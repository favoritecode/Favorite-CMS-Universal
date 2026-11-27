<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support {

    use DateTimeImmutable;
    use DateTimeInterface;
    use DateTimeZone;
    use Throwable;

    class TimezoneHelper
    {
        /**
         * Format a stored UTC timestamp, Unix epoch, or DateTimeInterface into site timezone.
         *
         * @param mixed $datetime Stored UTC timestamp string, epoch integer, or DateTimeInterface
         * @param string $format Target PHP date format (default: 'd M Y, h:i A', e.g. '18 Sep 2026, 01:43 PM')
         * @param ?string $timezone Optional explicit IANA timezone identifier override
         * @return string
         */
        public static function format(mixed $datetime, string $format = 'd M Y, h:i A', ?string $timezone = null): string
        {
            if ($datetime === null || $datetime === '') {
                return '';
            }

            // Delegate to Core DateTime service or format_date helper if available
            if (class_exists(\FavoriteCMS\Core\DateTime::class)) {
                return \FavoriteCMS\Core\DateTime::format($datetime, $format, $timezone);
            }

            if (function_exists('format_date')) {
                return format_date($datetime, $format, $timezone);
            }

            // Resilient standalone fallback if running without Core framework loaded
            try {
                $tzString = $timezone ?? date_default_timezone_get();
                $targetTz = new DateTimeZone($tzString ?: 'UTC');

                if ($datetime instanceof DateTimeInterface) {
                    return (new DateTimeImmutable($datetime->format('Y-m-d H:i:s.u'), $datetime->getTimezone()))
                        ->setTimezone($targetTz)
                        ->format($format);
                }

                if (is_numeric($datetime)) {
                    return (new DateTimeImmutable('@' . (int)$datetime))
                        ->setTimezone($targetTz)
                        ->format($format);
                }

                $dateStr = trim((string)$datetime);
                if ($dateStr === '') {
                    return '';
                }

                if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $dateStr)) {
                    $dt = new DateTimeImmutable($dateStr);
                } else {
                    $dt = new DateTimeImmutable($dateStr, new DateTimeZone('UTC'));
                }

                return $dt->setTimezone($targetTz)->format($format);
            } catch (Throwable) {
                return is_string($datetime) ? $datetime : '';
            }
        }

        /**
         * Format as date only in site timezone (e.g. '18 Sep 2026').
         */
        public static function formatDate(mixed $datetime, string $format = 'd M Y', ?string $timezone = null): string
        {
            return self::format($datetime, $format, $timezone);
        }
    }
}

namespace {

    // Global convenience helpers for templates
    if (!function_exists('fpay_format_datetime')) {
        function fpay_format_datetime(mixed $datetime, string $format = 'd M Y, h:i A', ?string $timezone = null): string
        {
            return \FavoriteCMS\Pay\Support\TimezoneHelper::format($datetime, $format, $timezone);
        }
    }

    if (!function_exists('fpay_format_date')) {
        function fpay_format_date(mixed $datetime, string $format = 'd M Y', ?string $timezone = null): string
        {
            return \FavoriteCMS\Pay\Support\TimezoneHelper::formatDate($datetime, $format, $timezone);
        }
    }
}
