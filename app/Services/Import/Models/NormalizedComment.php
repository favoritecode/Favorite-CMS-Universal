<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedComment
{
    public string $sourceId = '';
    public ?string $postSourceId = null;
    public ?string $parentSourceId = null;
    public string $authorName = 'Anonymous';
    public string $authorEmail = 'anonymous@example.com';
    public ?string $authorUrl = null;
    public ?string $authorIp = null;
    public string $content = '';
    public string $status = 'approved'; // approved, pending, spam, trash
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    /** @var array<string, mixed> */
    public array $metadata = [];

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
