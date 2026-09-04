<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

final class PaymentAttempt
{
    private string $id;
    private string $intentId;
    private string $gatewayId;
    private Money $amount;
    private PaymentStatus $status;
    private ?string $transactionReference;
    private ?string $operatorNotes;
    private ?int $verifiedBy;
    private ?string $verifiedAt;
    private ?string $errorMessage;
    private ?string $idempotencyKey;
    private ?string $senderAccount;
    private array $metadata;
    private string $createdAt;

    public function __construct(
        string $id,
        string $intentId,
        string $gatewayId,
        Money $amount,
        PaymentStatus $status = PaymentStatus::PENDING,
        ?string $transactionReference = null,
        ?string $operatorNotes = null,
        ?int $verifiedBy = null,
        ?string $verifiedAt = null,
        ?string $errorMessage = null,
        ?string $idempotencyKey = null,
        ?string $senderAccount = null,
        array $metadata = [],
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->intentId = $intentId;
        $this->gatewayId = $gatewayId;
        $this->amount = $amount;
        $this->status = $status;
        $this->transactionReference = $transactionReference;
        $this->operatorNotes = $operatorNotes;
        $this->verifiedBy = $verifiedBy;
        $this->verifiedAt = $verifiedAt;
        $this->errorMessage = $errorMessage;
        $this->idempotencyKey = $idempotencyKey;
        $this->senderAccount = $senderAccount;
        $this->metadata = $metadata;
        $this->createdAt = $createdAt ?? date('Y-m-d H:i:s');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIntentId(): string
    {
        return $this->intentId;
    }

    public function getGatewayId(): string
    {
        return $this->gatewayId;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    public function getOperatorNotes(): ?string
    {
        return $this->operatorNotes;
    }

    public function getVerificationNotes(): ?string
    {
        return $this->operatorNotes;
    }

    public function getVerifiedBy(): ?int
    {
        return $this->verifiedBy;
    }

    public function getVerifiedAt(): ?string
    {
        return $this->verifiedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getRejectionReason(): ?string
    {
        return $this->errorMessage;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getSenderAccount(): ?string
    {
        return $this->senderAccount;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function markApproved(int $operatorUserId, ?string $notes = null): self
    {
        $clone = clone $this;
        $clone->status = PaymentStatus::SUCCEEDED;
        $clone->verifiedBy = $operatorUserId;
        $clone->verifiedAt = date('Y-m-d H:i:s');
        if ($notes !== null) {
            $clone->operatorNotes = $notes;
        }
        return $clone;
    }

    public function markRejected(int $operatorUserId, string $reason): self
    {
        $clone = clone $this;
        $clone->status = PaymentStatus::FAILED;
        $clone->verifiedBy = $operatorUserId;
        $clone->verifiedAt = date('Y-m-d H:i:s');
        $clone->errorMessage = $reason;
        return $clone;
    }
}
