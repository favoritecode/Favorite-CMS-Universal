<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

final class PaymentIntent
{
    private string $id;
    private string $sourcePlugin;
    private string $sourceReference;
    private ?int $customerId;
    private Money $baseAmount;
    private Money $chargeAmount;
    private PaymentStatus $status;
    private ?PaymentMethodType $methodType;
    private ?ConversionSnapshot $conversionSnapshot;
    private array $metadata;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(
        string $id,
        string $sourcePlugin,
        string $sourceReference,
        Money $baseAmount,
        Money $chargeAmount,
        PaymentStatus $status = PaymentStatus::PENDING,
        ?PaymentMethodType $methodType = null,
        ?int $customerId = null,
        ?ConversionSnapshot $conversionSnapshot = null,
        array $metadata = [],
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->sourcePlugin = $sourcePlugin;
        $this->sourceReference = $sourceReference;
        $this->baseAmount = $baseAmount;
        $this->chargeAmount = $chargeAmount;
        $this->status = $status;
        $this->methodType = $methodType;
        $this->customerId = $customerId;
        $this->conversionSnapshot = $conversionSnapshot;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt ?? date('Y-m-d H:i:s');
        $this->updatedAt = $updatedAt ?? $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSourcePlugin(): string
    {
        return $this->sourcePlugin;
    }

    public function getSourceReference(): string
    {
        return $this->sourceReference;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getUserId(): ?int
    {
        return $this->customerId;
    }

    public function getBaseAmount(): Money
    {
        return $this->baseAmount;
    }

    public function getChargeAmount(): Money
    {
        return $this->chargeAmount;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getMethodType(): ?PaymentMethodType
    {
        return $this->methodType;
    }

    public function getConversionSnapshot(): ?ConversionSnapshot
    {
        return $this->conversionSnapshot;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function withStatus(PaymentStatus $newStatus): self
    {
        $clone = clone $this;
        $clone->status = $newStatus;
        $clone->updatedAt = date('Y-m-d H:i:s');
        return $clone;
    }

    public function withMethodType(PaymentMethodType $methodType): self
    {
        $clone = clone $this;
        $clone->methodType = $methodType;
        $clone->updatedAt = date('Y-m-d H:i:s');
        return $clone;
    }
}
