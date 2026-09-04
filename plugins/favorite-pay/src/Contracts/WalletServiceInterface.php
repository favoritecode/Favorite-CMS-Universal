<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\WalletLedgerEntry;

interface WalletServiceInterface
{
    public function getBalance(int $userId): Money;

    /**
     * Deposits funds into customer wallet.
     * Foreign-currency deposits are converted to BDT and locked at deposit time.
     */
    public function deposit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry;

    public function debit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry;

    public function hold(
        int $userId,
        Money $amount,
        string $referenceId
    ): WalletLedgerEntry;

    public function releaseHold(
        int $userId,
        Money $amount,
        string $referenceId
    ): WalletLedgerEntry;

    /**
     * @return WalletLedgerEntry[]
     */
    public function getLedgerHistory(int $userId, int $limit = 50, int $offset = 0): array;
}
