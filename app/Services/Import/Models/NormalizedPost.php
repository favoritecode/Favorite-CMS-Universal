<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedPost
{
    public string $sourceId = '';
    public string $sourceGuid = '';
    public string $sourceUrl = '';
    public string $title = '';
    public string $slug = '';
    public string $content = '';
    public string $excerpt = '';
    public string $status = 'published'; // published, draft, pending, etc.
    public ?string $authorName = null;
    public ?string $authorEmail = null;
    public ?string $authorUsername = null;
    public ?string $publishedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    /** @var string[] */
    public array $categories = [];
    /** @var string[] */
    public array $tags = [];
    public ?string $featuredImageUrl = null;
    /** @var string[] */
    public array $inlineMediaUrls = [];
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
