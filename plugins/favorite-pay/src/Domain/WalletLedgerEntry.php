<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

use InvalidArgumentException;

/**
 * Immutable Wallet Ledger Entry
 *
 * Implements Locked Decision:
 * 1. BDT-denominated wallet ledger stored in Poisha.
 * 2. Double-entry / append-only audit tracking.
 */
final class WalletLedgerEntry
{
    private string $id;
    private int $userId;
    private string $type; // 'credit', 'debit', 'hold', 'release'
    private Money $amount;
    private Money $balanceAfter;
    private string $referenceType;
    private string $referenceId;
    private string $description;
    private string $createdAt;

    public function __construct(
        string $id,
        int $userId,
        string $type,
        Money $amount,
        Money $balanceAfter,
        string $referenceType,
        string $referenceId,
        string $description = '',
        ?string $createdAt = null
    ) {
        if (!in_array($type, ['credit', 'debit', 'hold', 'release'], true)) {
            throw new InvalidArgumentException("Invalid ledger entry type: {$type}");
        }

        if ($amount->getCurrency() !== 'BDT' || $balanceAfter->getCurrency() !== 'BDT') {
            throw new InvalidArgumentException("Wallet ledger entries must strictly be denominated in BDT.");
        }

        $this->id = $id;
        $this->userId = $userId;
        $this->type = $type;
        $this->amount = $amount;
        $this->balanceAfter = $balanceAfter;
        $this->referenceType = $referenceType;
        $this->referenceId = $referenceId;
        $this->description = $description;
        $this->createdAt = $createdAt ?? date('Y-m-d H:i:s');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getBalanceAfter(): Money
    {
        return $this->balanceAfter;
    }

    public function getReferenceType(): string
    {
        return $this->referenceType;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
}
