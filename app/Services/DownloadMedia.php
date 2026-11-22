<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use FavoriteCMS\Core\{Container,Database,Request,Response,DownloadResponse};
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Models\{Media,User};

/** Download-only formats are stored outside the public document root, never as executable paths. */
final class DownloadMedia
{
    public const EXTENSIONS = ['exe','msi','apk','bat','cmd','dmg','deb','rpm','epub','mobi','azw','azw3','rtf','odt','ods','odp','7z','rar'];
    public static function validate(string $path, string $extension, ?User $user): string
    {
        $software = in_array($extension, ['exe','msi','apk','bat','cmd','dmg','deb','rpm'], true);
        if ($software && (!$user || !($user->isSuperAdmin() || $user->hasRole('admin')))) {
            throw new SecurityException('Only administrators can upload software and command files.');
        }
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException('Unable to inspect upload.');
        try { $head = (string)fread($handle, 512); } finally { fclose($handle); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $valid = match ($extension) {
            'exe' => str_starts_with($head, 'MZ'),
            'msi' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'bat','cmd' => in_array($mime, ['text/plain','text/x-msdos-batch','application/x-bat','application/x-msdos-program'], true) && !str_contains($head, "\0"),
            'apk','epub','odt','ods','odp' => self::validZip($path, $extension),
            'mobi','azw','azw3' => substr($head, 60, 8) === 'BOOKMOBI',
            'rtf' => str_starts_with($head, '{\\rtf'),
            '7z' => str_starts_with($head, "7z\xBC\xAF\x27\x1C"),
            'rar' => str_starts_with($head, "Rar!\x1A\x07"),
            'deb' => str_starts_with($head, "!<arch>\n"),
            'rpm' => str_starts_with($head, "\xED\xAB\xEE\xDB"),
            'dmg' => self::validDmg($path),
            default => false,
        };
        if (!$valid) throw new \InvalidArgumentException('The uploaded content does not match the .' . $extension . ' format.');
        // BAT/CMD are deliberately not text/plain: they must never render inline.
        return match ($extension) {
            'epub' => 'application/epub+zip',
            'mobi','azw','azw3' => 'application/x-mobipocket-ebook',
            'rtf' => 'application/rtf',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            '7z' => 'application/x-7z-compressed',
            'rar' => 'application/vnd.rar',
            'apk' => 'application/vnd.android.package-archive',
            default => 'application/octet-stream',
        };
    }
    private static function validZip(string $path, string $extension): bool
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP ZIP extension is required for this format.');
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return false;
        try {
            if ($extension === 'apk') return $zip->locateName('AndroidManifest.xml') !== false;
            $expected = match ($extension) {
                'epub' => 'application/epub+zip',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
            };
            $entry = $zip->statName('mimetype');
            return $entry && $entry['size'] < 128 && $zip->getFromName('mimetype', 128) === $expected;
        } finally { $zip->close(); }
    }
    private static function validDmg(string $path): bool
    {
        $f = fopen($path, 'rb');
        if (!$f) return false;
        try { return fseek($f, -512, SEEK_END) === 0 && fread($f, 4) === 'koly'; }
        finally { fclose($f); }
    }
    public static function response(Request $request, int $id): Response
    {
        if (!in_array($request->method(), ['GET','HEAD'], true)) return Response::make('',405)->header('Allow','GET, HEAD');
        $media = Media::find($id);
        $base = realpath(APP_ROOT . '/storage/media-downloads');
        $path = $media ? realpath((string)$media->path) : false;
        if (!$base || !$path || !is_file($path) || !str_starts_with(str_replace('\\','/',$path), rtrim(str_replace('\\','/',$base),'/').'/')) {
            return Response::make('Download not found.',404);
        }
        return new DownloadResponse($path, (string)$media->filename, $request);
    }
}
