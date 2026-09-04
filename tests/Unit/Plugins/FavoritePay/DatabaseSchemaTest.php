<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseSchemaTest extends TestCase
{
    private Database $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        // Use an isolated in-memory SQLite PDO for fast, zero-dependency schema testing
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        // Wrap PDO in a Core Database adapter
        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
    }

    public function testMigrationUpCreatesAllSevenTables(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        $expectedTables = [
            'favorite_pay_gateways',
            'favorite_pay_rates',
            'favorite_pay_transactions',
            'favorite_pay_attempts',
            'favorite_pay_refunds',
            'favorite_pay_wallets',
            'favorite_pay_wallet_entries',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                $this->db->tableExists($table),
                "Failed asserting that table '{$table}' exists after migration up."
            );
        }
    }

    public function testExpectedColumnsInTransactionsTable(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        $cols = $this->getTableColumns('favorite_pay_transactions');

        $this->assertContains('id', $cols);
        $this->assertContains('transaction_id', $cols);
        $this->assertContains('source_plugin', $cols);
        $this->assertContains('source_reference', $cols);
        $this->assertContains('user_id', $cols);
        $this->assertContains('base_amount', $cols);
        $this->assertContains('base_currency', $cols);
        $this->assertContains('charge_amount', $cols);
        $this->assertContains('charge_currency', $cols);
        $this->assertContains('exchange_rate', $cols);
        $this->assertContains('rate_factor', $cols);
        $this->assertContains('rate_scale', $cols);
        $this->assertContains('payment_method_type', $cols);
        $this->assertContains('gateway_id', $cols);
        $this->assertContains('status', $cols);
        $this->assertContains('idempotency_key', $cols);
        $this->assertContains('external_reference', $cols);
        $this->assertContains('failure_reason', $cols);
        $this->assertContains('completed_at', $cols);
        $this->assertContains('created_at', $cols);
        $this->assertContains('updated_at', $cols);
    }

    public function testMonetaryColumnsAreIntegerTypes(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        // Insert integer minor units
        $this->db->insert('favorite_pay_transactions', [
            'transaction_id'   => 'pi_test_101',
            'source_plugin'    => 'favorite-digital',
            'source_reference' => 'ORDER-101',
            'base_amount'      => 12050, // 120.50 BDT in Poisha
            'base_currency'    => 'BDT',
            'charge_amount'    => 12050,
            'charge_currency'  => 'BDT',
            'exchange_rate'    => 1.000000,
            'status'           => 'pending',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $row = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = 'pi_test_101'");
        $this->assertNotNull($row);
        $this->assertSame(12050, (int)$row->base_amount);
        $this->assertSame(12050, (int)$row->charge_amount);
    }

    public function testWalletLedgerCanSafelyRecordCreditAndDebit(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        // 1. Create wallet
        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => 42,
            'balance'    => 50000, // 500.00 BDT
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = 42");
        $this->assertNotNull($wallet);
        $this->assertSame(50000, (int)$wallet->balance);

        // 2. Append ledger entry for deposit
        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_dep_01',
            'wallet_id'       => $wallet->id,
            'user_id'         => 42,
            'type'            => 'credit',
            'amount'          => 50000,
            'balance_after'   => 50000,
            'reference_type'  => 'deposit',
            'reference_id'    => 'DEP-001',
            'idempotency_key' => 'IDEM-DEP-001',
            'description'     => 'Initial deposit',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // 3. Append ledger entry for debit (purchase)
        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_deb_02',
            'wallet_id'       => $wallet->id,
            'user_id'         => 42,
            'type'            => 'debit',
            'amount'          => 20000,
            'balance_after'   => 30000,
            'reference_type'  => 'purchase',
            'reference_id'    => 'ORD-999',
            'idempotency_key' => 'IDEM-PUR-001',
            'description'     => 'Digital purchase',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $entries = $this->db->select("SELECT * FROM favorite_pay_wallet_entries WHERE user_id = 42 ORDER BY id ASC");
        $this->assertCount(2, $entries);
        $this->assertSame('credit', $entries[0]->type);
        $this->assertSame(50000, (int)$entries[0]->amount);
        $this->assertSame('debit', $entries[1]->type);
        $this->assertSame(20000, (int)$entries[1]->amount);
        $this->assertSame(30000, (int)$entries[1]->balance_after);
    }

    public function testDuplicateIdempotencyProtectionOnWalletEntries(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => 99,
            'balance'    => 10000,
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = 99");

        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_unique_1',
            'wallet_id'       => $wallet->id,
            'user_id'         => 99,
            'type'            => 'credit',
            'amount'          => 10000,
            'balance_after'   => 10000,
            'reference_type'  => 'deposit',
            'reference_id'    => 'DEP-99',
            'idempotency_key' => 'KEY-SAME-123',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Duplicate idempotency key must be rejected by unique constraint
        $this->expectException(\Throwable::class);
        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_unique_2',
            'wallet_id'       => $wallet->id,
            'user_id'         => 99,
            'type'            => 'credit',
            'amount'          => 10000,
            'balance_after'   => 20000,
            'reference_type'  => 'deposit',
            'reference_id'    => 'DEP-99-DUPE',
            'idempotency_key' => 'KEY-SAME-123',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function testDuplicateTrxIdProtectionOnSameGateway(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => 'att_01',
            'transaction_id'     => 'pi_tx_01',
            'gateway_id'         => 'bkash_manual',
            'amount'             => 50000,
            'currency'           => 'BDT',
            'status'             => 'awaiting_verification',
            'provider_reference' => 'TRX_SAME_123',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Attempting to reuse identical TrxID under bkash_manual must trigger unique violation
        $this->expectException(\Throwable::class);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => 'att_02',
            'transaction_id'     => 'pi_tx_02',
            'gateway_id'         => 'bkash_manual',
            'amount'             => 50000,
            'currency'           => 'BDT',
            'status'             => 'awaiting_verification',
            'provider_reference' => 'TRX_SAME_123',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    public function testMigratorCanRunAndRecordPluginMigration(): void
    {
        $migrator = new Migrator($this->db);
        $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $this->assertContains('001_create_favorite_pay_tables', $applied);
        $this->assertTrue($migrator->hasRun('001_create_favorite_pay_tables'));

        // Running again should do nothing (idempotent)
        $reapplied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');
        $this->assertEmpty($reapplied);
    }

    public function testMigrationDownDropsAllSevenTables(): void
    {
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();
        $this->assertTrue($this->db->tableExists('favorite_pay_transactions'));

        $migration->down();
        $this->assertFalse($this->db->tableExists('favorite_pay_transactions'));
        $this->assertFalse($this->db->tableExists('favorite_pay_wallets'));
        $this->assertFalse($this->db->tableExists('favorite_pay_rates'));
    }

    private function getTableColumns(string $table): array
    {
        $rows = $this->db->select("PRAGMA table_info(`{$table}`)");
        return array_map(fn($row) => $row->name, $rows);
    }
}
