<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedAuthor
{
    public string $sourceId = '';
    public string $name = '';
    public ?string $username = null;
    public ?string $email = null;
    public ?string $url = null;

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
