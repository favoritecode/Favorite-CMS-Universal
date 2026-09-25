<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Contracts;

use FavoriteCMS\Services\Import\Models\NormalizedImport;

interface ImporterInterface
{
    /**
     * Unique identifier for the importer (e.g., 'blogger', 'wordpress', 'rss_atom', 'json').
     */
    public function getId(): string;

    /**
     * Human-readable source platform name (e.g., 'Google Blogger', 'WordPress (WXR)', etc.).
     */
    public function getName(): string;

    /**
     * Brief description of the supported format and instructions for users.
     */
    public function getDescription(): string;

    /**
     * Array of supported file extensions (lowercase, e.g. ['xml', 'atom']).
     */
    public function getSupportedExtensions(): array;

    /**
     * Fast heuristic/signature inspection to determine if the content matches this adapter.
     */
    public function detect(string $content, ?string $filename = null, ?string $mimeType = null): bool;

    /**
     * Validate content structure prior to full parsing.
     *
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validate(string $content): array;

    /**
     * Parse source export into the common NormalizedImport model.
     */
    public function parse(string $content): NormalizedImport;
}
