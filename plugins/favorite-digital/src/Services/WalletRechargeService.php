<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WalletRechargeService
{
    public const REGULAR_MIN_AMOUNT = '50.00';
    public const MAX_AMOUNT = '10000.00';
    public const DEFAULT_CURRENCY = 'BDT';

    protected WalletRepository $walletRepo;
    protected WalletService $walletService;
    protected ?PaymentServiceInterface $paymentService;
    protected ?CurrencyServiceInterface $currencyService;
    protected ?Database $db;

    public function __construct(
        WalletRepository $walletRepo,
        WalletService $walletService,
        ?PaymentServiceInterface $paymentService = null,
        ?CurrencyServiceInterface $currencyService = null,
        ?Database $db = null
    ) {
        $this->walletRepo = $walletRepo;
        $this->walletService = $walletService;
        $this->paymentService = $paymentService;
        $this->currencyService = $currencyService;
        $this->db = $db ?? $walletRepo->getDatabase();
    }

    public function getWalletRepository(): WalletRepository
    {
        return $this->walletRepo;
    }

    public function getWalletService(): WalletService
    {
        return $this->walletService;
    }

    public function getPaymentService(): ?PaymentServiceInterface
    {
        return $this->paymentService;
    }

    public function setPaymentService(?PaymentServiceInterface $service): void
    {
        $this->paymentService = $service;
    }

    public function getCurrencyService(): ?CurrencyServiceInterface
    {
        return $this->currencyService;
    }

    public function setCurrencyService(?CurrencyServiceInterface $service): void
    {
        $this->currencyService = $service;
    }

    public function getPrimaryCurrency(): string
    {
        if ($this->currencyService !== null && method_exists($this->currencyService, 'getBaseCurrency')) {
            return $this->currencyService->getBaseCurrency();
        }
        if (class_exists(\FavoriteCMS\Core\Currency::class)) {
            return \FavoriteCMS\Core\Currency::getPrimaryCurrency();
        }
        return self::DEFAULT_CURRENCY;
    }

    /**
     * Compute dynamic minimum and maximum recharge limits.
     * For Binance Pay: Minimum is the equivalent of 1 USD in Primary Currency, strictly derived from authoritative FX.
     * Fails closed if authoritative FX is unavailable or expired.
     */
    public function getRechargeLimits(string $gatewayId = ''): array
    {
        $primaryCurrency = $this->getPrimaryCurrency();
        $isBinance = str_contains(strtolower($gatewayId), 'binance');

        $minAmount = self::REGULAR_MIN_AMOUNT;
        $maxAmount = self::MAX_AMOUNT;
        $rateInfo = null;

        if ($isBinance) {
            if ($this->currencyService === null) {
                throw new UnauthoritativeRateException(
                    "Cannot compute Binance recharge limit: Currency service is unavailable.",
                    'USD',
                    $primaryCurrency
                );
            }

            // Must query authoritative exchange rate for USD -> primaryCurrency
            $snapshot = $this->currencyService->getRate('USD', $primaryCurrency);
            if (!$snapshot->isValidForPayment()) {
                throw new UnauthoritativeRateException(
                    "Cannot compute Binance recharge limit: FX rate is not valid for payment.",
                    'USD',
                    $primaryCurrency
                );
            }

            $oneUsd = Money::fromMajorString('1.00', 'USD');
            $equivPrimary = $snapshot->convert($oneUsd);

            // Convert minor to major string
            $equivMajor = number_format($equivPrimary->getAmount() / 100, 2, '.', '');
            $minAmount = $equivMajor;

            $rateInfo = [
                'rate_decimal' => $snapshot->getRateDecimalString(),
                'from'         => 'USD',
                'to'           => $primaryCurrency,
                'source'       => $snapshot->getSource(),
                'locked_at'    => $snapshot->getLockedAt(),
            ];
        }

        return [
            'min'              => $minAmount,
            'max'              => $maxAmount,
            'currency'         => $primaryCurrency,
            'is_binance'       => $isBinance,
            'rate_information' => $rateInfo,
        ];
    }

    /**
     * Validate client-submitted recharge amount string.
     * Enforces strict decimal format, minor unit conversion, and server-side limits.
     *
     * @return int Amount in minor units (e.g. 50000 for 500.00 BDT)
     */
    public function validateRechargeAmount(string $amount, string $gatewayId = ''): int
    {
        $raw = trim($amount);

        // Reject empty or non-numeric strings
        if ($raw === '' || !is_numeric($raw)) {
            throw new InvalidArgumentException("Recharge amount must be a valid numeric value.");
        }

        // Reject negative signs or scientific notation
        if (str_starts_with($raw, '-') || stripos($raw, 'e') !== false) {
            throw new InvalidArgumentException("Recharge amount cannot be negative or formatted in scientific notation.");
        }

        // Reject excessive precision (> 2 decimal places for BDT)
        if (preg_match('/\.\d{3,}$/', $raw)) {
            throw new InvalidArgumentException("Recharge amount has excessive precision. Maximum 2 decimal places allowed.");
        }

        // Parse through Money to avoid any float inaccuracy
        try {
            $money = Money::fromMajorString($raw, $this->getPrimaryCurrency());
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Invalid recharge amount format: " . $e->getMessage());
        }

        $minorAmount = $money->getAmount();
        if ($minorAmount <= 0) {
            throw new InvalidArgumentException("Recharge amount must be strictly greater than zero.");
        }

        // Validate against server-enforced limits
        $limits = $this->getRechargeLimits($gatewayId);
        $minMinor = Money::fromMajorString($limits['min'], $limits['currency'])->getAmount();
        $maxMinor = Money::fromMajorString($limits['max'], $limits['currency'])->getAmount();

        if ($minorAmount < $minMinor) {
            throw new InvalidArgumentException("Recharge amount cannot be less than {$limits['min']} {$limits['currency']}.");
        }

        if ($minorAmount > $maxMinor) {
            throw new InvalidArgumentException("Recharge amount cannot exceed {$limits['max']} {$limits['currency']}.");
        }

        return $minorAmount;
    }

    /**
     * Calculate recharge preview figures (wallet credit vs acquiring payment amount).
     */
    public function getRechargeCalculation(string $amountBdt, string $gatewayId): array
    {
        $minor = $this->validateRechargeAmount($amountBdt, $gatewayId);
        $primaryCurrency = $this->getPrimaryCurrency();
        $baseMoney = Money::fromMinor($minor, $primaryCurrency);

        $isBinance = str_contains(strtolower($gatewayId), 'binance');
        $chargeCurrency = $primaryCurrency;
        $chargeAmount = $baseMoney;
        $snapshot = null;

        if ($isBinance) {
            $chargeCurrency = 'USDT';
            if ($this->currencyService === null) {
                throw new UnauthoritativeRateException("Currency service is unavailable for foreign payment calculation.", $primaryCurrency, 'USDT');
            }
            $snapshot = $this->currencyService->getRate($primaryCurrency, 'USDT');
            if (!$snapshot->isValidForPayment()) {
                throw new UnauthoritativeRateException("Exchange rate is not valid for payment.", $primaryCurrency, 'USDT');
            }
            $chargeAmount = $snapshot->convert($baseMoney);
        }

        return [
            'wallet_amount'    => number_format($minor / 100, 2, '.', ''),
            'wallet_currency'  => $primaryCurrency,
            'gateway_id'       => $gatewayId,
            'charge_amount'    => number_format($chargeAmount->getAmount() / 100, 2, '.', ''),
            'charge_currency'  => $chargeCurrency,
            'rate_snapshot'    => $snapshot ? $snapshot->toArray() : null,
            'is_foreign'       => $chargeCurrency !== $primaryCurrency,
        ];
    }

    /**
     * Initiate a wallet recharge intent through Favorite Pay public API.
     */
    public function createRecharge(int $userId, string $amountBdt, string $gatewayId, array $params = []): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException("Valid authenticated user is required for wallet recharge.");
        }

        // Wallet suspension check
        $wallet = $this->walletRepo->getOrCreateWallet($userId);
        if ($wallet->status !== 'active') {
            throw WalletException::walletInactive($userId, (string)$wallet->status);
        }

        $minorAmount = $this->validateRechargeAmount($amountBdt, $gatewayId);
        $primaryCurrency = $this->getPrimaryCurrency();
        $baseMoney = Money::fromMinor($minorAmount, $primaryCurrency);
        $cleanAmount = number_format($minorAmount / 100, 2, '.', '');

        if ($this->paymentService === null) {
            throw new RuntimeException("Favorite Pay payment service is currently unavailable.");
        }

        $sourceRef = 'wrc_' . bin2hex(random_bytes(8));

        // Create Favorite Pay PaymentIntent
        $intent = $this->paymentService->createIntent(
            'favorite-digital',
            $sourceRef,
            $baseMoney,
            [
                'customer_id' => $userId,
                'gateway_id'  => $gatewayId,
                'metadata'    => [
                    'type'          => 'wallet_recharge',
                    'user_id'       => $userId,
                    'wallet_amount' => $cleanAmount,
                    'currency'      => $primaryCurrency,
                    'gateway_id'    => $gatewayId,
                    'recharge_ref'  => $sourceRef,
                ],
            ]
        );

        // Check if gateway is manual
        $isManual = false;
        $instructions = [];
        $available = $this->paymentService->getAvailablePaymentMethods($primaryCurrency);
        foreach ($available as $method) {
            if ($method['id'] === $gatewayId) {
                $isManual = !empty($method['is_manual']);
                $instructions = $method['instructions'] ?? [];
                break;
            }
        }

        if ($isManual) {
            return [
                'intent'       => $intent,
                'is_manual'    => true,
                'instructions' => $instructions,
                'recharge_ref' => $sourceRef,
                'amount'       => $cleanAmount,
            ];
        }

        // Initiate online attempt
        $attempt = $this->paymentService->initiatePayment($intent->getId(), $gatewayId, $params);

        return [
            'intent'       => $intent,
            'attempt'      => $attempt,
            'is_manual'    => false,
            'recharge_ref' => $sourceRef,
            'amount'       => $cleanAmount,
        ];
    }

    /**
     * Customer submits manual transaction reference (TrxID) for pending recharge.
     */
    public function submitManualRecharge(
        int $userId,
        string $intentId,
        string $gatewayId,
        string $transactionReference,
        array $details = []
    ): PaymentAttempt {
        if ($this->paymentService === null) {
            throw new RuntimeException("Favorite Pay payment service is unavailable.");
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Recharge payment intent '{$intentId}' not found.");
        }

        if ((int)$intent->getCustomerId() !== $userId) {
            throw new InvalidArgumentException("Unauthorized access to recharge payment intent.");
        }

        if ($intent->getStatus() === PaymentStatus::SUCCEEDED) {
            throw new RuntimeException("Cannot submit reference: recharge is already completed.");
        }

        if ($intent->getStatus() === PaymentStatus::FAILED) {
            throw new RuntimeException("Cannot submit reference: recharge has failed.");
        }

        return $this->paymentService->submitManualVerification(
            $intentId,
            $gatewayId,
            $transactionReference,
            $details
        );
    }

    /**
     * Authoritatively settle a verified successful wallet recharge.
     * Credits the customer's wallet ONLY when intent is server-side verified as SUCCEEDED.
     */
    public function settleRecharge(string $intentId): ?object
    {
        if ($this->paymentService === null) {
            throw new RuntimeException("Payment service is unavailable for recharge settlement.");
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            return null;
        }

        if ($intent->getSourcePlugin() !== 'favorite-digital') {
            return null;
        }

        $ref = $intent->getSourceReference();
        $meta = $intent->getMetadata();
        $isRecharge = str_starts_with($ref, 'wrc_') || ($meta['type'] ?? '') === 'wallet_recharge';

        if (!$isRecharge) {
            return null;
        }

        // ONLY verified SUCCEEDED status can credit wallet
        if ($intent->getStatus() !== PaymentStatus::SUCCEEDED) {
            return null;
        }

        $userId = (int)$intent->getCustomerId();
        if ($userId <= 0) {
            return null;
        }

        // Amount calculation in primary currency
        $baseMoney = $intent->getBaseAmount();
        $creditAmount = number_format($baseMoney->getAmount() / 100, 2, '.', '');

        $gwId = $intent->getGatewayId() ?? ($meta['gateway_id'] ?? 'payment');
        $desc = "Wallet recharge via {$gwId} [Ref: {$intentId}]";

        // Idempotent credit via WalletService sole authority
        return $this->walletService->credit(
            $userId,
            $creditAmount,
            $intentId,
            $desc,
            null,
            'recharge'
        );
    }

    /**
     * Admin action to approve a manual payment attempt and settle recharge credit.
     */
    public function approveManualRecharge(string $attemptId, int $adminUserId, ?string $notes = null): object
    {
        if ($this->paymentService === null) {
            throw new RuntimeException("Payment service unavailable.");
        }

        $attempt = $this->paymentService->approveManualPayment($attemptId, $adminUserId, $notes);
        $this->settleRecharge($attempt->getIntentId());

        return $attempt;
    }

    /**
     * Safe retry for failed or expired recharges.
     * Generates a new payment intent with fresh FX snapshot instead of mutating past intent.
     */
    public function retryRecharge(int $userId, string $intentId, string $gatewayId): array
    {
        if ($this->paymentService === null) {
            throw new RuntimeException("Payment service unavailable.");
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Recharge intent '{$intentId}' not found.");
        }

        if ((int)$intent->getCustomerId() !== $userId) {
            throw new InvalidArgumentException("Unauthorized access to recharge intent.");
        }

        if ($intent->getStatus() !== PaymentStatus::FAILED && $intent->getStatus() !== PaymentStatus::EXPIRED) {
            throw new RuntimeException("Only failed or expired recharges can be retried.");
        }

        $amount = number_format($intent->getBaseAmount()->getAmount() / 100, 2, '.', '');
        return $this->createRecharge($userId, $amount, $gatewayId);
    }

    /**
     * Retrieve paginated recharge history for customer.
     */
    public function getRechargeHistory(int $userId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $items = [];
        $total = 0;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $countRow = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_transactions 
                 WHERE user_id = ? AND source_plugin = 'favorite-digital' AND (source_reference LIKE 'wrc_%' OR metadata LIKE '%wallet_recharge%')",
                [$userId]
            );
            $total = (int)($countRow->cnt ?? 0);

            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_transactions 
                 WHERE user_id = ? AND source_plugin = 'favorite-digital' AND (source_reference LIKE 'wrc_%' OR metadata LIKE '%wallet_recharge%')
                 ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
                [$userId]
            );

            foreach ($rows as $row) {
                $statusVal = (string)$row->status;
                $statusLabel = match ($statusVal) {
                    'succeeded' => 'Completed',
                    'awaiting_verification' => 'Awaiting Verification',
                    'pending' => 'Pending',
                    'failed' => 'Failed',
                    'expired' => 'Expired',
                    default => ucfirst($statusVal),
                };

                $baseAmount = number_format((int)$row->base_amount / 100, 2, '.', '');
                $chargeAmount = number_format((int)$row->charge_amount / 100, 2, '.', '');

                $items[] = (object)[
                    'transaction_id'  => (string)$row->transaction_id,
                    'source_reference'=> (string)$row->source_reference,
                    'wallet_amount'   => $baseAmount,
                    'wallet_currency' => (string)$row->base_currency,
                    'charge_amount'   => $chargeAmount,
                    'charge_currency' => (string)$row->charge_currency,
                    'gateway_id'      => (string)($row->gateway_id ?? 'N/A'),
                    'status'          => $statusVal,
                    'status_label'    => $statusLabel,
                    'created_at'      => (string)$row->created_at,
                    'completed_at'    => $row->completed_at ? (string)$row->completed_at : null,
                ];
            }
        }

        return [
            'data'        => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
        ];
    }
}

