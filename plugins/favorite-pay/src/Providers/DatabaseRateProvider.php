<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Providers;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;

/**
 * Retrieves authoritative exchange rates from favorite_pay_rates database table.
 * Supports active/retired/inactive status filtering and rate lifecycle management.
 */
class DatabaseRateProvider implements ExchangeRateProviderInterface
{
    private Database $db;
    private string $providerId;

    public function __construct(Database $db, string $providerId = 'database')
    {
        $this->db = $db;
        $this->providerId = $providerId;
    }

    public function getDatabase(): Database
    {
        return $this->db;
    }

    public function getRate(string $fromCurrency, string $toCurrency): ?ConversionSnapshot
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return null;
        }

        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        $now = date('Y-m-d H:i:s');

        $row = $this->db->selectOne(
            "SELECT * FROM favorite_pay_rates 
             WHERE base_currency = ? 
               AND quote_currency = ? 
               AND is_authoritative = 1 
               AND (status = 'active' OR status IS NULL)
               AND effective_at <= ? 
               AND (expires_at IS NULL OR expires_at > ?) 
             ORDER BY effective_at DESC, id DESC 
             LIMIT 1",
            [$from, $to, $now, $now]
        );

        if (!$row) {
            return null;
        }

        $row = (array)$row;

        return new ConversionSnapshot(
            (string)$row['base_currency'],
            (string)$row['quote_currency'],
            (int)$row['rate_factor'],
            (int)($row['rate_scale'] ?? ConversionSnapshot::DEFAULT_SCALE),
            (bool)$row['is_authoritative'],
            $row['effective_at'] ?? $row['created_at'] ?? null,
            $row['expires_at'] ?? null,
            (string)($row['source'] ?? 'database')
        );
    }

    public function hasRate(string $fromCurrency, string $toCurrency): bool
    {
        return $this->getRate($fromCurrency, $toCurrency) !== null;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    /**
     * Retrieve all rates with audit metadata, ordered by newest effective_at.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRates(int $limit = 100): array
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return [];
        }

        $rows = $this->db->select(
            "SELECT * FROM favorite_pay_rates 
             ORDER BY effective_at DESC, id DESC 
             LIMIT " . (int)$limit
        );
        return array_map(fn($r) => (array)$r, $rows);
    }

    /**
     * Get single rate by ID.
     */
    public function getRateById(int $id): ?array
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return null;
        }

        $row = $this->db->selectOne(
            "SELECT * FROM favorite_pay_rates WHERE id = ? LIMIT 1",
            [$id]
        );
        return $row ? (array)$row : null;
    }

    /**
     * Retire currently active rates for a given currency pair so new rate window does not overlap.
     * Preserves historical records by updating status and expiration rather than deleting.
     */
    public function retireActiveRates(string $baseCurrency, string $quoteCurrency, string $retiredAt): int
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return 0;
        }

        $from = strtoupper(trim($baseCurrency));
        $to = strtoupper(trim($quoteCurrency));

        try {
            $stmt = $this->db->query(
                "UPDATE favorite_pay_rates 
                 SET status = 'retired', expires_at = ? 
                 WHERE base_currency = ? 
                   AND quote_currency = ? 
                   AND is_authoritative = 1 
                   AND (status = 'active' OR status IS NULL)
                   AND (expires_at IS NULL OR expires_at > ?)",
                [$retiredAt, $from, $to, $retiredAt]
            );
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Manually deactivate/retire a specific rate.
     */
    public function deactivateRate(int $id, int $operatorId, ?string $retiredAt = null): bool
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return false;
        }

        $now = $retiredAt ?? date('Y-m-d H:i:s');
        try {
            $stmt = $this->db->query(
                "UPDATE favorite_pay_rates 
                 SET status = 'inactive', expires_at = ? 
                 WHERE id = ?",
                [$now, $id]
            );
            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Insert a new operator rate record.
     */
    public function insertRate(array $data): int
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return 0;
        }

        $this->db->insert('favorite_pay_rates', $data);
        return (int)$this->db->lastInsertId();
    }
}
