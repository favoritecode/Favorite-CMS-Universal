<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\WalletLedgerEntry;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wallet Service
 *
 * Implements Locked Decision:
 * 1. BDT-denominated wallet ledger stored in Poisha.
 * 2. Foreign-currency deposits are converted to BDT and locked at deposit time.
 * 3. Strict overdraft prevention (balance >= debit).
 */
class WalletService implements WalletServiceInterface
{
    private CurrencyServiceInterface $currencyService;

    /** @var array<int, int> User ID => balance in BDT Poisha */
    private array $balances = [];

    /** @var array<int, WalletLedgerEntry[]> User ID => list of ledger entries */
    private array $ledgers = [];

    public function __construct(CurrencyServiceInterface $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    public function getBalance(int $userId): Money
    {
        $poisha = $this->balances[$userId] ?? 0;
        return Money::bdt($poisha);
    }

    public function deposit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry {
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Deposit amount must be strictly positive.");
        }

        // Convert foreign currencies to BDT and lock at deposit time
        $bdtAmount = $amount->getCurrency() === 'BDT'
            ? $amount
            : $this->currencyService->convert($amount, 'BDT');

        $currentBalance = $this->getBalance($userId);
        $newBalance = $currentBalance->add($bdtAmount);

        $this->balances[$userId] = $newBalance->getAmount();

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'credit',
            $bdtAmount,
            $newBalance,
            'deposit',
            $referenceId,
            $description !== '' ? $description : "Deposit to wallet"
        );

        $this->ledgers[$userId][] = $entry;

        if (function_exists('do_action')) {
            do_action('favorite.pay.wallet.credited', [
                'user_id' => $userId,
                'amount' => $bdtAmount->getAmount(),
                'balance' => $newBalance->getAmount(),
            ]);
        }

        return $entry;
    }

    public function debit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry {
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Debit amount must be strictly positive.");
        }

        // Must be in BDT
        $bdtAmount = $amount->getCurrency() === 'BDT'
            ? $amount
            : $this->currencyService->convert($amount, 'BDT');

        $currentBalance = $this->getBalance($userId);
        if ($currentBalance->lessThan($bdtAmount)) {
            throw new RuntimeException("Insufficient wallet balance for user {$userId}.");
        }

        $newBalance = $currentBalance->subtract($bdtAmount);
        $this->balances[$userId] = $newBalance->getAmount();

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'debit',
            $bdtAmount,
            $newBalance,
            'purchase',
            $referenceId,
            $description !== '' ? $description : "Wallet debit"
        );

        $this->ledgers[$userId][] = $entry;

        if (function_exists('do_action')) {
            do_action('favorite.pay.wallet.debited', [
                'user_id' => $userId,
                'amount' => $bdtAmount->getAmount(),
                'balance' => $newBalance->getAmount(),
            ]);
        }

        return $entry;
    }

    public function hold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
    {
        $bdtAmount = $amount->getCurrency() === 'BDT'
            ? $amount
            : $this->currencyService->convert($amount, 'BDT');

        $currentBalance = $this->getBalance($userId);
        if ($currentBalance->lessThan($bdtAmount)) {
            throw new RuntimeException("Insufficient balance to place hold for user {$userId}.");
        }

        $newBalance = $currentBalance->subtract($bdtAmount);
        $this->balances[$userId] = $newBalance->getAmount();

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'hold',
            $bdtAmount,
            $newBalance,
            'hold',
            $referenceId,
            "Funds placed on hold"
        );

        $this->ledgers[$userId][] = $entry;
        return $entry;
    }

    public function releaseHold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
    {
        $bdtAmount = $amount->getCurrency() === 'BDT'
            ? $amount
            : $this->currencyService->convert($amount, 'BDT');

        $currentBalance = $this->getBalance($userId);
        $newBalance = $currentBalance->add($bdtAmount);
        $this->balances[$userId] = $newBalance->getAmount();

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'release',
            $bdtAmount,
            $newBalance,
            'release',
            $referenceId,
            "Hold released back to wallet"
        );

        $this->ledgers[$userId][] = $entry;
        return $entry;
    }

    public function getLedgerHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        $list = $this->ledgers[$userId] ?? [];
        return array_slice(array_reverse($list), $offset, $limit);
    }
}
