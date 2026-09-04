<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Exceptions;

use DomainException;

/**
 * Thrown when an exchange rate is missing, unauthoritative, expired, or otherwise invalid
 * for processing real payments.
 */
class UnauthoritativeRateException extends DomainException
{
    private string $fromCurrency;
    private string $toCurrency;

    public function __construct(
        string $message,
        string $fromCurrency = '',
        string $toCurrency = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->fromCurrency = strtoupper(trim($fromCurrency));
        $this->toCurrency = strtoupper(trim($toCurrency));
    }

    public function getFromCurrency(): string
    {
        return $this->fromCurrency;
    }

    public function getToCurrency(): string
    {
        return $this->toCurrency;
    }
}
