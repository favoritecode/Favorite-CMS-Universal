<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Mail;

use Closure;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\Security\Crypto;
use FavoriteCMS\Services\System\InstallationIdentity;
use InvalidArgumentException;
use RuntimeException;

/**
 * Universal Google OAuth 2.0 Service for Gmail SMTP XOAUTH2.
 *
 * Implements:
 * - Universal 1-click "Continue with Google" via central Favorite CMS OAuth Gateway
 * - First-time installation enrollment during secure backchannel ticket exchange
 * - Subsequent server-to-server HMAC-SHA256 authenticated token refresh and revocation
 * - Backward compatibility with custom Google Cloud Platform credentials (Custom GCP mode)
 * - Dynamic domain-agnostic Redirect URI generation with strict open-redirect prevention
 * - Cryptographic state tokens (256-bit entropy, 15-minute TTL, single-use, session-bound)
 * - Authenticated AES-256-GCM encryption for stored refresh tokens and secrets at rest
 * - In-memory transient access token caching with automatic renewal
 * - Pluggable HTTP mock handler for protocol contract testing
 */
class GoogleOAuthService
{
    public const GATEWAY_START_ENDPOINT    = '/oauth/google/start';
    public const GATEWAY_EXCHANGE_ENDPOINT = '/api/v1/oauth/token/exchange';
    public const GATEWAY_REFRESH_ENDPOINT  = '/api/v1/oauth/token/refresh';
    public const GATEWAY_REVOKE_ENDPOINT   = '/api/v1/oauth/token/revoke';

    public const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
    public const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const REVOKE_ENDPOINT   = 'https://oauth2.googleapis.com/revoke';

    public const GMAIL_SCOPE = 'https://mail.google.com/ openid email profile';
    public const STATE_EXPIRY_SECONDS = 900; // 15 minutes

    /**
     * In-memory transient access token cache for current request lifecycle.
     */
    protected static ?string $transientAccessToken = null;
    protected static ?int $transientExpiresAt = null;

    /**
     * Pluggable HTTP handler for unit and integration testing.
     * Signature: fn(string $url, string $method, array $headers, array|string|null $body): array{code: int, body: string}
     */
    public static ?Closure $httpHandler = null;

    /**
     * Clear in-memory transient access tokens.
     */
    public static function clearTransientTokens(): void
    {
        static::$transientAccessToken = null;
        static::$transientExpiresAt = null;
    }

    /**
     * Validate OAuth Gateway URL security:
     * - Must be a valid absolute URL
     * - HTTPS required in production; HTTP permitted only for localhost / local dev
     * - Strictly rejects userinfo credentials
     * - Strictly rejects query parameters or fragments in the base URL
     */
    public static function validateGatewayUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsed = parse_url($trimmed);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        // Userinfo, query, or fragment is strictly prohibited in the base Gateway URL
        if (!empty($parsed['user']) || !empty($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            return false;
        }

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');

        if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
            return false;
        }

        return true;
    }

    /**
     * Normalize a Gateway URL by trimming whitespace and trailing slashes.
     */
    public static function normalizeGatewayUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * Retrieve the configured Google OAuth Gateway base URL.
     *
     * Resolution order:
     * 1. Explicit installation database setting (highest priority)
     * 2. Environment variable: FAVORITE_OAUTH_GATEWAY_URL
     * 3. Config file: services.google_oauth.gateway_url
     * 4. Null if not configured (NO fake or hardcoded default)
     */
    public static function getGatewayUrl(): ?string
    {
        // 1. Explicit database setting (configured by admin in Settings UI)
        $settingUrl = trim((string)Setting::get('email', 'google_gateway_url', ''));
        if ($settingUrl !== '') {
            $normalized = static::normalizeGatewayUrl($settingUrl);
            if (static::validateGatewayUrl($normalized)) {
                return $normalized;
            }
        }

        // 2. Environment variable (if defined)
        $envUrl = getenv('FAVORITE_OAUTH_GATEWAY_URL');
        if (!is_string($envUrl) || trim($envUrl) === '') {
            $envVal = $_ENV['FAVORITE_OAUTH_GATEWAY_URL'] ?? $_SERVER['FAVORITE_OAUTH_GATEWAY_URL'] ?? null;
            if (is_string($envVal)) {
                $envUrl = $envVal;
            }
        }
        if (is_string($envUrl) && trim($envUrl) !== '') {
            $normalized = static::normalizeGatewayUrl($envUrl);
            if (static::validateGatewayUrl($normalized)) {
                return $normalized;
            }
        }

        // 3. Config file (if defined and non-empty)
        if (function_exists('config')) {
            $configUrl = trim((string)config('services.google_oauth.gateway_url', ''));
            if ($configUrl !== '') {
                $normalized = static::normalizeGatewayUrl($configUrl);
                if (static::validateGatewayUrl($normalized)) {
                    return $normalized;
                }
            }
        }

        // 4. Not configured (no fake or hardcoded default)
        return null;
    }

    /**
     * Check whether a valid Google OAuth Gateway URL is configured.
     */
    public static function isGatewayConfigured(): bool
    {
        return static::getGatewayUrl() !== null;
    }

    /**
     * Determine whether the CMS is in Universal Gateway mode (default)
     * or Custom GCP mode (if the administrator has explicitly configured both custom credentials).
     */
    public static function isGatewayMode(): bool
    {
        $mode = (string)Setting::get('email', 'google_oauth_mode', '');
        if ($mode === 'custom') {
            return false;
        }
        if ($mode === 'gateway') {
            return true;
        }

        return !(static::hasClientId() && static::hasClientSecret());
    }

    /**
     * Validate callback URL security per RFC and anti-open-redirect rules:
     * - Must be absolute URL
     * - HTTPS required in production; HTTP permitted only for localhost / local dev
     * - Rejects userinfo credentials, fragments, and illegal schemes (javascript, file, data)
     * - Must end with canonical callback path
     */
    public static function validateCallbackUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        // Userinfo or fragment is strictly prohibited
        if (!empty($parsed['user']) || !empty($parsed['pass']) || isset($parsed['fragment'])) {
            return false;
        }

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');

        if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
            return false;
        }

        $path = $parsed['path'] ?? '';
        return str_ends_with($path, '/admin/settings/google-callback');
    }

    /**
     * Dynamically resolve the OAuth 2.0 Redirect URI using canonical CMS URL.
     * Guaranteed to work across localhost, subdirectories, subdomains, and custom ports.
     */
    public static function getRedirectUri(): string
    {
        $uri = '';
        if (function_exists('app_url')) {
            $uri = app_url('/admin/settings/google-callback');
        } else {
            $siteUrl = (string)Setting::get('general', 'site_url', 'http://localhost');
            $uri = rtrim($siteUrl, '/') . '/admin/settings/google-callback';
        }

        return $uri;
    }

    /**
     * Get the configured custom Google OAuth Client ID (for Custom GCP mode).
     */
    public static function getClientId(): string
    {
        return trim((string)Setting::get('email', 'google_client_id', ''));
    }

    /**
     * Check if custom Google Client ID is configured.
     */
    public static function hasClientId(): bool
    {
        return static::getClientId() !== '';
    }

    /**
     * Get decrypted custom Google OAuth Client Secret (for Custom GCP mode).
     */
    public static function getClientSecret(): string
    {
        $stored = (string)Setting::get('email', 'google_client_secret', '');
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, Crypto::VERSION . ':')) {
            return Crypto::decrypt($stored) ?? '';
        }

        return $stored;
    }

    /**
     * Check if custom Google Client Secret is configured.
     */
    public static function hasClientSecret(): bool
    {
        return static::getClientSecret() !== '';
    }

    /**
     * Store custom Google Client Secret securely using AES-256-GCM encryption.
     */
    public static function storeClientSecret(string $secret): void
    {
        $secret = trim($secret);
        if ($secret === '' || $secret === '********') {
            return;
        }

        if (Crypto::isAvailable()) {
            Setting::set('email', 'google_client_secret', Crypto::encrypt($secret));
        } else {
            Setting::set('email', 'google_client_secret', $secret);
        }
        Setting::clearCache();
    }

    /**
     * Check whether Google Mail is currently connected and has a valid refresh token.
     */
    public static function isConnected(): bool
    {
        $connected = (int)Setting::get('email', 'google_connected', 0) === 1;
        if (!$connected) {
            return false;
        }

        $refreshToken = static::getStoredRefreshToken();
        return $refreshToken !== '';
    }

    /**
     * Get the authenticated Google account email (e.g. user@gmail.com).
     */
    public static function getAccountEmail(): string
    {
        return trim((string)Setting::get('email', 'google_account_email', ''));
    }

    /**
     * Generate a cryptographically secure state token (256-bit entropy) and store in session with timestamp.
     */
    public static function generateState(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_state_time'] = time();

        return $state;
    }

    /**
     * Validate an incoming OAuth callback state parameter against stored session state.
     * Enforces strict constant-time comparison and 15-minute expiry.
     * Replay-protected: single-use, consumed immediately.
     */
    public static function validateState(?string $state): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        if (empty($state) || !is_string($state)) {
            return false;
        }

        $storedState = $_SESSION['google_oauth_state'] ?? null;
        $stateTime = $_SESSION['google_oauth_state_time'] ?? null;

        // Invalidate single-use state immediately to prevent replay
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_state_time']);

        if (!is_string($storedState) || !is_int($stateTime)) {
            return false;
        }

        if ((time() - $stateTime) > self::STATE_EXPIRY_SECONDS) {
            return false;
        }

        return hash_equals($storedState, $state);
    }

    /**
     * Build the authorization URL for user consent.
     *
     * In Universal Gateway Mode:
     * Builds Gateway Start URL without pre-shared HMAC.
     * Transmits: installation_id, callback_url, state, site_url.
     * NEVER sends: installation_secret, Google Client Secret, or tokens.
     *
     * In Custom GCP Mode:
     * Builds direct Google consent URL with administrator's custom Client ID.
     */
    public static function getAuthorizationUrl(string $state, bool $forcePrompt = false): string
    {
        $callbackUrl = static::getRedirectUri();
        if (!static::validateCallbackUrl($callbackUrl)) {
            throw new RuntimeException("Invalid or insecure callback URL: {$callbackUrl}");
        }

        if (static::isGatewayMode()) {
            $gatewayUrl = static::getGatewayUrl();
            if ($gatewayUrl === null) {
                throw new RuntimeException('Google OAuth Gateway is not configured. Please configure a Gateway URL or enter Custom Google Cloud credentials.');
            }
            $installationId = InstallationIdentity::getId();

            $siteUrl = (string)Setting::get('general', 'site_url', '');
            if ($siteUrl === '' && function_exists('app_url')) {
                $siteUrl = app_url('/');
            }

            $params = [
                'installation_id' => $installationId,
                'callback_url'    => $callbackUrl,
                'state'           => $state,
                'site_url'        => $siteUrl,
            ];

            if ($forcePrompt) {
                $params['prompt'] = 'consent';
            }

            return $gatewayUrl . self::GATEWAY_START_ENDPOINT . '?' . http_build_query($params);
        }

        // Custom GCP mode fallback
        $clientId = static::getClientId();
        if ($clientId === '') {
            throw new RuntimeException('Google Client ID is not configured. Please save Client ID in settings first.');
        }

        $params = [
            'client_id'              => $clientId,
            'redirect_uri'           => $callbackUrl,
            'response_type'          => 'code',
            'scope'                  => self::GMAIL_SCOPE,
            'access_type'            => 'offline',
            'state'                  => $state,
            'include_granted_scopes' => 'true',
        ];

        if ($forcePrompt || static::getStoredRefreshToken() === '') {
            $params['prompt'] = 'consent';
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * Alias for getAuthorizationUrl.
     */
    public static function buildAuthUrl(string $state, bool $forcePrompt = false): string
    {
        return static::getAuthorizationUrl($state, $forcePrompt);
    }

    /**
     * Exchange single-use Gateway ticket and complete first-time installation enrollment.
     *
     * Performed strictly over direct server-to-server HTTPS. The browser never sees the installation secret.
     *
     * @param string $ticket 256-bit cryptographically random Gateway ticket
     * @param string $state Validated local OAuth state
     * @return array{success: bool, access_token: string, refresh_token: string, expires_in: int, email: string, account_email: string}
     */
    public static function exchangeTicket(string $ticket, string $state): array
    {
        $ticket = trim($ticket);
        if ($ticket === '' || !preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $ticket)) {
            throw new InvalidArgumentException('Invalid or malformed OAuth ticket received from Gateway.');
        }

        $installationId = InstallationIdentity::getId();
        $installationSecret = InstallationIdentity::getSecret();

        $gatewayUrl = static::getGatewayUrl();
        if ($gatewayUrl === null) {
            throw new RuntimeException('Google OAuth Gateway is not configured.');
        }
        $exchangeUrl = $gatewayUrl . self::GATEWAY_EXCHANGE_ENDPOINT;

        $bodyData = [
            'installation_id'     => $installationId,
            'ticket'              => $ticket,
            'state'               => $state,
            'installation_secret' => $installationSecret,
        ];
        $jsonPayload = json_encode($bodyData, JSON_UNESCAPED_SLASHES);

        $response = static::makeHttpRequest($exchangeUrl, 'POST', [
            'Content-Type: application/json',
            'Accept: application/json',
        ], $jsonPayload);

        if ($response['code'] !== 200) {
            InstallationIdentity::setEnrolled(false);
            $errData = json_decode($response['body'], true);
            $errMsg = $errData['error_description'] ?? $errData['error'] ?? "HTTP {$response['code']}";
            throw new RuntimeException("OAuth Gateway ticket exchange failed: {$errMsg}");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['refresh_token'])) {
            InstallationIdentity::setEnrolled(false);
            throw new RuntimeException('OAuth Gateway did not return valid credentials.');
        }

        $accessToken  = (string)$data['access_token'];
        $refreshToken = (string)$data['refresh_token'];
        $expiresIn    = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $accountEmail = trim((string)($data['account_email'] ?? $data['email'] ?? ''));
        $googleSub    = isset($data['google_sub']) ? (string)$data['google_sub'] : null;

        if ($accountEmail === '') {
            $accountEmail = static::resolveEmailFromTokens($accessToken, null);
        }

        if ($accountEmail === '') {
            InstallationIdentity::setEnrolled(false);
            throw new RuntimeException('Could not determine Google account email address from authentication.');
        }

        // Enrollment confirmed: Gateway has registered installation_secret
        InstallationIdentity::setEnrolled(true);

        // Persist authentication at rest
        static::persistAuthentication($accountEmail, $accessToken, $refreshToken, $expiresIn, $googleSub);

        return [
            'success'       => true,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $expiresIn,
            'email'         => $accountEmail,
            'account_email' => $accountEmail,
        ];
    }

    /**
     * Direct code exchange with Google Token Endpoint (Custom GCP mode backward compatibility).
     *
     * @param string $code
     * @return array{success: bool, access_token: string, refresh_token: string|null, expires_in: int, email: string, account_email: string}
     */
    public static function exchangeCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('Authorization code cannot be empty.');
        }

        $clientId = static::getClientId();
        $clientSecret = static::getClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Google Client ID or Client Secret is missing.');
        }

        $payload = [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => static::getRedirectUri(),
            'grant_type'    => 'authorization_code',
        ];

        $response = static::makeHttpRequest(self::TOKEN_ENDPOINT, 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query($payload));

        if ($response['code'] !== 200) {
            $errData = json_decode($response['body'], true);
            $errMsg = $errData['error_description'] ?? $errData['error'] ?? "HTTP {$response['code']}";
            throw new RuntimeException("Google token exchange failed: {$errMsg}");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Google did not return a valid access token.');
        }

        $accessToken  = (string)$data['access_token'];
        $refreshToken = isset($data['refresh_token']) ? (string)$data['refresh_token'] : null;
        $expiresIn    = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $idToken      = isset($data['id_token']) ? (string)$data['id_token'] : null;

        $accountEmail = static::resolveEmailFromTokens($accessToken, $idToken);
        if ($accountEmail === '') {
            throw new RuntimeException('Could not determine Google account email address from authentication.');
        }

        static::persistAuthentication($accountEmail, $accessToken, $refreshToken, $expiresIn);

        return [
            'success'       => true,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => $expiresIn,
            'email'         => $accountEmail,
            'account_email' => $accountEmail,
        ];
    }

    /**
     * Retrieve a valid access token for XOAUTH2 dispatch, refreshing automatically if expired.
     */
    public static function getValidAccessToken(): ?string
    {
        $now = time();
        if (static::$transientAccessToken !== null && static::$transientExpiresAt !== null) {
            if (static::$transientExpiresAt > ($now + 60)) { // 60-second safety buffer
                return static::$transientAccessToken;
            }
        }

        return static::refreshAccessToken();
    }

    /**
     * Refresh OAuth access token.
     *
     * In Universal Gateway Mode:
     * Constructs HMAC-SHA256 signed request to Gateway `/api/v1/oauth/token/refresh`.
     * Gateway verifies signature with enrolled installation secret and calls Google Token Endpoint.
     *
     * In Custom GCP Mode:
     * Directly calls Google Token Endpoint using local credentials.
     */
    public static function refreshAccessToken(): ?string
    {
        $refreshToken = static::getStoredRefreshToken();
        if ($refreshToken === '') {
            static::markDisconnected('No stored refresh token available. Reconnection required.');
            return null;
        }

        if (static::isGatewayMode()) {
            $installationId = InstallationIdentity::getId();
            $timestamp = time();
            $nonce = bin2hex(random_bytes(16));

            $bodyData = [
                'installation_id' => $installationId,
                'refresh_token'   => $refreshToken,
                'timestamp'       => $timestamp,
                'nonce'           => $nonce,
            ];
            $jsonBody = json_encode($bodyData, JSON_UNESCAPED_SLASHES);

            $canonical = InstallationIdentity::buildCanonicalPayload(
                'POST',
                self::GATEWAY_REFRESH_ENDPOINT,
                $timestamp,
                $nonce,
                $installationId,
                $jsonBody
            );
            $signature = InstallationIdentity::sign($canonical);

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Installation-Id: ' . $installationId,
                'X-Timestamp: ' . (string)$timestamp,
                'X-Nonce: ' . $nonce,
                'X-Signature: ' . $signature,
            ];

            $gatewayUrl = static::getGatewayUrl();
            if ($gatewayUrl === null) {
                return null;
            }
            $refreshUrl = $gatewayUrl . self::GATEWAY_REFRESH_ENDPOINT;

            $response = static::makeHttpRequest($refreshUrl, 'POST', $headers, $jsonBody);

            if ($response['code'] !== 200) {
                $errData = json_decode($response['body'], true);
                $errType = $errData['error'] ?? '';
                if (in_array($errType, ['invalid_grant', 'unauthorized_client', 'invalid_signature'], true)) {
                    static::markDisconnected('Google connection expired or was revoked. Please reconnect Google in Admin Settings.');
                }
                return null;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data) || empty($data['access_token'])) {
                return null;
            }

            $newAccessToken = (string)$data['access_token'];
            $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;

            static::$transientAccessToken = $newAccessToken;
            static::$transientExpiresAt = time() + $expiresIn;

            // If Gateway / Google rotated the refresh token, store updated ciphertext
            if (!empty($data['refresh_token'])) {
                static::storeRefreshToken((string)$data['refresh_token']);
            }

            return $newAccessToken;
        }

        // Custom GCP mode direct refresh
        $clientId = static::getClientId();
        $clientSecret = static::getClientSecret();
        if ($clientId === '' || $clientSecret === '') {
            static::markDisconnected('Google Client ID or Client Secret is not configured.');
            return null;
        }

        $payload = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ];

        $response = static::makeHttpRequest(self::TOKEN_ENDPOINT, 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query($payload));

        if ($response['code'] !== 200) {
            $errData = json_decode($response['body'], true);
            $errType = $errData['error'] ?? '';
            if (in_array($errType, ['invalid_grant', 'unauthorized_client'], true)) {
                static::markDisconnected('Google connection expired or was revoked. Please reconnect Google in Admin Settings.');
            }
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $newAccessToken = (string)$data['access_token'];
        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;

        static::$transientAccessToken = $newAccessToken;
        static::$transientExpiresAt = time() + $expiresIn;

        if (!empty($data['refresh_token'])) {
            static::storeRefreshToken((string)$data['refresh_token']);
        }

        return $newAccessToken;
    }

    /**
     * Persist authentication results in settings database.
     */
    public static function persistAuthentication(
        string $accountEmail,
        string $accessToken,
        ?string $refreshToken,
        int $expiresIn,
        ?string $googleSub = null
    ): void {
        Setting::set('email', 'google_account_email', $accountEmail);
        Setting::set('email', 'google_connected', 1, 'int');
        Setting::set('email', 'google_token_expires_at', time() + $expiresIn, 'int');

        if ($googleSub !== null && $googleSub !== '') {
            Setting::set('email', 'google_sub', $googleSub);
        }

        if ($refreshToken !== null && $refreshToken !== '') {
            static::storeRefreshToken($refreshToken);
        }

        static::$transientAccessToken = $accessToken;
        static::$transientExpiresAt = time() + $expiresIn;
        Setting::clearCache();
    }

    /**
     * Check whether the cached or stored access token has expired.
     */
    public static function isTokenExpired(): bool
    {
        if (static::$transientExpiresAt !== null) {
            return time() >= (static::$transientExpiresAt - 60);
        }

        $expiresAt = (int)Setting::get('email', 'google_token_expires_at', 0);
        if ($expiresAt === 0) {
            return true;
        }

        return time() >= ($expiresAt - 60);
    }

    /**
     * Disconnect Google account and purge stored tokens.
     */
    public static function disconnect(): void
    {
        $refreshToken = static::getStoredRefreshToken();
        if ($refreshToken !== '') {
            try {
                if (static::isGatewayMode()) {
                    $installationId = InstallationIdentity::getId();
                    $timestamp = time();
                    $nonce = bin2hex(random_bytes(16));

                    $bodyData = [
                        'installation_id' => $installationId,
                        'refresh_token'   => $refreshToken,
                        'timestamp'       => $timestamp,
                        'nonce'           => $nonce,
                    ];
                    $jsonBody = json_encode($bodyData, JSON_UNESCAPED_SLASHES);

                    $canonical = InstallationIdentity::buildCanonicalPayload(
                        'POST',
                        self::GATEWAY_REVOKE_ENDPOINT,
                        $timestamp,
                        $nonce,
                        $installationId,
                        $jsonBody
                    );
                    $signature = InstallationIdentity::sign($canonical);

                    $headers = [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'X-Installation-Id: ' . $installationId,
                        'X-Timestamp: ' . (string)$timestamp,
                        'X-Nonce: ' . $nonce,
                        'X-Signature: ' . $signature,
                    ];

                    $gatewayUrl = static::getGatewayUrl();
                    if ($gatewayUrl !== null) {
                        static::makeHttpRequest($gatewayUrl . self::GATEWAY_REVOKE_ENDPOINT, 'POST', $headers, $jsonBody);
                    }
                } else {
                    static::makeHttpRequest(self::REVOKE_ENDPOINT, 'POST', [
                        'Content-Type: application/x-www-form-urlencoded',
                    ], http_build_query(['token' => $refreshToken]));
                }
            } catch (\Throwable) {
                // Ignore network errors during remote revocation
            }
        }

        Setting::set('email', 'google_connected', 0, 'int');
        Setting::set('email', 'google_refresh_token', '');
        Setting::set('email', 'google_account_email', '');
        Setting::set('email', 'google_sub', '');
        Setting::set('email', 'google_token_expires_at', 0, 'int');
        static::$transientAccessToken = null;
        static::$transientExpiresAt = null;
        Setting::clearCache();
    }

    /**
     * Store refresh token securely with AES-256-GCM authenticated encryption.
     */
    public static function storeRefreshToken(string $refreshToken): void
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            Setting::set('email', 'google_refresh_token', '');
            Setting::clearCache();
            return;
        }

        if (Crypto::isAvailable()) {
            Setting::set('email', 'google_refresh_token', Crypto::encrypt($refreshToken));
        } else {
            Setting::set('email', 'google_refresh_token', $refreshToken);
        }
        Setting::clearCache();
    }

    /**
     * Retrieve and decrypt stored refresh token.
     */
    public static function getStoredRefreshToken(): string
    {
        $stored = (string)Setting::get('email', 'google_refresh_token', '');
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, Crypto::VERSION . ':')) {
            return Crypto::decrypt($stored) ?? '';
        }

        return $stored;
    }

    /**
     * Mark Google connection as revoked or disconnected with clean diagnostics.
     */
    protected static function markDisconnected(string $reason): void
    {
        Setting::set('email', 'google_connected', 0, 'int');
        static::$transientAccessToken = null;
        static::$transientExpiresAt = null;
        Setting::clearCache();
    }

    /**
     * Resolve user email from ID token or userinfo endpoint.
     */
    protected static function resolveEmailFromTokens(string $accessToken, ?string $idToken): string
    {
        if ($idToken !== null && $idToken !== '') {
            $parts = explode('.', $idToken);
            if (isset($parts[1])) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (is_array($payload) && !empty($payload['email'])) {
                    return (string)$payload['email'];
                }
            }
        }

        try {
            $resp = static::makeHttpRequest(self::USERINFO_ENDPOINT, 'GET', [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ]);
            if ($resp['code'] === 200) {
                $data = json_decode($resp['body'], true);
                if (is_array($data) && !empty($data['email'])) {
                    return (string)$data['email'];
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * Execute HTTP request (or route through static::$httpHandler for tests).
     *
     * @param string $url
     * @param string $method
     * @param array<string> $headers
     * @param string|null $body
     * @return array{code: int, body: string}
     */
    protected static function makeHttpRequest(string $url, string $method, array $headers, ?string $body = null): array
    {
        if (static::$httpHandler !== null) {
            return (static::$httpHandler)($url, $method, $headers, $body);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $responseBody = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                throw new RuntimeException("HTTP request failed: {$error}");
            }

            return ['code' => $statusCode, 'body' => (string)$responseBody];
        }

        // Stream context fallback
        $opts = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        $ctx = stream_context_create($opts);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            throw new RuntimeException('Failed to communicate with remote endpoint.');
        }

        $statusLine = $http_response_header[0] ?? '';
        preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m);
        $code = isset($m[1]) ? (int)$m[1] : 200;

        return ['code' => $code, 'body' => $res];
    }
}
