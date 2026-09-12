<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Media extends BaseModel
{
    protected static string $table = 'media';

    /**
     * A bounded page of media, newest first, filtered by type category (same rules as getTypeCategory())
     * and by a filename/title search.
     */
    public static function filtered(string $category = 'all', string $search = '', int $limit = 12, int $offset = 0): array
    {
        [$where, $params] = static::filterSql($category, $search);
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT * FROM `media` {$where} ORDER BY `id` DESC LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)])
        );
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    /**
     * Total number of media records matching the same filters as filtered().
     */
    public static function countFiltered(string $category = 'all', string $search = ''): int
    {
        [$where, $params] = static::filterSql($category, $search);
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $row = $db->selectOne("SELECT COUNT(*) AS cnt FROM `media` {$where}", $params);
        return (int)($row->cnt ?? 0);
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    protected static function filterSql(string $category, string $search): array
    {
        $image    = "`mime_type` LIKE 'image/%'";
        $video    = "`mime_type` LIKE 'video/%'";
        $audio    = "`mime_type` LIKE 'audio/%'";
        $document = "(`mime_type` LIKE '%pdf%' OR `mime_type` LIKE '%word%' OR `mime_type` LIKE '%excel%' OR `mime_type` LIKE '%spreadsheet%' OR `mime_type` LIKE '%powerpoint%' OR `mime_type` LIKE '%presentation%' OR `mime_type` LIKE 'text/%')";
        $archive  = "(`mime_type` LIKE '%zip%' OR `mime_type` LIKE '%tar%' OR `mime_type` LIKE '%gzip%' OR `mime_type` LIKE '%compressed%')";

        // Mirrors the precedence of getTypeCategory(): image, video, audio, document, archive, other
        $condition = match ($category) {
            'all'      => '1=1',
            'image'    => $image,
            'video'    => $video,
            'audio'    => $audio,
            'document' => "NOT ({$image} OR {$video} OR {$audio}) AND {$document}",
            'archive'  => "NOT ({$image} OR {$video} OR {$audio} OR {$document}) AND {$archive}",
            'other'    => "NOT ({$image} OR {$video} OR {$audio} OR {$document} OR {$archive})",
            default    => '1=0',
        };

        $where = 'WHERE ' . $condition;
        $params = [];
        if ($search !== '') {
            $pattern = '%' . addcslashes($search, '\\%_') . '%';
            $where .= ' AND (`filename` LIKE ? OR `title` LIKE ?)';
            $params = [$pattern, $pattern];
        }

        return [$where, $params];
    }

    /**
     * JSON-safe representation used by media pickers.
     */
    public function toPickerArray(): array
    {
        return [
            'id'             => (int)$this->id,
            'filename'       => (string)$this->filename,
            'url'            => (string)$this->url,
            'mime_type'      => (string)$this->mime_type,
            'size'           => (int)$this->size,
            'formatted_size' => $this->getFormattedSize(),
            'is_image'       => $this->isImage(),
            'is_video'       => $this->isVideo(),
            'is_audio'       => $this->isAudio(),
            'is_document'    => $this->isDocument(),
            'category'       => $this->getTypeCategory(),
            'width'          => $this->width,
            'height'         => $this->height,
            'alt_text'       => $this->alt_text ?: $this->filename,
            'title'          => $this->title ?: $this->filename,
        ];
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'audio/');
    }

    public function isDocument(): bool
    {
        $mime = $this->mime_type ?? '';
        return str_contains($mime, 'pdf') 
            || str_contains($mime, 'word') 
            || str_contains($mime, 'excel') 
            || str_contains($mime, 'spreadsheet') 
            || str_contains($mime, 'powerpoint') 
            || str_contains($mime, 'presentation') 
            || str_starts_with($mime, 'text/');
    }

    public function isArchive(): bool
    {
        $mime = $this->mime_type ?? '';
        return str_contains($mime, 'zip') || str_contains($mime, 'tar') || str_contains($mime, 'gzip') || str_contains($mime, 'compressed');
    }

    public function getTypeCategory(): string
    {
        if ($this->isImage()) return 'image';
        if ($this->isVideo()) return 'video';
        if ($this->isAudio()) return 'audio';
        if ($this->isDocument()) return 'document';
        if ($this->isArchive()) return 'archive';
        return 'other';
    }

    public function getFormattedSize(): string
    {
        $bytes = (int)($this->size ?? 0);
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    public function getThumbnailUrl(int $width, int $height): string
    {
        // Dummy implementation for thumbnail generation
        return $this->url ?? '';
    }

    public function delete(): bool
    {
        if ($this->path && file_exists($this->path)) {
            unlink($this->path);
        }
        return parent::delete();
    }
}

