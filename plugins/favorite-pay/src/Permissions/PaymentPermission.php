<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Permissions;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;

final class PaymentPermission
{
    public const VIEW = 'favorite_pay.payments.view';
    public const VERIFY = 'favorite_pay.payments.verify';

    /**
     * Check if a user has permission to view payment verification queue and details.
     */
    public static function canView(?User $user = null): bool
    {
        if ($user === null && function_exists('current_user')) {
            $user = current_user();
        }

        if (!$user || !$user->isActive()) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasPermission(self::VIEW);
    }

    /**
     * Check if a user has permission to approve or reject manual payment attempts.
     */
    public static function canVerify(?User $user = null): bool
    {
        if ($user === null && function_exists('current_user')) {
            $user = current_user();
        }

        if (!$user || !$user->isActive()) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasPermission(self::VERIFY);
    }

    /**
     * Register permission records in Core permissions table if available.
     */
    public static function registerDefaultPermissions(?Database $db): void
    {
        if ($db === null || !$db->tableExists('permissions')) {
            return;
        }

        $definitions = [
            [
                'name'        => 'View Favorite Pay',
                'slug'        => self::VIEW,
                'description' => 'View Favorite Pay payment verification queue and transaction details',
                'group_name'  => 'payment',
            ],
            [
                'name'        => 'Verify Favorite Pay',
                'slug'        => self::VERIFY,
                'description' => 'Approve or reject Favorite Pay manual payment verification requests',
                'group_name'  => 'payment',
            ],
        ];

        foreach ($definitions as $def) {
            $exists = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [$def['slug']]);
            if (!$exists) {
                $db->insert('permissions', $def);
            }
        }
    }
}
