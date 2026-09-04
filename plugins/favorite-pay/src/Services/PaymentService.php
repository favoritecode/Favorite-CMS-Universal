<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use InvalidArgumentException;
use RuntimeException;

/**
 * Payment Service
 *
 * Implements:
 * 1. Gateway driver delegation via GatewayRegistry.
 * 2. 100% Operator Manual Bangladesh Payment Approval lifecycle.
 * 3. TrxID duplicate protection per gateway.
 * 4. Idempotency protection for repeated requests.
 * 5. Optional database persistence to Favorite Pay Phase 2 schema.
 */
class PaymentService implements PaymentServiceInterface
{
    private CurrencyServiceInterface $currencyService;
    private GatewayRegistry $gatewayRegistry;
    private ?Database $db;

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

    /** @var array<string, string> composite key (gateway:trx_id) => attempt_id */
    private array $providerReferences = [];

    /** @var array<string, PaymentAttempt> idempotency_key => PaymentAttempt */
    private array $idempotentAttempts = [];

    public function __construct(
        CurrencyServiceInterface $currencyService,
        GatewayRegistry $gatewayRegistry,
        ?Database $db = null
    ) {
        $this->currencyService = $currencyService;
        $this->gatewayRegistry = $gatewayRegistry;
        $this->db = $db;
    }

    public function createIntent(
        string $sourcePlugin,
        string $sourceReference,
        Money $baseAmount,
        array $options = []
    ): PaymentIntent {
        if (!$baseAmount->isPositive()) {
            throw new InvalidArgumentException("Payment base amount must be strictly positive.");
        }

        $id = 'pi_' . bin2hex(random_bytes(10));
        $chargeCurrency = null;
        if (isset($options['charge_currency']) && trim((string)$options['charge_currency']) !== '') {
            $chargeCurrency = strtoupper(trim((string)$options['charge_currency']));
        } elseif (isset($options['gateway_id']) || isset($options['gateway'])) {
            $gwId = trim((string)($options['gateway_id'] ?? $options['gateway']));
            if ($this->gatewayRegistry->has($gwId)) {
                $gw = $this->gatewayRegistry->get($gwId);
                if ($gw instanceof \FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway) {
                    $pref = $gw->getPreferredPaymentCurrency();
                    if ($baseAmount->getCurrency() !== $pref) {
                        $chargeCurrency = $pref;
                    }
                }
            }
        }

        if ($chargeCurrency === null) {
            $chargeCurrency = $baseAmount->getCurrency();
        }

        // Lock conversion snapshot if foreign charge currency
        $snapshot = null;
        if ($chargeCurrency !== $baseAmount->getCurrency()) {
            $snapshot = $this->currencyService->createLockedSnapshot($baseAmount->getCurrency(), $chargeCurrency);
            if (!$snapshot->isValidForPayment()) {
                throw new UnauthoritativeRateException(
                    "Cannot create payment intent: Exchange rate for {$baseAmount->getCurrency()} to {$chargeCurrency} is not valid for payment.",
                    $baseAmount->getCurrency(),
                    $chargeCurrency
                );
            }
            $chargeAmount = $snapshot->convert($baseAmount);
        } else {
            $chargeAmount = $baseAmount;
        }

        $methodType = null;
        if (isset($options['method_type'])) {
            $methodType = is_string($options['method_type'])
                ? PaymentMethodType::from($options['method_type'])
                : $options['method_type'];
        }

        $intent = new PaymentIntent(
            $id,
            $sourcePlugin,
            $sourceReference,
            $baseAmount,
            $chargeAmount,
            PaymentStatus::PENDING,
            $methodType,
            $options['customer_id'] ?? null,
            $snapshot,
            $options['metadata'] ?? []
        );

        $this->intents[$id] = $intent;

        // Persist to database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $this->db->insert('favorite_pay_transactions', [
                'transaction_id'      => $id,
                'source_plugin'       => $sourcePlugin,
                'source_reference'    => $sourceReference,
                'user_id'             => $options['customer_id'] ?? null,
                'base_amount'         => $baseAmount->getAmount(),
                'base_currency'       => $baseAmount->getCurrency(),
                'charge_amount'       => $chargeAmount->getAmount(),
                'charge_currency'     => $chargeAmount->getCurrency(),
                'exchange_rate'       => $snapshot ? (float)$snapshot->getRateFactor() / $snapshot->getRateScale() : 1.0,
                'rate_factor'         => $snapshot?->getRateFactor(),
                'rate_scale'          => $snapshot?->getRateScale(),
                'payment_method_type' => $methodType?->value,
                'status'              => PaymentStatus::PENDING->value,
                'metadata'            => !empty($options['metadata']) ? json_encode($options['metadata']) : null,
                'created_at'          => date('Y-m-d H:i:s'),
            ]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.intent.created', $intent);
            do_action('favorite.pay.payment.created', [
                'transaction_id' => $id,
                'source_plugin'  => $sourcePlugin,
                'amount_bdt'     => $baseAmount->getAmount(),
            ]);
        }

        return $intent;
    }

    public function getIntent(string $intentId): ?PaymentIntent
    {
        if (isset($this->intents[$intentId])) {
            return $this->intents[$intentId];
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $row = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?", [$intentId]);
            if ($row) {
                $baseAmount = new Money((int)$row->base_amount, (string)$row->base_currency);
                $chargeAmount = new Money((int)$row->charge_amount, (string)$row->charge_currency);
                $intent = new PaymentIntent(
                    (string)$row->transaction_id,
                    (string)$row->source_plugin,
                    (string)$row->source_reference,
                    $baseAmount,
                    $chargeAmount,
                    PaymentStatus::from((string)$row->status),
                    !empty($row->payment_method_type) ? PaymentMethodType::from((string)$row->payment_method_type) : null,
                    $row->user_id ? (int)$row->user_id : null
                );
                $this->intents[$intentId] = $intent;
                return $intent;
            }
        }

        return null;
    }

    public function updateIntentStatus(string $intentId, PaymentStatus $newStatus): PaymentIntent
    {
        $intent = $this->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment intent not found: {$intentId}");
        }

        if (!$intent->getStatus()->canTransitionTo($newStatus)) {
            throw new RuntimeException(
                "Invalid status transition from {$intent->getStatus()->value} to {$newStatus->value}."
            );
        }

        $updated = $intent->withStatus($newStatus);
        $this->intents[$intentId] = $updated;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $this->db->update('favorite_pay_transactions', [
                'status'       => $newStatus->value,
                'completed_at' => $newStatus->isFinal() ? date('Y-m-d H:i:s') : null,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['transaction_id' => $intentId]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.intent.status_updated', [
                'intent_id'        => $intentId,
                'status'           => $newStatus->value,
                'source_plugin'    => $updated->getSourcePlugin(),
                'source_reference' => $updated->getSourceReference(),
            ]);

            if ($newStatus === PaymentStatus::SUCCEEDED) {
                do_action('favorite.pay.payment.succeeded', [
                    'transaction_id'   => $intentId,
                    'source_plugin'    => $updated->getSourcePlugin(),
                    'source_reference' => $updated->getSourceReference(),
                    'amount_bdt'       => $updated->getBaseAmount()->getAmount(),
                    'currency_pay'     => $updated->getChargeAmount()->getCurrency(),
                    'settled_at'       => date('Y-m-d H:i:s'),
                ]);
            } elseif ($newStatus === PaymentStatus::FAILED) {
                do_action('favorite.pay.payment.failed', [
                    'transaction_id'   => $intentId,
                    'source_plugin'    => $updated->getSourcePlugin(),
                    'source_reference' => $updated->getSourceReference(),
                    'reason'           => 'Payment rejected or failed',
                ]);
            }
        }

        return $updated;
    }

    public function submitManualVerification(
        string $intentId,
        string $gatewayId,
        string $transactionReference,
        array $details = []
    ): PaymentAttempt {
        $intent = $this->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment intent not found: {$intentId}");
        }

        // Prevent submission if intent is already settled or final
        if ($intent->getStatus()->isFinal()) {
            throw new RuntimeException("Cannot submit payment attempt for final intent status: {$intent->getStatus()->value}");
        }

        // Resolve gateway via GatewayRegistry
        if (!$this->gatewayRegistry->has($gatewayId)) {
            throw new InvalidArgumentException("Payment gateway not registered: {$gatewayId}");
        }

        $gateway = $this->gatewayRegistry->get($gatewayId);
        if (!$gateway->isEnabled()) {
            throw new RuntimeException("Payment gateway '{$gatewayId}' is disabled.");
        }

        $trimmedRef = trim($transactionReference);
        if ($trimmedRef === '') {
            throw new InvalidArgumentException("Transaction reference (TrxID) cannot be empty for manual payment.");
        }

        // Idempotency check
        $idempotencyKey = isset($details['idempotency_key']) ? trim((string)$details['idempotency_key']) : null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            if (isset($this->idempotentAttempts[$idempotencyKey])) {
                return $this->idempotentAttempts[$idempotencyKey];
            }
        }

        // Duplicate TrxID check for the same gateway
        $compositeKey = strtolower($gateway->getId() . ':' . $trimmedRef);
        if (isset($this->providerReferences[$compositeKey])) {
            throw new RuntimeException("Duplicate transaction reference '{$trimmedRef}' for gateway '{$gateway->getId()}'.");
        }

        // Delegate attempt creation to the concrete gateway driver
        $params = array_merge($details, [
            'transaction_reference' => $trimmedRef,
            'idempotency_key'       => $idempotencyKey,
        ]);
        $attempt = $gateway->createAttempt($intent, $params);

        $attemptId = $attempt->getId();
        $this->attempts[$attemptId] = $attempt;
        $this->providerReferences[$compositeKey] = $attemptId;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $this->idempotentAttempts[$idempotencyKey] = $attempt;
        }

        // Update intent status to AWAITING_VERIFICATION
        $this->updateIntentStatus($intentId, PaymentStatus::AWAITING_VERIFICATION);

        // Persist attempt to database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $this->db->insert('favorite_pay_attempts', [
                'attempt_id'         => $attemptId,
                'transaction_id'     => $intentId,
                'gateway_id'         => $gateway->getId(),
                'amount'             => $attempt->getAmount()->getAmount(),
                'currency'           => $attempt->getAmount()->getCurrency(),
                'status'             => PaymentStatus::AWAITING_VERIFICATION->value,
                'provider_reference' => $trimmedRef,
                'operator_notes'     => $details['notes'] ?? null,
                'created_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.submitted', [
                'attempt_id' => $attemptId,
                'intent_id'  => $intentId,
                'trx_id'     => $trimmedRef,
                'gateway_id' => $gateway->getId(),
            ]);

            do_action('favorite.pay.payment.awaiting_verification', [
                'transaction_id' => $intentId,
                'attempt_id'     => $attemptId,
                'gateway_id'     => $gateway->getId(),
            ]);
        }

        return $attempt;
    }

    public function approveManualPayment(
        string $attemptId,
        int $operatorUserId,
        ?string $notes = null
    ): PaymentAttempt {
        $attempt = $this->getAttempt($attemptId);
        if (!$attempt) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
            throw new RuntimeException("Cannot approve payment attempt: attempt is already approved.");
        }

        if ($attempt->getStatus() === PaymentStatus::FAILED) {
            throw new RuntimeException("Cannot approve payment attempt: attempt was already rejected.");
        }

        if ($attempt->getStatus() !== PaymentStatus::AWAITING_VERIFICATION) {
            throw new RuntimeException("Cannot approve attempt in status: {$attempt->getStatus()->value}");
        }

        // Delegate verification to gateway driver
        $gateway = $this->gatewayRegistry->get($attempt->getGatewayId());
        $verifiedAttempt = $gateway->verifyAttempt($attempt, [
            'action'      => 'approve',
            'operator_id' => $operatorUserId,
            'notes'       => $notes,
        ]);

        $approvedAttempt = $verifiedAttempt->markApproved($operatorUserId, $notes);
        $this->attempts[$attemptId] = $approvedAttempt;

        // Transition Intent to SUCCEEDED
        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::SUCCEEDED);

        // Update database attempt record if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $this->db->update('favorite_pay_attempts', [
                'status'         => PaymentStatus::SUCCEEDED->value,
                'verified_by'    => $operatorUserId,
                'verified_at'    => date('Y-m-d H:i:s'),
                'operator_notes' => $notes,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['attempt_id' => $attemptId]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.approved', [
                'attempt_id'  => $attemptId,
                'operator_id' => $operatorUserId,
            ]);
        }

        return $approvedAttempt;
    }

    public function rejectManualPayment(
        string $attemptId,
        int $operatorUserId,
        string $reason
    ): PaymentAttempt {
        $attempt = $this->getAttempt($attemptId);
        if (!$attempt) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
            throw new RuntimeException("Cannot reject payment attempt: attempt was already approved.");
        }

        if ($attempt->getStatus() === PaymentStatus::FAILED) {
            throw new RuntimeException("Cannot reject payment attempt: attempt is already rejected.");
        }

        if ($attempt->getStatus() !== PaymentStatus::AWAITING_VERIFICATION) {
            throw new RuntimeException("Cannot reject attempt in status: {$attempt->getStatus()->value}");
        }

        $gateway = $this->gatewayRegistry->get($attempt->getGatewayId());
        $verifiedAttempt = $gateway->verifyAttempt($attempt, [
            'action'      => 'reject',
            'operator_id' => $operatorUserId,
            'reason'      => $reason,
        ]);

        $rejectedAttempt = $verifiedAttempt->markRejected($operatorUserId, $reason);
        $this->attempts[$attemptId] = $rejectedAttempt;

        // Transition Intent to FAILED
        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::FAILED);

        // Update database attempt record if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $this->db->update('favorite_pay_attempts', [
                'status'        => PaymentStatus::FAILED->value,
                'verified_by'   => $operatorUserId,
                'verified_at'   => date('Y-m-d H:i:s'),
                'error_message' => $reason,
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['attempt_id' => $attemptId]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.rejected', [
                'attempt_id'  => $attemptId,
                'operator_id' => $operatorUserId,
                'reason'      => $reason,
            ]);
        }

        return $rejectedAttempt;
    }

    public function getAttempt(string $attemptId): ?PaymentAttempt
    {
        if (isset($this->attempts[$attemptId])) {
            return $this->attempts[$attemptId];
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $row = $this->db->selectOne("SELECT * FROM favorite_pay_attempts WHERE attempt_id = ?", [$attemptId]);
            if ($row) {
                $amount = new Money((int)$row->amount, (string)$row->currency);
                $attempt = new PaymentAttempt(
                    (string)$row->attempt_id,
                    (string)$row->transaction_id,
                    (string)$row->gateway_id,
                    $amount,
                    PaymentStatus::from((string)$row->status),
                    $row->provider_reference ? (string)$row->provider_reference : null,
                    $row->operator_notes ? (string)$row->operator_notes : null,
                    $row->verified_by ? (int)$row->verified_by : null,
                    $row->verified_at ? (string)$row->verified_at : null,
                    $row->error_message ? (string)$row->error_message : null
                );
                $this->attempts[$attemptId] = $attempt;
                return $attempt;
            }
        }

        return null;
    }

    public function recordAttempt(PaymentAttempt $attempt): void
    {
        $this->attempts[$attempt->getId()] = $attempt;
        if ($attempt->getTransactionReference() !== null) {
            $compositeKey = strtolower($attempt->getGatewayId() . ':' . trim($attempt->getTransactionReference()));
            $this->providerReferences[$compositeKey] = $attempt->getId();
        }
    }

    public function hasTransactions(): bool
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $row = $this->db->selectOne("SELECT 1 FROM favorite_pay_transactions LIMIT 1");
            if ($row !== null) {
                return true;
            }
        }

        return !empty($this->intents);
    }

    public function hasAttempts(): bool
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $row = $this->db->selectOne("SELECT 1 FROM favorite_pay_attempts LIMIT 1");
            if ($row !== null) {
                return true;
            }
        }

        return !empty($this->attempts);
    }

    public function hasFinancialActivity(): bool
    {
        return $this->hasTransactions() || $this->hasAttempts();
    }

    /**
     * Resolve a payment attempt for incoming webhook notifications.
     */
    public function findAttemptForWebhook(
        string $gatewayId,
        ?string $attemptOrTxId = null,
        ?string $providerRef = null
    ): ?PaymentAttempt {
        // 1. Direct attempt ID lookup
        if ($attemptOrTxId !== null && $attemptOrTxId !== '') {
            $attempt = $this->getAttempt($attemptOrTxId);
            if ($attempt !== null && $attempt->getGatewayId() === $gatewayId) {
                return $attempt;
            }

            // 2. Transaction / Intent ID lookup
            $intent = $this->getIntent($attemptOrTxId);
            if ($intent !== null) {
                // Check memory attempts
                foreach ($this->attempts as $att) {
                    if ($att->getIntentId() === $intent->getId() && $att->getGatewayId() === $gatewayId) {
                        return $att;
                    }
                }

                // Check database
                if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
                    $row = $this->db->selectOne(
                        "SELECT * FROM favorite_pay_attempts WHERE transaction_id = ? AND gateway_id = ? ORDER BY id DESC LIMIT 1",
                        [$intent->getId(), $gatewayId]
                    );
                    if ($row) {
                        return $this->getAttempt((string)$row->attempt_id);
                    }
                }
            }
        }

        // 3. Gateway + Provider Reference lookup
        if ($providerRef !== null && $providerRef !== '') {
            $compositeKey = strtolower($gatewayId . ':' . trim($providerRef));
            if (isset($this->providerReferences[$compositeKey])) {
                return $this->getAttempt($this->providerReferences[$compositeKey]);
            }

            if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
                $row = $this->db->selectOne(
                    "SELECT * FROM favorite_pay_attempts WHERE gateway_id = ? AND provider_reference = ? LIMIT 1",
                    [$gatewayId, trim($providerRef)]
                );
                if ($row) {
                    return $this->getAttempt((string)$row->attempt_id);
                }
            }
        }

        return null;
    }

    /**
     * Mark a payment attempt as successfully completed via a verified webhook.
     * Enforces idempotency and status transition guards.
     */
    public function markAttemptSuccessfulViaWebhook(
        string $attemptId,
        ?string $providerReference = null,
        array $metadata = []
    ): PaymentAttempt {
        $attempt = $this->getAttempt($attemptId);
        if (!$attempt) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        // Idempotency: if already SUCCEEDED, return cleanly
        if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
            return $attempt;
        }

        if (!$attempt->getStatus()->canTransitionTo(PaymentStatus::SUCCEEDED)) {
            throw new RuntimeException(
                "Cannot transition attempt from {$attempt->getStatus()->value} to succeeded."
            );
        }

        $succeededAttempt = $attempt->markSucceeded($providerReference, $metadata);
        $this->attempts[$attemptId] = $succeededAttempt;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $this->db->update('favorite_pay_attempts', [
                'status'             => PaymentStatus::SUCCEEDED->value,
                'provider_reference' => $providerReference ?? $attempt->getTransactionReference(),
                'verified_at'        => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ], ['attempt_id' => $attemptId]);
        }

        // Transition the parent PaymentIntent to SUCCEEDED
        // This fires 'favorite.pay.payment.succeeded' which triggers WalletService auto-settlement
        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::SUCCEEDED);

        return $succeededAttempt;
    }

    /**
     * Mark a payment attempt as failed via a verified webhook.
     */
    public function markAttemptFailedViaWebhook(
        string $attemptId,
        string $errorMessage,
        ?string $providerReference = null,
        array $metadata = []
    ): PaymentAttempt {
        $attempt = $this->getAttempt($attemptId);
        if (!$attempt) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
            throw new RuntimeException("Cannot mark already succeeded payment attempt as failed.");
        }

        $failedAttempt = $attempt->markFailed($errorMessage, $providerReference, $metadata);
        $this->attempts[$attemptId] = $failedAttempt;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $this->db->update('favorite_pay_attempts', [
                'status'             => PaymentStatus::FAILED->value,
                'provider_reference' => $providerReference ?? $attempt->getTransactionReference(),
                'error_message'      => $errorMessage,
                'updated_at'         => date('Y-m-d H:i:s'),
            ], ['attempt_id' => $attemptId]);
        }

        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::FAILED);

        return $failedAttempt;
    }
}
