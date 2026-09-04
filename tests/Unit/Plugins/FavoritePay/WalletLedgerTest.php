<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\WalletService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WalletLedgerTest extends TestCase
{
    private CurrencyService $currencyService;
    private WalletService $walletService;

    protected function setUp(): void
    {
        $this->currencyService = new CurrencyService();
        $this->walletService = new WalletService($this->currencyService);
    }

    public function testWalletStartsAtZero(): void
    {
        $balance = $this->walletService->getBalance(10);
        $this->assertTrue($balance->isZero());
        $this->assertSame('BDT', $balance->getCurrency());
    }

    public function testDepositInBdt(): void
    {
        $entry = $this->walletService->deposit(10, Money::bdt(25000), 'DEP-101', 'Bank Deposit');
        $this->assertSame('credit', $entry->getType());
        $this->assertSame(25000, $entry->getAmount()->getAmount());
        $this->assertSame(25000, $entry->getBalanceAfter()->getAmount());

        $balance = $this->walletService->getBalance(10);
        $this->assertSame(25000, $balance->getAmount());
    }

    public function testDepositForeignCurrencyConvertsAndLocksToBdt(): void
    {
        // Set operator rate for USD -> BDT = 117.50
        $this->currencyService->setOperatorRate('USD', '117.50', 1, 'BDT');

        // Deposit $10.00 USD -> converted at 117.50 BDT = 117,500 Poisha
        $usdDeposit = Money::fromMajorString('10.00', 'USD');
        $entry = $this->walletService->deposit(20, $usdDeposit, 'DEP-USD-201');

        $this->assertSame('BDT', $entry->getAmount()->getCurrency());
        $this->assertSame(117500, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance(20);
        $this->assertSame(117500, $balance->getAmount());
    }

    public function testDebitPreventsOverdraft(): void
    {
        $this->walletService->deposit(30, Money::bdt(10000), 'DEP-301');

        // Trying to debit 150.00 BDT (15,000 Poisha) with only 100.00 BDT (10,000 Poisha) in wallet
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Insufficient wallet balance");

        $this->walletService->debit(30, Money::bdt(15000), 'PUR-301');
    }

    public function testSuccessfulDebit(): void
    {
        $this->walletService->deposit(40, Money::bdt(50000), 'DEP-401');
        $debitEntry = $this->walletService->debit(40, Money::bdt(20000), 'PUR-401');

        $this->assertSame('debit', $debitEntry->getType());
        $this->assertSame(30000, $debitEntry->getBalanceAfter()->getAmount());

        $balance = $this->walletService->getBalance(40);
        $this->assertSame(30000, $balance->getAmount());
    }

    public function testHoldAndRelease(): void
    {
        $this->walletService->deposit(50, Money::bdt(50000), 'DEP-501');

        $hold = $this->walletService->hold(50, Money::bdt(15000), 'HOLD-501');
        $this->assertSame(35000, $hold->getBalanceAfter()->getAmount());
        $this->assertSame(35000, $this->walletService->getBalance(50)->getAmount());

        $release = $this->walletService->releaseHold(50, Money::bdt(15000), 'HOLD-501');
        $this->assertSame(50000, $release->getBalanceAfter()->getAmount());
        $this->assertSame(50000, $this->walletService->getBalance(50)->getAmount());
    }
}
