<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\User;

final class DraftReceipt
{
    public static function prefix(User $user): string
    {
        return 'favorite_post_draft_' . hash('sha256', site_path('/')) . '_' . (int)$user->id . '_';
    }

    public static function saved(Request $request, User $user): void
    {
        $key = (string)$request->post('_draft_key', '');
        $revision = (string)$request->post('_draft_snapshot', '');
        $prefix = self::prefix($user);
        if (str_starts_with($key, $prefix) && preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', substr($key, strlen($prefix))) && preg_match('/^[a-f0-9]{32}$/D', $revision)) {
            $_SESSION['draft_receipts'][] = ['key' => $key, 'revision' => $revision];
            $_SESSION['draft_receipts'] = array_slice($_SESSION['draft_receipts'], -40);
        }
    }
}
