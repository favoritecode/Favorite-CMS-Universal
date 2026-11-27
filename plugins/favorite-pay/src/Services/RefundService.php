<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\RefundableGatewayInterface;
use FavoriteCMS\Pay\Contracts\RefundServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use InvalidArgumentException;
use RuntimeException;

class RefundService implements RefundServiceInterface
{
    private PaymentServiceInterface $paymentService;
    private ?GatewayRegistry $gatewayRegistry;
    private ?Database $db;

    /** @var array<string, array[]> */
    private array $refunds = [];

    public function __construct(
        PaymentServiceInterface $paymentService,
        ?GatewayRegistry $gatewayRegistry = null,
        ?Database $db = null
    ) {
        $this->paymentService = $paymentService;
        $this->gatewayRegistry = $gatewayRegistry;
        $this->db = $db;
    }

    public function setGatewayRegistry(GatewayRegistry $gatewayRegistry): void
    {
        $this->gatewayRegistry = $gatewayRegistry;
    }

    public function setDatabase(?Database $db): void
    {
        $this->db = $db;
    }

    public function createRefund(
        string $intentId,
        ?Money $amount = null,
        string $reason = '',
        array $options = []
    ): array {
        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment intent not found: {$intentId}");
        }

        if ($intent->getStatus() !== PaymentStatus::SUCCEEDED && $intent->getStatus() !== PaymentStatus::PARTIALLY_REFUNDED) {
            throw new RuntimeException("Cannot refund payment with status: {$intent->getStatus()->value}");
        }

        $chargeAmount = $intent->getChargeAmount();
        $baseAmount = $intent->getBaseAmount();

        $refundAmount = $amount ?? $chargeAmount;
        if (!$refundAmount->isPositive()) {
            throw new InvalidArgumentException("Refund amount must be strictly positive.");
        }

        if ($refundAmount->getCurrency() === $chargeAmount->getCurrency()) {
            if ($refundAmount->greaterThan($chargeAmount)) {
                throw new RuntimeException("Refund amount exceeds original charge amount.");
            }
        } elseif ($refundAmount->getCurrency() === $baseAmount->getCurrency()) {
            if ($refundAmount->greaterThan($baseAmount)) {
                throw new RuntimeException("Refund amount exceeds original order base amount.");
            }
        } else {
            throw new InvalidArgumentException("Refund currency '{$refundAmount->getCurrency()}' does not match charge currency '{$chargeAmount->getCurrency()}' or order currency '{$baseAmount->getCurrency()}'.");
        }

        $refundId = 'ref_' . bin2hex(random_bytes(8));
        $providerRef = $options['provider_refund_reference'] ?? null;
        $operatorId = isset($options['operator_id']) ? (int)$options['operator_id'] : null;

        $refundRecord = [
            'id'                        => $refundId,
            'intent_id'                 => $intentId,
            'amount'                    => $refundAmount->getAmount(),
            'currency'                  => $refundAmount->getCurrency(),
            'original_order_amount'     => $baseAmount->getAmount(),
            'original_order_currency'   => $baseAmount->getCurrency(),
            'charge_amount'             => $chargeAmount->getAmount(),
            'charge_currency'           => $chargeAmount->getCurrency(),
            'conversion_snapshot'       => $intent->getConversionSnapshot()?->toArray(),
            'reason'                    => $reason,
            'provider_refund_reference' => $providerRef,
            'operator_id'               => $operatorId,
            'created_at'                => date('Y-m-d H:i:s'),
        ];


        $this->refunds[$intentId][] = $refundRecord;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_refunds')) {
            $this->db->insert('favorite_pay_refunds', [
                'refund_id'                 => $refundId,
                'transaction_id'            => $intentId,
                'amount'                    => $refundAmount->getAmount(),
                'currency'                  => $refundAmount->getCurrency(),
                'status'                    => 'succeeded',
                'provider_refund_reference' => $providerRef,
                'reason'                    => $reason,
                'operator_id'               => $operatorId,
                'created_at'                => date('Y-m-d H:i:s'),
            ]);
        }

        $newStatus = $refundAmount->equals($intent->getChargeAmount())
            ? PaymentStatus::REFUNDED
            : PaymentStatus::PARTIALLY_REFUNDED;

        $this->paymentService->updateIntentStatus($intentId, $newStatus);

        if (function_exists('do_action')) {
            do_action('favorite.pay.refund.created', $refundRecord);
        }

        return $refundRecord;
    }

    /**
     * Execute an automated refund via the payment gateway driver.
     * Rejects cleanly if the gateway does not support automated refunds.
     */
    public function createGatewayRefund(
        string $intentId,
        ?Money $amount = null,
        string $reason = '',
        ?string $gatewayId = null
    ): array {
        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment intent not found: {$intentId}");
        }

        // Determine gateway ID
        $targetGatewayId = $gatewayId ?? $intent->getGatewayId();
        if ($targetGatewayId === null && $this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $attRow = $this->db->selectOne(
                "SELECT gateway_id FROM favorite_pay_attempts WHERE transaction_id = ? AND status = 'succeeded' LIMIT 1",
                [$intentId]
            );
            if ($attRow) {
                $targetGatewayId = (string)$attRow->gateway_id;
            }
        }

        if ($targetGatewayId === null) {
            throw new RuntimeException("Cannot execute gateway refund: no payment gateway associated with intent {$intentId}.");
        }

        if ($this->gatewayRegistry === null || !$this->gatewayRegistry->has($targetGatewayId)) {
            throw new RuntimeException("Payment gateway '{$targetGatewayId}' is not registered.");
        }

        $gateway = $this->gatewayRegistry->get($targetGatewayId);
        if (!($gateway instanceof RefundableGatewayInterface)) {
            throw new RuntimeException("Payment gateway '{$targetGatewayId}' does not support automated refunds.");
        }

        $refundAmount = $amount ?? $intent->getChargeAmount();
        if (!$refundAmount->isPositive()) {
            throw new InvalidArgumentException("Refund amount must be strictly positive.");
        }

        $result = $gateway->refund($intentId, $refundAmount, $reason);
        if (!$result->isSuccess()) {
            throw new RuntimeException("Gateway refund failed: " . ($result->getErrorMessage() ?? "Unknown error"));
        }

        return $this->createRefund($intentId, $refundAmount, $reason, [
            'provider_refund_reference' => $result->getProviderRefundReference(),
        ]);
    }

    public function getRefunds(string $intentId): array
    {
        return $this->refunds[$intentId] ?? [];
    }

    public function hasRefunds(): bool
    {
        return !empty($this->refunds);
    }
}
