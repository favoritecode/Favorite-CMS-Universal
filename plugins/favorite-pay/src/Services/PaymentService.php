<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
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
 * Implements Locked Decision:
 * 1. 100% Operator Manual Bangladesh Payment Approval (pending -> awaiting_verification -> succeeded/failed).
 * 2. Immutable amount locking at checkout time.
 */
class PaymentService implements PaymentServiceInterface
{
    private CurrencyServiceInterface $currencyService;
    private GatewayRegistry $gatewayRegistry;

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

    public function __construct(
        CurrencyServiceInterface $currencyService,
        GatewayRegistry $gatewayRegistry
    ) {
        $this->currencyService = $currencyService;
        $this->gatewayRegistry = $gatewayRegistry;
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
        $chargeCurrency = strtoupper($options['charge_currency'] ?? $baseAmount->getCurrency());

        // Lock conversion snapshot if foreign charge currency
        $snapshot = null;
        if ($chargeCurrency !== $baseAmount->getCurrency()) {
            $snapshot = $this->currencyService->createLockedSnapshot($baseAmount->getCurrency(), $chargeCurrency);
            $chargeAmount = $snapshot->convert($baseAmount);
        } else {
            $chargeAmount = $baseAmount;
        }

        $intent = new PaymentIntent(
            $id,
            $sourcePlugin,
            $sourceReference,
            $baseAmount,
            $chargeAmount,
            PaymentStatus::PENDING,
            isset($options['method_type']) ? PaymentMethodType::from($options['method_type']) : null,
            $options['customer_id'] ?? null,
            $snapshot,
            $options['metadata'] ?? []
        );

        $this->intents[$id] = $intent;

        if (function_exists('do_action')) {
            do_action('favorite.pay.intent.created', $intent);
        }

        return $intent;
    }

    public function getIntent(string $intentId): ?PaymentIntent
    {
        return $this->intents[$intentId] ?? null;
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

        if (function_exists('do_action')) {
            do_action('favorite.pay.intent.status_updated', [
                'intent_id' => $intentId,
                'status' => $newStatus->value,
                'source_plugin' => $updated->getSourcePlugin(),
                'source_reference' => $updated->getSourceReference(),
            ]);

            if ($newStatus === PaymentStatus::SUCCEEDED) {
                do_action('favorite.pay.payment.succeeded', [
                    'intent_id' => $intentId,
                    'source_plugin' => $updated->getSourcePlugin(),
                    'source_reference' => $updated->getSourceReference(),
                    'base_amount' => $updated->getBaseAmount()->getAmount(),
                    'charge_amount' => $updated->getChargeAmount()->getAmount(),
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

        $trimmedRef = trim($transactionReference);
        if ($trimmedRef === '') {
            throw new InvalidArgumentException("Transaction reference (TrxID) cannot be empty for manual payment.");
        }

        // Transition intent to AWAITING_VERIFICATION
        $this->updateIntentStatus($intentId, PaymentStatus::AWAITING_VERIFICATION);

        $attemptId = 'att_' . bin2hex(random_bytes(10));
        $attempt = new PaymentAttempt(
            $attemptId,
            $intentId,
            $gatewayId,
            $intent->getChargeAmount(),
            PaymentStatus::AWAITING_VERIFICATION,
            $trimmedRef,
            $details['notes'] ?? null
        );

        $this->attempts[$attemptId] = $attempt;

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.submitted', [
                'attempt_id' => $attemptId,
                'intent_id' => $intentId,
                'trx_id' => $trimmedRef,
                'gateway_id' => $gatewayId,
            ]);
        }

        return $attempt;
    }

    public function approveManualPayment(
        string $attemptId,
        int $operatorUserId,
        ?string $notes = null
    ): PaymentAttempt {
        if (!isset($this->attempts[$attemptId])) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        $attempt = $this->attempts[$attemptId];
        if ($attempt->getStatus() !== PaymentStatus::AWAITING_VERIFICATION) {
            throw new RuntimeException("Cannot approve attempt in status: {$attempt->getStatus()->value}");
        }

        $approvedAttempt = $attempt->markApproved($operatorUserId, $notes);
        $this->attempts[$attemptId] = $approvedAttempt;

        // Transition Intent to SUCCEEDED
        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::SUCCEEDED);

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.approved', [
                'attempt_id' => $attemptId,
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
        if (!isset($this->attempts[$attemptId])) {
            throw new InvalidArgumentException("Payment attempt not found: {$attemptId}");
        }

        $attempt = $this->attempts[$attemptId];
        if ($attempt->getStatus() !== PaymentStatus::AWAITING_VERIFICATION) {
            throw new RuntimeException("Cannot reject attempt in status: {$attempt->getStatus()->value}");
        }

        $rejectedAttempt = $attempt->markRejected($operatorUserId, $reason);
        $this->attempts[$attemptId] = $rejectedAttempt;

        // Transition Intent to FAILED
        $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::FAILED);

        if (function_exists('do_action')) {
            do_action('favorite.pay.manual.rejected', [
                'attempt_id' => $attemptId,
                'operator_id' => $operatorUserId,
                'reason' => $reason,
            ]);
        }

        return $rejectedAttempt;
    }

    public function getAttempt(string $attemptId): ?PaymentAttempt
    {
        return $this->attempts[$attemptId] ?? null;
    }
}
