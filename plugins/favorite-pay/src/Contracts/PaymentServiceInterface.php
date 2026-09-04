<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;

interface PaymentServiceInterface
{
    public function createIntent(
        string $sourcePlugin,
        string $sourceReference,
        Money $baseAmount,
        array $options = []
    ): PaymentIntent;

    public function getIntent(string $intentId): ?PaymentIntent;

    public function updateIntentStatus(string $intentId, PaymentStatus $newStatus): PaymentIntent;

    /**
     * Customer submits manual Bangladesh payment transaction reference (TrxID).
     * Moves intent to 'awaiting_verification'.
     */
    public function submitManualVerification(
        string $intentId,
        string $gatewayId,
        string $transactionReference,
        array $details = []
    ): PaymentAttempt;

    /**
     * Operator approves manual payment after inspecting incoming bank/MFS statement.
     */
    public function approveManualPayment(
        string $attemptId,
        int $operatorUserId,
        ?string $notes = null
    ): PaymentAttempt;

    /**
     * Operator rejects manual payment due to mismatched TrxID or missing funds.
     */
    public function rejectManualPayment(
        string $attemptId,
        int $operatorUserId,
        string $reason
    ): PaymentAttempt;
}
