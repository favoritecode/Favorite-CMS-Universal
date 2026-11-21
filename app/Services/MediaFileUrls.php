<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use FavoriteCMS\Core\{Container,Database,Request,Response,DownloadResponse};
use FavoriteCMS\Models\{Media,Setting};

/** Stable public labels; original storage paths and existing URLs stay intact. */
final class MediaFileUrls
{
    private static function database(): Database { return Container::getInstance()->get(Database::class); }
    private static function key(string $name): string { return hash('sha256', mb_strtolower($name, 'UTF-8')); }
    private static function name(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        if (str_starts_with($name, '.')) $name = 'file' . $name;
        return $name !== '' ? $name : 'file';
    }
    private static function localPath(Media $media): ?array
    {
        $path = realpath((string)$media->path);
        if (!$path || !is_file($path)) return null;
        foreach (['public/uploads' => false, 'storage/media-downloads' => true] as $directory => $attachment) {
            $base = realpath(APP_ROOT . '/' . $directory);
            if ($base && str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $base), '/') . '/')) return [$path, $attachment];
        }
        return null;
    }
    public static function url(Media $media): string
    {
        $original = $media->storageUrl();
        if (!self::localPath($media)) return $original;
        $known = Setting::get('media_file_urls', (string)$media->id, '');
        if (is_string($known) && $known !== '') return site_path($known);
        try { $assigned = self::assign((int)$media->id); return $assigned !== '' ? site_path($assigned) : $original; }
        catch (\Throwable $error) { error_log('Favorite CMS file URL skipped: ' . $error->getMessage()); return $original; }
    }
    public static function assign(int $id): string
    {
        $db = self::database();
        return $db->transaction(function () use ($db, $id): string {
            // Serialize name reservations, including concurrent uploads on MySQL.
            $driver = $db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $insert = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $db->execute($insert . ' INTO `settings` (`group_name`,`setting_key`,`value`,`type`,`is_public`) VALUES (?,?,?,?,?)', ['media_file_urls', '_lock', '1', 'string', 0]);
            if ($driver === 'mysql') $db->selectOne('SELECT id FROM `settings` WHERE group_name=? AND setting_key=? FOR UPDATE', ['media_file_urls', '_lock']);
            Setting::clearCache();
            $known = Setting::get('media_file_urls', (string)$id, '');
            if (is_string($known) && $known !== '') return $known;
            $target = Media::find($id);
            if (!$target || !self::localPath($target)) return '';
            // A new file must not claim an address already owned by admin rules
            // or a literal public plugin route. Later intentional redirects still win.
            $occupiedRoutes = [];
            foreach (UrlRedirects::rules() as $rule) {
                $occupiedRoutes[rawurldecode((string)$rule['source'])] = true;
            }
            foreach (\FavoriteCMS\Core\Router::getRoutes() as $route) {
                if (in_array($route['method'], ['GET', 'HEAD', 'ANY'], true)
                    && !str_contains($route['path'], '{')) {
                    $occupiedRoutes[rawurldecode($route['path'])] = true;
                }
            }
            // Earlier existing uploads get the earlier suffix, regardless of view order.
            $rows = $db->select('SELECT * FROM `media` WHERE filename=? AND id<=? ORDER BY id ASC', [(string)$target->filename, $id]);
            foreach ($rows as $row) {
                $item = new Media((array)$row);
                if (!self::localPath($item) || Setting::get('media_file_urls', (string)$item->id, '') !== '') continue;
                $name = self::name((string)$item->filename);
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $stem = $extension !== '' ? substr($name, 0, -strlen($extension) - 1) : $name;
                $key = self::key($name);
                $next = max(0, (int)Setting::get('media_file_url_counters', $key, 0));
                do {
                    $candidate = $stem . ($next > 0 ? '-' . $next : '') . ($extension !== '' ? '.' . $extension : '');
                    $next++;
                    $path = '/' . rawurlencode($candidate);
                    $pathKey = self::key($candidate);
                    $occupied = isset($occupiedRoutes['/' . $candidate])
                        || Setting::get('media_file_url_paths', $pathKey, '') !== ''
                        || UrlRedirects::reserved($path)
                        || file_exists(APP_ROOT . '/public/' . $candidate)
                        || \FavoriteCMS\Models\Page::findBySlug($candidate) !== null
                        || \FavoriteCMS\Models\Post::findBySlug($candidate) !== null;
                } while ($occupied);
                // Reservations survive deletion: old URLs must never point at new files.
                Setting::set('media_file_url_paths', $pathKey, json_encode(['id' => (int)$item->id, 'name' => $candidate], JSON_UNESCAPED_UNICODE));
                Setting::set('media_file_urls', (string)$item->id, $path);
                Setting::set('media_file_url_counters', $key, $next, 'int');
            }
            return (string)Setting::get('media_file_urls', (string)$id, '');
        });
    }
    public static function response(Request $request): ?Response
    {
        if (substr_count($request->path(), '/') !== 1) return null;
        $name = rawurldecode(substr($request->path(), 1));
        if ($name === '' || self::name($name) !== $name) return null;
        $stored = Setting::get('media_file_url_paths', self::key($name), '');
        if (!is_string($stored) || $stored === '') return null;
        $alias = json_decode($stored, true);
        if (!is_array($alias) || ($alias['name'] ?? '') !== $name) return null;
        if (!in_array($request->method(), ['GET','HEAD'], true)) return Response::make('',405)->header('Allow','GET, HEAD');
        $media = Media::find((int)($alias['id'] ?? 0));
        $local = $media ? self::localPath($media) : null;
        if (!$media || !$local) return Response::make('File not found.',404);
        $response = new DownloadResponse($local[0], (string)$alias['name'], $request);
        if ($local[1]) return $response;
        $mime = (string)$media->mime_type;
        if (preg_match('#^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$#D', $mime)) $response->header('Content-Type', $mime);
        if (preg_match('#^(?:image|video|audio)/#', $mime) || in_array($mime, ['application/pdf','text/plain'], true)) {
            $response->header('Content-Disposition', preg_replace('/^attachment;/', 'inline;', $response->getHeader('Content-Disposition')));
        }
        return $response;
    }
}
