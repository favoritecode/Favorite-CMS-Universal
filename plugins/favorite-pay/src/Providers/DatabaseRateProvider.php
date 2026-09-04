<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Providers;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;

/**
 * Retrieves authoritative exchange rates from favorite_pay_rates database table.
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
               AND effective_at <= ? 
               AND (expires_at IS NULL OR expires_at > ?) 
             ORDER BY effective_at DESC, id DESC 
             LIMIT 1",
            [$from, $to, $now, $now]
        );

        if (!$row) {
            return null;
        }

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
}
