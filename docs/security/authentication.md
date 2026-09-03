# Authentication & Session Security

This document details the authentication protocols, credential verification mechanisms, and session lifecycles in **Favorite CMS Universal**.

---

## 1. Password Storage & Hashing

- **Hashing Algorithm**: Passwords are encrypted using PHP's native `password_hash()` with `PASSWORD_DEFAULT` (bcrypt with automatic cost factor or Argon2 depending on host PHP build).
- **No Plaintext**: Passwords are never stored or logged in plaintext.
- **Verification**: Evaluated using constant-time `password_verify()` to prevent timing attacks.
- **Password Strength Rules**: Registration and user creation enforce minimum 8-character passwords.

---

## 2. Session Management & Cookies

- **Session Prefixing**: Sessions are named uniquely based on the application path (`fcms_{hash}`) to avoid session namespace collisions on shared servers.
- **Storage Location**: Session files are stored within a protected application directory (`storage/sessions/`) rather than the global server `/tmp` directory, preventing other shared-hosting users on the same server from inspecting session tokens.
- **Cookie Security Directives**:
  - `httponly = true`: Session cookies cannot be accessed or stolen via JavaScript (`document.cookie`), neutralizing session-stealing XSS attacks.
  - `samesite = "Lax"`: Provides defense against cross-site request forgery by preventing cookies from being transmitted on third-party cross-origin requests.
  - `secure = true`: When HTTPS is active, cookies are marked secure so they are never transmitted across unencrypted HTTP channels.

---

## 3. Account Status Invalidation

- **Instant Session Invalidation on Ban**:
  - If an active user is banned by an administrator, the `Kernel` authentication middleware checks `$currentUser->isBanned()` on every single incoming `/admin/*` request.
  - The moment a banned state is detected, the session is cleared immediately:
    ```php
    unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $_SESSION['auth_user_email']);
    $_SESSION['flash_error'] = 'Your account has been permanently banned.';
    return Response::redirect('/admin/login');
    ```
  - The user is redirected to the login screen with a permanent ban alert.

