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

    public const MANAGE_RATES = 'favorite_pay.rates.manage';

    /**
     * Check if a user has permission to configure and manage authoritative exchange rates.
     */
    public static function canManageRates(?User $user = null): bool
    {
        if ($user === null && function_exists('current_user')) {
            $user = current_user();
        }

        if (!$user || !$user->isActive()) {
            return false;
        }

        if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
            return true;
        }

        return $user->hasPermission(self::MANAGE_RATES) || $user->hasPermission('manage_settings');
    }

    public const MANAGE_SETTINGS = 'favorite_pay.settings.manage';

    /**
     * Check if a user has permission to configure payment gateways and settings.
     */
    public static function canManageSettings(?User $user = null): bool
    {
        if ($user === null && function_exists('current_user')) {
            $user = current_user();
        }

        if (!$user || !$user->isActive()) {
            return false;
        }

        if ($user->hasRole('super-admin') || $user->hasRole('admin')) {
            return true;
        }

        return $user->hasPermission('manage_settings') || $user->hasPermission(self::MANAGE_SETTINGS);
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
            [
                'name'        => 'Manage Favorite Pay Rates',
                'slug'        => self::MANAGE_RATES,
                'description' => 'Configure and manage authoritative exchange rates in Favorite Pay',
                'group_name'  => 'payment',
            ],
            [
                'name'        => 'Manage Favorite Pay Settings',
                'slug'        => self::MANAGE_SETTINGS,
                'description' => 'Configure Favorite Pay payment gateways and merchant credentials',
                'group_name'  => 'payment',
            ],
        ];

        foreach ($definitions as $def) {
            $exists = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [$def['slug']]);
            if (!$exists) {
                $db->insert('permissions', $def);
            }
        }

        // Link default payment permissions to the Admin role if tables exist
        if ($db->tableExists('roles') && $db->tableExists('role_permissions')) {
            $adminRole = $db->selectOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
            if ($adminRole) {
                foreach ($definitions as $def) {
                    $perm = $db->selectOne("SELECT id FROM permissions WHERE slug = ? LIMIT 1", [$def['slug']]);
                    if ($perm) {
                        $rpExists = $db->selectOne(
                            "SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1",
                            [$adminRole->id, $perm->id]
                        );
                        if (!$rpExists) {
                            $db->insert('role_permissions', [
                                'role_id'       => $adminRole->id,
                                'permission_id' => $perm->id,
                            ]);
                        }
                    }
                }
            }
        }
    }
}
