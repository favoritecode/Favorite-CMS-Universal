<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Media extends BaseModel
{
    protected static string $table = 'media';

    public function isImage(): bool
    {
        return strpos($this->mime_type ?? '', 'image/') === 0;
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

