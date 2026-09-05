<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedMedia
{
    public string $sourceId = '';
    public string $sourceUrl = '';
    public ?string $filename = null;
    public ?string $title = null;
    public ?string $altText = null;
    public ?string $caption = null;
    public ?string $description = null;
    public ?string $mimeType = null;
    public ?string $localPath = null;
    public ?string $localUrl = null;
    public ?int $mediaId = null;
    public string $status = 'pending'; // pending, downloaded, skipped, failed
    public ?string $failureReason = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
