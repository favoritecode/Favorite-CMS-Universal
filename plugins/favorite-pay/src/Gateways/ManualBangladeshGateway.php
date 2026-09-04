<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways;

use FavoriteCMS\Pay\Contracts\ConfigurableGatewayInterface;
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
class ManualBangladeshGateway implements PaymentGatewayInterface, ConfigurableGatewayInterface
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

    public function isConfigured(): bool
    {
        $accountNumber = trim((string)($this->config['account_number'] ?? ''));
        if ($accountNumber !== '') {
            return true;
        }
        $bankName = trim((string)($this->config['bank_name'] ?? ''));
        return $bankName !== '';
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function getInstructions(array $context = []): array
    {
        return [
            'gateway_id'             => $this->id,
            'title'                  => $this->title,
            'channel'                => $this->config['channel'] ?? 'manual_bd',
            'account_name'           => $this->config['account_name'] ?? '',
            'account_number'         => $this->config['account_number'] ?? '',
            'account_type'           => $this->config['account_type'] ?? 'Personal / Merchant',
            'bank_name'              => $this->config['bank_name'] ?? '',
            'branch_name'            => $this->config['branch_name'] ?? '',
            'routing_no'             => $this->config['routing_no'] ?? '',
            'swift_code'             => $this->config['swift_code'] ?? '',
            'instructions'           => $this->config['instructions'] ?? 'Please send the exact amount to the account above and submit your transaction reference (TrxID).',
            'reference_instructions' => $this->config['reference_instructions'] ?? 'Submit the Transaction ID (TrxID) or deposit reference after completing payment.',
            'proof_requirements'     => $this->config['proof_requirements'] ?? 'Sender number/account, transaction reference (TrxID), and optional screenshot.',
            'is_enabled'             => $this->enabled,
            'is_configured'          => $this->isConfigured(),
            'is_available'           => $this->isAvailable(),
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

    public function getConfigSchema(): array
    {
        return [
            'channel' => [
                'type'        => 'text',
                'label'       => 'Payment Channel',
                'required'    => true,
                'secret'      => false,
                'description' => 'Identifier for the payment channel (e.g. bkash, nagad, rocket, bank).',
            ],
            'account_name' => [
                'type'        => 'text',
                'label'       => 'Merchant / Account Name',
                'required'    => false,
                'secret'      => false,
                'description' => 'Account holder or organization name.',
            ],
            'account_number' => [
                'type'        => 'text',
                'label'       => 'Account Number / Phone',
                'required'    => false,
                'secret'      => false,
                'description' => 'Receiving account number or mobile wallet number.',
            ],
            'account_type' => [
                'type'        => 'text',
                'label'       => 'Account Type',
                'required'    => false,
                'secret'      => false,
                'description' => 'Account type (e.g. Personal, Merchant, Agent).',
            ],
            'bank_name' => [
                'type'        => 'text',
                'label'       => 'Bank Name',
                'required'    => false,
                'secret'      => false,
                'description' => 'Name of the bank for manual wire/transfer.',
            ],
            'branch_name' => [
                'type'        => 'text',
                'label'       => 'Branch Name',
                'required'    => false,
                'secret'      => false,
                'description' => 'Branch name where account is held.',
            ],
            'routing_no' => [
                'type'        => 'text',
                'label'       => 'Routing Number',
                'required'    => false,
                'secret'      => false,
                'description' => 'Bank routing number for electronic fund transfer (BEFTN/NPSB).',
            ],
            'swift_code' => [
                'type'        => 'text',
                'label'       => 'SWIFT / BIC',
                'required'    => false,
                'secret'      => false,
                'description' => 'SWIFT / BIC code for international wire transfers.',
            ],
            'instructions' => [
                'type'        => 'textarea',
                'label'       => 'Customer Instructions',
                'required'    => false,
                'secret'      => false,
                'description' => 'Instructions displayed to customer on checkout.',
            ],
            'reference_instructions' => [
                'type'        => 'textarea',
                'label'       => 'Reference Instructions',
                'required'    => false,
                'secret'      => false,
                'description' => 'Guidance on what TrxID / reference the customer must provide.',
            ],
            'proof_requirements' => [
                'type'        => 'text',
                'label'       => 'Proof Requirements',
                'required'    => false,
                'secret'      => false,
                'description' => 'Information required from customer as proof of payment.',
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $validated = [];
        $validated['channel'] = trim((string)($config['channel'] ?? 'manual_bd'));
        $validated['account_name'] = trim((string)($config['account_name'] ?? ''));
        $validated['account_number'] = trim((string)($config['account_number'] ?? ''));
        $validated['account_type'] = trim((string)($config['account_type'] ?? 'Personal / Merchant'));
        $validated['bank_name'] = trim((string)($config['bank_name'] ?? ''));
        $validated['branch_name'] = trim((string)($config['branch_name'] ?? ''));
        $validated['routing_no'] = trim((string)($config['routing_no'] ?? ''));
        $validated['swift_code'] = trim((string)($config['swift_code'] ?? ''));
        $validated['instructions'] = trim((string)($config['instructions'] ?? ''));
        $validated['reference_instructions'] = trim((string)($config['reference_instructions'] ?? ''));
        $validated['proof_requirements'] = trim((string)($config['proof_requirements'] ?? ''));
        return $validated;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $this->validateConfig($config));
    }
}
