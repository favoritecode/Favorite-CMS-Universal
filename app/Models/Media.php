<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Media extends BaseModel
{
    protected static string $table = 'media';

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

