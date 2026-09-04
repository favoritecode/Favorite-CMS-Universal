<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

enum PaymentMethodType: string
{
    case MANUAL_BD = 'manual_bd';
    case MANUAL_BKASH = 'manual_bkash';
    case MANUAL_NAGAD = 'manual_nagad';
    case MANUAL_BANK = 'manual_bank';
    case MANUAL_ROCKET = 'manual_rocket';
    case BKASH = 'bkash';
    case BINANCE = 'binance';
    case CARD = 'card';
    case CRYPTO = 'crypto';
    case WALLET = 'wallet';
    case OFFLINE = 'offline';

    public function requiresManualVerification(): bool
    {
        return in_array($this, [
            self::MANUAL_BD,
            self::MANUAL_BKASH,
            self::MANUAL_NAGAD,
            self::MANUAL_ROCKET,
            self::MANUAL_BANK,
        ], true);
    }
}
