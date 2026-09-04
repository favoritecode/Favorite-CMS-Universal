<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

enum PaymentMethodType: string
{
    case MANUAL_BD = 'manual_bd';
    case CARD = 'card';
    case CRYPTO = 'crypto';
    case WALLET = 'wallet';
    case OFFLINE = 'offline';

    public function requiresManualVerification(): bool
    {
        return $this === self::MANUAL_BD;
    }
}
