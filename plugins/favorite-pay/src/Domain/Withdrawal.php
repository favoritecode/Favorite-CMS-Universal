<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

use InvalidArgumentException;

final class Withdrawal
{
    private string $id;
    private int $userId;
    private ?int $walletId;
    private Money $amount;
    private Money $fee;
    private Money $netAmount;
    private string $method;
    private array $destinationData;
    private string $destinationMasked;
    private WithdrawalStatus $status;
    private ?string $holdReference;
    private ?string $transactionReference;
    private ?string $idempotencyKey;
    private ?int $adminUserId;
    private ?string $operatorNotes;
    private array $auditTrail;
    private string $createdAt;
    private ?string $updatedAt;
    private ?string $processedAt;

    public function __construct(
        string $id,
        int $userId,
        Money $amount,
        string $method,
        array $destinationData,
        WithdrawalStatus $status = WithdrawalStatus::PENDING,
        ?Money $fee = null,
        ?Money $netAmount = null,
        ?string $destinationMasked = null,
        ?int $walletId = null,
        ?string $holdReference = null,
        ?string $transactionReference = null,
        ?string $idempotencyKey = null,
        ?int $adminUserId = null,
        ?string $operatorNotes = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $processedAt = null,
        ?array $auditTrail = null
    ) {
        $trimmedId = trim($id);
        if ($trimmedId === '') {
            throw new InvalidArgumentException("Withdrawal ID cannot be empty.");
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException("User ID must be positive.");
        }

        $currency = $amount->getCurrency();
        $feeMoney = $fee ?? new Money(0, $currency);
        $netMoney = $netAmount ?? $amount->subtract($feeMoney);

        $this->id = $trimmedId;
        $this->userId = $userId;
        $this->amount = $amount;
        $this->fee = $feeMoney;
        $this->netAmount = $netMoney;
        $this->method = strtolower(trim($method));
        $this->destinationData = $destinationData;
        $this->destinationMasked = $destinationMasked ?? self::maskDestination($this->method, $destinationData);
        $this->status = $status;
        $this->walletId = $walletId;
        $this->holdReference = $holdReference;
        $this->transactionReference = $transactionReference;
        $this->idempotencyKey = $idempotencyKey;
        $this->adminUserId = $adminUserId;
        $this->operatorNotes = $operatorNotes;
        $this->auditTrail = $auditTrail ?? [];
        $this->createdAt = $createdAt ?? date('Y-m-d H:i:s');
        $this->updatedAt = $updatedAt;
        $this->processedAt = $processedAt;
    }

    public static function maskDestination(string $method, array $data): string
    {
        $m = strtolower(trim($method));
        if (in_array($m, ['bkash', 'nagad', 'rocket', 'manual_bkash', 'manual_nagad', 'manual_rocket'], true)) {
            $account = trim((string)($data['account_number'] ?? $data['phone'] ?? $data['number'] ?? $data['account'] ?? $data['destination'] ?? ''));
            if (strlen($account) >= 8) {
                return substr($account, 0, 3) . '****' . substr($account, -4);
            }
            return !empty($account) ? '***' . substr($account, -2) : 'N/A';
        }

        if ($m === 'bank_transfer' || $m === 'bank') {
            $bankName = trim((string)($data['bank_name'] ?? 'Bank'));
            $accNum = trim((string)($data['account_number'] ?? ''));
            $accName = trim((string)($data['account_name'] ?? ''));
            $maskedNum = strlen($accNum) >= 4 ? '****' . substr($accNum, -4) : '****';
            return $bankName . ' (' . $maskedNum . ')' . ($accName !== '' ? ' - ' . $accName : '');
        }

        $val = trim((string)reset($data));
        return strlen($val) > 6 ? substr($val, 0, 2) . '****' . substr($val, -2) : '****';
    }

    public function getId(): string { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getWalletId(): ?int { return $this->walletId; }
    public function getAmount(): Money { return $this->amount; }
    public function getFee(): Money { return $this->fee; }
    public function getNetAmount(): Money { return $this->netAmount; }
    public function getCurrency(): string { return $this->amount->getCurrency(); }
    public function getMethod(): string { return $this->method; }
    public function getDestinationData(): array { return $this->destinationData; }
    public function getDestinationMasked(): string { return $this->destinationMasked; }
    public function getStatus(): WithdrawalStatus { return $this->status; }
    public function getHoldReference(): ?string { return $this->holdReference; }
    public function getTransactionReference(): ?string { return $this->transactionReference; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getAdminUserId(): ?int { return $this->adminUserId; }
    public function getOperatorNotes(): ?string { return $this->operatorNotes; }
    public function getAuditTrail(): array { return $this->auditTrail; }
    public function getCreatedAt(): string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getProcessedAt(): ?string { return $this->processedAt; }

    public function withAuditEntry(array $entry): self
    {
        $clone = clone $this;
        $clone->auditTrail[] = $entry;
        return $clone;
    }

    public function withStatus(
        WithdrawalStatus $newStatus,
        ?int $adminId = null,
        ?string $notes = null,
        ?string $txRef = null,
        ?array $auditEntry = null
    ): self {
        $clone = clone $this;
        $clone->status = $newStatus;
        $clone->updatedAt = date('Y-m-d H:i:s');
        if ($adminId !== null) {
            $clone->adminUserId = $adminId;
        }
        if ($notes !== null) {
            $clone->operatorNotes = $notes;
        }
        if ($txRef !== null) {
            $clone->transactionReference = $txRef;
        }
        if ($newStatus->isFinal()) {
            $clone->processedAt = date('Y-m-d H:i:s');
        }
        if ($auditEntry !== null) {
            $clone->auditTrail[] = $auditEntry;
        }
        return $clone;
    }

    public function withNotes(string $notes, ?int $adminId = null, ?array $auditEntry = null): self
    {
        $clone = clone $this;
        $clone->operatorNotes = $notes;
        $clone->updatedAt = date('Y-m-d H:i:s');
        if ($adminId !== null) {
            $clone->adminUserId = $adminId;
        }
        if ($auditEntry !== null) {
            $clone->auditTrail[] = $auditEntry;
        }
        return $clone;
    }

    public function withTransactionReference(string $txRef, ?int $adminId = null, ?array $auditEntry = null): self
    {
        $clone = clone $this;
        $clone->transactionReference = $txRef;
        $clone->updatedAt = date('Y-m-d H:i:s');
        if ($adminId !== null) {
            $clone->adminUserId = $adminId;
        }
        if ($auditEntry !== null) {
            $clone->auditTrail[] = $auditEntry;
        }
        return $clone;
    }

    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'withdrawal_id'         => $this->id,
            'user_id'               => $this->userId,
            'wallet_id'             => $this->walletId,
            'amount'                => $this->amount->getAmount(),
            'currency'              => $this->amount->getCurrency(),
            'fee'                   => $this->fee->getAmount(),
            'net_amount'            => $this->netAmount->getAmount(),
            'method'                => $this->method,
            'destination_data'      => $this->destinationData,
            'destination_masked'    => $this->destinationMasked,
            'status'                => $this->status->value,
            'hold_reference'        => $this->holdReference,
            'transaction_reference' => $this->transactionReference,
            'idempotency_key'       => $this->idempotencyKey,
            'admin_user_id'         => $this->adminUserId,
            'operator_notes'        => $this->operatorNotes,
            'audit_trail'           => $this->auditTrail,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
            'processed_at'          => $this->processedAt,
        ];
    }
}
