<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways;

use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manual Bangladesh Payment Gateway Driver
 *
 * Models offline/manual payment methods in Bangladesh:
 * - bKash Manual Payment (Send Money / Merchant Payment)
 * - Nagad Manual Payment
 * - Bank Wire / EFT / Cash Deposit
 *
 * Completely offline with ZERO external network/API requests.
 */
class ManualBangladeshGateway implements PaymentGatewayInterface
{
    protected string $id;
    protected string $title;
    protected PaymentMethodType $type;
    protected bool $enabled;
    protected array $supportedCurrencies;
    protected array $config;

    public function __construct(
        string $id = 'manual_bd',
        string $title = 'Manual Bangladesh Payment',
        PaymentMethodType $type = PaymentMethodType::MANUAL_BD,
        array $config = [],
        bool $enabled = true,
        array $supportedCurrencies = ['BDT']
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->type = $type;
        $this->config = $config;
        $this->enabled = $enabled;
        $this->supportedCurrencies = $supportedCurrencies;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): PaymentMethodType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    public function getInstructions(array $context = []): array
    {
        return [
            'gateway_id'     => $this->id,
            'title'          => $this->title,
            'channel'        => $this->config['channel'] ?? 'manual_bd',
            'account_name'   => $this->config['account_name'] ?? 'Favorite CMS Merchant',
            'account_number' => $this->config['account_number'] ?? '01700000000',
            'account_type'   => $this->config['account_type'] ?? 'Personal / Merchant',
            'bank_name'      => $this->config['bank_name'] ?? '',
            'branch_name'    => $this->config['branch_name'] ?? '',
            'routing_no'     => $this->config['routing_no'] ?? '',
            'instructions'   => $this->config['instructions'] ?? 'Please send the exact amount to the account above and submit your transaction reference (TrxID).',
            'is_enabled'     => $this->enabled,
        ];
    }

    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt
    {
        if (!$this->enabled) {
            throw new RuntimeException("Payment gateway '{$this->id}' is currently disabled.");
        }

        $currency = $intent->getChargeAmount()->getCurrency();
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            throw new InvalidArgumentException("Currency '{$currency}' is not supported by gateway '{$this->id}'.");
        }

        // Validate provider reference / TrxID
        $reference = trim((string)($params['transaction_reference'] ?? ($params['trx_id'] ?? '')));
        if ($reference === '') {
            throw new InvalidArgumentException("Transaction reference (TrxID) is required for manual Bangladesh payment.");
        }

        $senderAccount = isset($params['sender_account']) ? trim((string)$params['sender_account']) : null;
        $notes = isset($params['notes']) ? trim((string)$params['notes']) : null;
        $idempotencyKey = isset($params['idempotency_key']) ? trim((string)$params['idempotency_key']) : null;

        $attemptId = 'att_' . bin2hex(random_bytes(10));

        // Attempt starts in AWAITING_VERIFICATION — client cannot set SUCCEEDED
        return new PaymentAttempt(
            $attemptId,
            $intent->getId(),
            $this->id,
            $intent->getChargeAmount(),
            PaymentStatus::AWAITING_VERIFICATION,
            $reference,
            $notes,
            null,
            null,
            null,
            $idempotencyKey,
            $senderAccount,
            $params
        );
    }

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt
    {
        $action = $verificationData['action'] ?? 'approve';
        $operatorId = (int)($verificationData['operator_id'] ?? 1);
        $notes = $verificationData['notes'] ?? null;
        $reason = $verificationData['reason'] ?? 'Payment verified by operator';

        if ($action === 'approve') {
            return $attempt->markApproved($operatorId, $notes);
        }

        if ($action === 'reject') {
            return $attempt->markRejected($operatorId, (string)$reason);
        }

        throw new InvalidArgumentException("Unknown verification action: {$action}");
    }
}
