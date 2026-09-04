<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

/**
 * Verified Webhook Result
 *
 * Immutable Value Object representing the authoritative outcome of a gateway-specific
 * webhook verification step.
 */
final class VerifiedWebhookResult
{
    private bool $verified;
    private ?string $transactionId;
    private ?string $providerReference;
    private ?PaymentStatus $status;
    private ?Money $amount;
    private ?string $currency;
    private ?string $errorMessage;
    private array $rawData;

    public function __construct(
        bool $verified,
        ?string $transactionId = null,
        ?string $providerReference = null,
        ?PaymentStatus $status = null,
        ?Money $amount = null,
        ?string $errorMessage = null,
        array $rawData = []
    ) {
        $this->verified = $verified;
        $this->transactionId = $transactionId;
        $this->providerReference = $providerReference;
        $this->status = $status;
        $this->amount = $amount;
        $this->currency = $amount?->getCurrency();
        $this->errorMessage = $errorMessage;
        $this->rawData = $rawData;
    }

    public static function success(
        string $transactionId,
        string $providerReference,
        Money $amount,
        array $rawData = []
    ): self {
        return new self(
            true,
            $transactionId,
            $providerReference,
            PaymentStatus::SUCCEEDED,
            $amount,
            null,
            $rawData
        );
    }

    public static function failed(
        string $transactionId,
        string $providerReference,
        Money $amount,
        string $errorMessage,
        array $rawData = []
    ): self {
        return new self(
            true,
            $transactionId,
            $providerReference,
            PaymentStatus::FAILED,
            $amount,
            $errorMessage,
            $rawData
        );
    }

    public static function rejected(string $errorMessage, array $rawData = []): self
    {
        return new self(
            false,
            null,
            null,
            null,
            null,
            $errorMessage,
            $rawData
        );
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function getStatus(): ?PaymentStatus
    {
        return $this->status;
    }

    public function getAmount(): ?Money
    {
        return $this->amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }
}
