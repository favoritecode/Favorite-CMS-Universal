<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

final class GatewayRefundResult
{
    private bool $success;
    private ?string $providerRefundReference;
    private ?Money $refundedAmount;
    private ?string $errorMessage;
    private array $rawResponse;

    public function __construct(
        bool $success,
        ?string $providerRefundReference = null,
        ?Money $refundedAmount = null,
        ?string $errorMessage = null,
        array $rawResponse = []
    ) {
        $this->success = $success;
        $this->providerRefundReference = $providerRefundReference;
        $this->refundedAmount = $refundedAmount;
        $this->errorMessage = $errorMessage;
        $this->rawResponse = $rawResponse;
    }

    public static function success(
        string $providerRefundReference,
        Money $refundedAmount,
        array $rawResponse = []
    ): self {
        return new self(true, $providerRefundReference, $refundedAmount, null, $rawResponse);
    }

    public static function failure(string $errorMessage, array $rawResponse = []): self
    {
        return new self(false, null, null, $errorMessage, $rawResponse);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getProviderRefundReference(): ?string
    {
        return $this->providerRefundReference;
    }

    public function getRefundedAmount(): ?Money
    {
        return $this->refundedAmount;
    }

    public function getAmount(): ?Money
    {
        return $this->refundedAmount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
