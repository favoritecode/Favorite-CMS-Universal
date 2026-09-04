<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\RefundServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use InvalidArgumentException;
use RuntimeException;

class RefundService implements RefundServiceInterface
{
    private PaymentServiceInterface $paymentService;

    /** @var array<string, array[]> */
    private array $refunds = [];

    public function __construct(PaymentServiceInterface $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function createRefund(
        string $intentId,
        ?Money $amount = null,
        string $reason = ''
    ): array {
        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment intent not found: {$intentId}");
        }

        if ($intent->getStatus() !== PaymentStatus::SUCCEEDED && $intent->getStatus() !== PaymentStatus::PARTIALLY_REFUNDED) {
            throw new RuntimeException("Cannot refund payment with status: {$intent->getStatus()->value}");
        }

        $refundAmount = $amount ?? $intent->getChargeAmount();
        if (!$refundAmount->isPositive()) {
            throw new InvalidArgumentException("Refund amount must be strictly positive.");
        }

        if ($refundAmount->greaterThan($intent->getChargeAmount())) {
            throw new RuntimeException("Refund amount exceeds original charge amount.");
        }

        $refundRecord = [
            'id' => 'ref_' . bin2hex(random_bytes(8)),
            'intent_id' => $intentId,
            'amount' => $refundAmount->getAmount(),
            'currency' => $refundAmount->getCurrency(),
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->refunds[$intentId][] = $refundRecord;

        $newStatus = $refundAmount->equals($intent->getChargeAmount())
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        $this->paymentService->updateIntentStatus($intentId, $newStatus);

        if (function_exists('do_action')) {
            do_action('favorite.pay.refund.created', $refundRecord);
        }

        return $refundRecord;
    }

    public function getRefunds(string $intentId): array
    {
        return $this->refunds[$intentId] ?? [];
    }
}
