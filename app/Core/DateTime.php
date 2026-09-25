<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FavoriteCMS\Models\Setting;
use Throwable;

class DateTime
{
    /**
     * Cache supported IANA timezone identifiers.
     */
    private static ?array $supportedIdentifiers = null;

    /**
     * Retrieve the active site timezone identifier (e.g. 'UTC', 'Asia/Dhaka').
     */
    public static function getTimezone(): string
    {
        try {
            $tz = class_exists(Setting::class) ? (string)Setting::get('general', 'timezone', '') : '';
            if ($tz !== '' && self::isValidTimezone($tz)) {
                return $tz;
            }
        } catch (Throwable) {
            // Setting service unavailable (e.g. during installer/early boot)
        }

        $configTz = (string)config('app.timezone', 'UTC');
        return self::isValidTimezone($configTz) ? $configTz : 'UTC';
    }

    /**
     * Retrieve a DateTimeZone object for the active site timezone.
     */
    public static function getTimezoneObject(?string $timezone = null): DateTimeZone
    {
        $tzString = $timezone ?? self::getTimezone();
        try {
            return new DateTimeZone($tzString);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * Validate an IANA timezone identifier against supported PHP DateTimeZones.
     */
    public static function isValidTimezone(string $timezone): bool
    {
        if (trim($timezone) === '') {
            return false;
        }

        if (self::$supportedIdentifiers === null) {
            self::$supportedIdentifiers = array_fill_keys(DateTimeZone::listIdentifiers(), true);
        }

        return isset(self::$supportedIdentifiers[$timezone]);
    }

    /**
     * Set the site timezone. Returns true on success, false if identifier is invalid.
     */
    public static function setTimezone(string $timezone): bool
    {
        $trimmed = trim($timezone);
        if (!self::isValidTimezone($trimmed)) {
            return false;
        }

        Setting::set('general', 'timezone', $trimmed);
        return true;
    }

    /**
     * Format a stored UTC timestamp, Unix epoch integer, or DateTimeInterface into the site timezone.
     *
     * @param string|int|DateTimeInterface|null $datetime Stored timestamp (assumed UTC if string/epoch)
     * @param string $format Target date() format string
     * @param ?string $timezone Optional override timezone identifier
     */
    public static function format(mixed $datetime, string $format = 'Y-m-d H:i:s', ?string $timezone = null): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $targetTz = self::getTimezoneObject($timezone);

        try {
            if ($datetime instanceof DateTimeInterface) {
                // If it's already a DateTime object, convert to target timezone
                $dt = (new DateTimeImmutable($datetime->format('Y-m-d H:i:s.u'), $datetime->getTimezone()))
                    ->setTimezone($targetTz);
                return $dt->format($format);
            }

            if (is_numeric($datetime)) {
                // Epoch timestamp (always UTC in Unix time)
                $dt = (new DateTimeImmutable('@' . (int)$datetime))->setTimezone($targetTz);
                return $dt->format($format);
            }

            $dateStr = trim((string)$datetime);
            if ($dateStr === '') {
                return '';
            }

            // If string ends with 'Z' or has explicit timezone offset (e.g. ISO 8601)
            if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $dateStr)) {
                $dt = new DateTimeImmutable($dateStr);
            } else {
                // Default database timestamps (e.g. '2026-09-10 12:00:00') are stored in UTC
                $dt = new DateTimeImmutable($dateStr, new DateTimeZone('UTC'));
            }

            return $dt->setTimezone($targetTz)->format($format);
        } catch (Throwable) {
            // Fallback for corrupt/unparseable strings
            return is_string($datetime) ? $datetime : '';
        }
    }

    /**
     * Return the current time as a DateTimeImmutable in the target or site timezone.
     */
    public static function now(?string $timezone = null): DateTimeImmutable
    {
        $tz = self::getTimezoneObject($timezone);
        return new DateTimeImmutable('now', $tz);
    }

    /**
     * Get a list of IANA timezone identifiers grouped by geographical region.
     *
     * @return array<string, array<string, string>>
     */
    public static function listTimezones(): array
    {
        $all = DateTimeZone::listIdentifiers();
        $grouped = [];

        foreach ($all as $tz) {
            $parts = explode('/', $tz, 2);
            $group = count($parts) > 1 ? $parts[0] : 'General';
            $city = count($parts) > 1 ? str_replace('_', ' ', $parts[1]) : $tz;

            try {
                $now = new DateTimeImmutable('now', new DateTimeZone($tz));
                $offset = $now->format('P'); // e.g. +06:00, -05:00, +00:00
                $label = "(UTC{$offset}) {$city}";
            } catch (Throwable) {
                $label = $city;
            }

            $grouped[$group][$tz] = $label;
        }

        // Sort groups with General/UTC first, then alphabetically
        ksort($grouped);
        if (isset($grouped['General'])) {
            $general = $grouped['General'];
            unset($grouped['General']);
            $grouped = ['General' => $general] + $grouped;
        }

        return $grouped;
    }
}