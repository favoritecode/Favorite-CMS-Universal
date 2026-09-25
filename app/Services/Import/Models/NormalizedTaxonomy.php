<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedTaxonomy
{
    public string $name = '';
    public string $slug = '';
    public string $type = 'category'; // 'category' or 'tag'
    public ?string $description = null;
    public ?string $parentSlug = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
