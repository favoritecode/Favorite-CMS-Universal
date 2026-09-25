<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Adapters;

use FavoriteCMS\Services\Import\Contracts\ImporterInterface;
use FavoriteCMS\Services\Import\Models\NormalizedAuthor;
use FavoriteCMS\Services\Import\Models\NormalizedComment;
use FavoriteCMS\Services\Import\Models\NormalizedImport;
use FavoriteCMS\Services\Import\Models\NormalizedMedia;
use FavoriteCMS\Services\Import\Models\NormalizedPage;
use FavoriteCMS\Services\Import\Models\NormalizedPost;
use FavoriteCMS\Services\Import\Models\NormalizedTaxonomy;
use InvalidArgumentException;
use JsonException;

class JsonAdapter implements ImporterInterface
{
    public function getId(): string
    {
        return 'json';
    }

    public function getName(): string
    {
        return 'Universal JSON Content Export';
    }

    public function getDescription(): string
    {
        return 'Import content from structured JSON backup files adhering to the Favorite CMS universal JSON content schema.';
    }

    public function getSupportedExtensions(): array
    {
        return ['json'];
    }

    public function detect(string $content, ?string $filename = null, ?string $mimeType = null): bool
    {
        $trimmed = ltrim($content);
        if (!str_starts_with($trimmed, '{')) {
            return false;
        }

        try {
            $data = json_decode($trimmed, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) return false;

            return isset($data['posts']) || isset($data['pages']) || isset($data['favorite_cms']) || isset($data['generator']);
        } catch (JsonException) {
            return false;
        }
    }

    public function validate(string $content): array
    {
        $errors = [];
        $warnings = [];

        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                $errors[] = 'JSON root must be an object.';
            } else {
                if (!isset($data['posts']) && !isset($data['pages'])) {
                    $errors[] = "JSON export must contain at least a 'posts' or 'pages' array.";
                }
            }
        } catch (JsonException $e) {
            $errors[] = 'JSON parsing failed: ' . $e->getMessage();
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    public function parse(string $content): NormalizedImport
    {
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON content structure.');
        }

        $import = new NormalizedImport();
        $import->sourceId = 'json';
        $import->sourceName = 'Universal JSON Export';
        $import->sourceVersion = (string)($data['version'] ?? '1.0');

        if (!empty($data['site']) && is_array($data['site'])) {
            $import->sourceMetadata['site_title'] = (string)($data['site']['title'] ?? '');
            $import->sourceMetadata['site_url'] = (string)($data['site']['url'] ?? '');
        }

        // 1. Taxonomies
        if (!empty($data['taxonomies']) && is_array($data['taxonomies'])) {
            foreach ($data['taxonomies'] as $tax) {
                if (is_array($tax) && !empty($tax['name'])) {
                    $import->addTaxonomy(new NormalizedTaxonomy([
                        'name'        => (string)$tax['name'],
                        'slug'        => (string)($tax['slug'] ?? $this->slugify((string)$tax['name'])),
                        'type'        => (string)($tax['type'] ?? 'category'),
                        'description' => isset($tax['description']) ? (string)$tax['description'] : null,
                    ]));
                }
            }
        }

        // 2. Posts
        if (!empty($data['posts']) && is_array($data['posts'])) {
            foreach ($data['posts'] as $p) {
                if (!is_array($p)) continue;

                $title = (string)($p['title'] ?? 'Untitled Post');
                $slug = (string)($p['slug'] ?? $this->slugify($title));
                $body = (string)($p['content'] ?? '');
                $excerpt = (string)($p['excerpt'] ?? '');
                $status = (string)($p['status'] ?? 'published');

                $authorName = 'Admin';
                $authorEmail = null;
                if (!empty($p['author'])) {
                    if (is_array($p['author'])) {
                        $authorName = (string)($p['author']['name'] ?? 'Admin');
                        $authorEmail = isset($p['author']['email']) ? (string)$p['author']['email'] : null;
                    } else {
                        $authorName = (string)$p['author'];
                    }
                }

                $categories = is_array($p['categories'] ?? null) ? $p['categories'] : [];
                $tags = is_array($p['tags'] ?? null) ? $p['tags'] : [];

                // Register categories & tags
                foreach ($categories as $cat) {
                    $import->addTaxonomy(new NormalizedTaxonomy([
                        'name' => (string)$cat,
                        'slug' => $this->slugify((string)$cat),
                        'type' => 'category',
                    ]));
                }
                foreach ($tags as $tag) {
                    $import->addTaxonomy(new NormalizedTaxonomy([
                        'name' => (string)$tag,
                        'slug' => $this->slugify((string)$tag),
                        'type' => 'tag',
                    ]));
                }

                $featuredImage = isset($p['featured_image']) ? (string)$p['featured_image'] : null;
                $mediaUrls = $this->extractImageUrls($body);
                if ($featuredImage && !in_array($featuredImage, $mediaUrls, true)) {
                    $mediaUrls[] = $featuredImage;
                }

                foreach ($mediaUrls as $u) {
                    $import->addMedia(new NormalizedMedia(['sourceUrl' => $u]));
                }

                $post = new NormalizedPost([
                    'sourceId'         => (string)($p['id'] ?? bin2hex(random_bytes(6))),
                    'sourceGuid'       => (string)($p['guid'] ?? $p['id'] ?? ''),
                    'sourceUrl'        => (string)($p['url'] ?? ''),
                    'title'            => $title,
                    'slug'             => $slug,
                    'content'          => $body,
                    'excerpt'          => $excerpt,
                    'status'           => $status,
                    'authorName'       => $authorName,
                    'authorEmail'      => $authorEmail,
                    'publishedAt'      => (string)($p['published_at'] ?? $p['created_at'] ?? date('Y-m-d H:i:s')),
                    'createdAt'        => (string)($p['created_at'] ?? date('Y-m-d H:i:s')),
                    'updatedAt'        => (string)($p['updated_at'] ?? date('Y-m-d H:i:s')),
                    'categories'       => array_map('strval', $categories),
                    'tags'             => array_map('strval', $tags),
                    'featuredImageUrl' => $featuredImage,
                    'inlineMediaUrls'  => $mediaUrls,
                ]);

                $import->addPost($post);
            }
        }

        // 3. Pages
        if (!empty($data['pages']) && is_array($data['pages'])) {
            foreach ($data['pages'] as $pg) {
                if (!is_array($pg)) continue;

                $title = (string)($pg['title'] ?? 'Untitled Page');
                $slug = (string)($pg['slug'] ?? $this->slugify($title));
                $body = (string)($pg['content'] ?? '');
                $status = (string)($pg['status'] ?? 'published');

                $mediaUrls = $this->extractImageUrls($body);
                foreach ($mediaUrls as $u) {
                    $import->addMedia(new NormalizedMedia(['sourceUrl' => $u]));
                }

                $import->addPage(new NormalizedPage([
                    'sourceId'         => (string)($pg['id'] ?? bin2hex(random_bytes(6))),
                    'sourceGuid'       => (string)($pg['guid'] ?? $pg['id'] ?? ''),
                    'sourceUrl'        => (string)($pg['url'] ?? ''),
                    'title'            => $title,
                    'slug'             => $slug,
                    'content'          => $body,
                    'excerpt'          => (string)($pg['excerpt'] ?? ''),
                    'status'           => $status,
                    'parentSourceId'   => isset($pg['parent_id']) ? (string)$pg['parent_id'] : null,
                    'menuOrder'        => (int)($pg['menu_order'] ?? 0),
                    'template'         => isset($pg['template']) ? (string)$pg['template'] : null,
                    'publishedAt'      => (string)($pg['published_at'] ?? $pg['created_at'] ?? date('Y-m-d H:i:s')),
                    'createdAt'        => (string)($pg['created_at'] ?? date('Y-m-d H:i:s')),
                    'updatedAt'        => (string)($pg['updated_at'] ?? date('Y-m-d H:i:s')),
                    'featuredImageUrl' => isset($pg['featured_image']) ? (string)$pg['featured_image'] : null,
                    'inlineMediaUrls'  => $mediaUrls,
                ]));
            }
        }

        // 4. Comments
        if (!empty($data['comments']) && is_array($data['comments'])) {
            foreach ($data['comments'] as $c) {
                if (!is_array($c)) continue;

                $import->addComment(new NormalizedComment([
                    'sourceId'       => (string)($c['id'] ?? bin2hex(random_bytes(6))),
                    'postSourceId'   => isset($c['post_id']) ? (string)$c['post_id'] : null,
                    'parentSourceId' => isset($c['parent_id']) ? (string)$c['parent_id'] : null,
                    'authorName'     => (string)($c['author_name'] ?? 'Anonymous'),
                    'authorEmail'    => (string)($c['author_email'] ?? 'anonymous@example.com'),
                    'authorUrl'      => isset($c['author_url']) ? (string)$c['author_url'] : null,
                    'content'        => (string)($c['content'] ?? ''),
                    'status'         => (string)($c['status'] ?? 'approved'),
                    'createdAt'      => (string)($c['created_at'] ?? date('Y-m-d H:i:s')),
                ]));
            }
        }

        return $import;
    }

    protected function extractImageUrls(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $urls = [];
        if (preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $html, $matches)) {
            foreach ($matches[1] as $u) {
                $t = trim($u);
                if (filter_var($t, FILTER_VALIDATE_URL) && str_starts_with(strtolower($t), 'http')) {
                    $urls[] = $t;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    protected function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT//IGNORE', $text ?: 'item');
        $text = preg_replace('~[^-\w]+~', '', (string)$text);
        $text = trim($text, '-');
        $text = strtolower($text);

        return $text ?: 'item';
    }
}
