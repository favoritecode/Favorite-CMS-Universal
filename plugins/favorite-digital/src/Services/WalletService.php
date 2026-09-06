<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use Throwable;

class WalletService
{
    protected WalletRepository $walletRepo;
    protected ?Database $db;

    public function __construct(WalletRepository $walletRepo, ?Database $db = null)
    {
        $this->walletRepo = $walletRepo;
        $this->db = $db ?? $walletRepo->getDatabase();
    }

    public function getWalletRepository(): WalletRepository
    {
        return $this->walletRepo;
    }

    public function getBalance(int $userId): string
    {
        if ($userId <= 0) {
            return '0.00';
        }

        $wallet = $this->walletRepo->getOrCreateWallet($userId);
        return number_format((float)$wallet->balance_amount, 2, '.', '');
    }

    public function credit(
        int $userId,
        string $amount,
        string $referenceId,
        string $description = '',
        ?int $orderId = null,
        string $type = 'credit'
    ): object {
        $amountMinor = $this->parseAmountMinor($amount);
        if ($amountMinor <= 0) {
            throw WalletException::invalidAmount($amount);
        }

        // Idempotency: return existing transaction if reference was already logged
        $existing = $this->walletRepo->getTransactionByReference($referenceId);
        if ($existing !== null) {
            return $existing;
        }

        return $this->executeInTransaction(function () use ($userId, $amountMinor, $referenceId, $description, $orderId, $type) {
            $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            if (!$wallet) {
                $wallet = $this->walletRepo->getOrCreateWallet($userId);
                $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            }

            if ($wallet->status !== 'active') {
                throw WalletException::walletInactive($userId, $wallet->status);
            }

            $currMinor = $this->parseAmountMinor($wallet->balance_amount);
            $afterMinor = $currMinor + $amountMinor;
            $newBalance = $this->minorToDecimal($afterMinor);
            $amountDec  = $this->minorToDecimal($amountMinor);

            $this->walletRepo->updateBalance((int)$wallet->id, $newBalance);

            $txId = $this->walletRepo->createTransaction([
                'wallet_id'     => (int)$wallet->id,
                'type'          => $type,
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $referenceId,
                'description'   => $description,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            return (object)[
                'id'            => $txId,
                'wallet_id'     => (int)$wallet->id,
                'type'          => $type,
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $referenceId,
                'description'   => $description,
            ];
        });
    }

    public function debit(
        int $userId,
        string $amount,
        string $referenceId,
        string $description = '',
        ?int $orderId = null
    ): object {
        $amountMinor = $this->parseAmountMinor($amount);
        if ($amountMinor <= 0) {
            throw WalletException::invalidAmount($amount);
        }

        // Idempotency check: duplicate call returns previous debit cleanly
        $existing = $this->walletRepo->getTransactionByReference($referenceId);
        if ($existing !== null) {
            return $existing;
        }

        return $this->executeInTransaction(function () use ($userId, $amountMinor, $referenceId, $description, $orderId) {
            $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            if (!$wallet) {
                $wallet = $this->walletRepo->getOrCreateWallet($userId);
                $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            }

            if ($wallet->status !== 'active') {
                throw WalletException::walletInactive($userId, $wallet->status);
            }

            $currMinor = $this->parseAmountMinor($wallet->balance_amount);
            if ($currMinor < $amountMinor) {
                throw WalletException::insufficientBalance(
                    $this->minorToDecimal($currMinor),
                    $this->minorToDecimal($amountMinor)
                );
            }

            $afterMinor = $currMinor - $amountMinor;
            if ($afterMinor < 0) {
                throw WalletException::insufficientBalance(
                    $this->minorToDecimal($currMinor),
                    $this->minorToDecimal($amountMinor)
                );
            }

            $newBalance = $this->minorToDecimal($afterMinor);
            $amountDec  = $this->minorToDecimal($amountMinor);

            $this->walletRepo->updateBalance((int)$wallet->id, $newBalance);

            $txId = $this->walletRepo->createTransaction([
                'wallet_id'     => (int)$wallet->id,
                'type'          => 'debit',
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $referenceId,
                'description'   => $description,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            return (object)[
                'id'            => $txId,
                'wallet_id'     => (int)$wallet->id,
                'type'          => 'debit',
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $referenceId,
                'description'   => $description,
            ];
        });
    }

    public function reverseDebit(
        int $userId,
        string $amount,
        string $originalReferenceId,
        string $description = '',
        ?int $orderId = null
    ): object {
        $amountMinor = $this->parseAmountMinor($amount);
        if ($amountMinor <= 0) {
            throw WalletException::invalidAmount($amount);
        }

        $reversalRef = 'rev_' . $originalReferenceId;
        $existing = $this->walletRepo->getTransactionByReference($reversalRef);
        if ($existing !== null) {
            return $existing;
        }

        return $this->executeInTransaction(function () use ($userId, $amountMinor, $reversalRef, $originalReferenceId, $description, $orderId) {
            $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            if (!$wallet) {
                $wallet = $this->walletRepo->getOrCreateWallet($userId);
                $wallet = $this->walletRepo->lockWalletForUpdate($userId);
            }

            $currMinor = $this->parseAmountMinor($wallet->balance_amount);
            $afterMinor = $currMinor + $amountMinor;
            $newBalance = $this->minorToDecimal($afterMinor);
            $amountDec  = $this->minorToDecimal($amountMinor);

            $this->walletRepo->updateBalance((int)$wallet->id, $newBalance);

            $desc = $description !== '' ? $description : "Reversal for {$originalReferenceId}";

            $txId = $this->walletRepo->createTransaction([
                'wallet_id'     => (int)$wallet->id,
                'type'          => 'reversal',
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $reversalRef,
                'description'   => $desc,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            return (object)[
                'id'            => $txId,
                'wallet_id'     => (int)$wallet->id,
                'type'          => 'reversal',
                'amount'        => $amountDec,
                'balance_after' => $newBalance,
                'order_id'      => $orderId,
                'reference_id'  => $reversalRef,
                'description'   => $desc,
            ];
        });
    }

    protected function executeInTransaction(callable $callback): mixed
    {
        if ($this->db === null) {
            return $callback();
        }

        $pdo = null;
        try {
            $pdo = $this->db->getConnection();
        } catch (Throwable) {
        }

        if ($pdo !== null && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $result = $callback();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return $callback();
    }

    protected function parseAmountMinor(string $amount): int
    {
        $clean = trim($amount);
        if ($clean === '' || !is_numeric($clean)) {
            return 0;
        }

        return (int)round((float)$clean * 100);
    }

    protected function minorToDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}

