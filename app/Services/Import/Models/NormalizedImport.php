<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Models;

class NormalizedImport
{
    public string $sourceId = '';
    public string $sourceName = '';
    public string $sourceVersion = '';
    /** @var array<string, mixed> */
    public array $sourceMetadata = [];

    /** @var NormalizedPost[] */
    public array $posts = [];

    /** @var NormalizedPage[] */
    public array $pages = [];

    /** @var NormalizedComment[] */
    public array $comments = [];

    /** @var NormalizedTaxonomy[] */
    public array $taxonomies = [];

    /** @var NormalizedAuthor[] */
    public array $authors = [];

    /** @var NormalizedMedia[] */
    public array $media = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] */
    public array $unsupportedFeatures = [];

    public function addPost(NormalizedPost $post): void
    {
        $this->posts[] = $post;
    }

    public function addPage(NormalizedPage $page): void
    {
        $this->pages[] = $page;
    }

    public function addComment(NormalizedComment $comment): void
    {
        $this->comments[] = $comment;
    }

    public function addTaxonomy(NormalizedTaxonomy $taxonomy): void
    {
        // Avoid duplicate taxonomies in list
        foreach ($this->taxonomies as $existing) {
            if ($existing->name === $taxonomy->name && $existing->type === $taxonomy->type) {
                return;
            }
        }
        $this->taxonomies[] = $taxonomy;
    }

    public function addAuthor(NormalizedAuthor $author): void
    {
        foreach ($this->authors as $existing) {
            if ($existing->name === $author->name || ($author->email && $existing->email === $author->email)) {
                return;
            }
        }
        $this->authors[] = $author;
    }

    public function addMedia(NormalizedMedia $media): void
    {
        if ($media->sourceUrl !== '') {
            foreach ($this->media as $existing) {
                if ($existing->sourceUrl === $media->sourceUrl) {
                    return;
                }
            }
        }
        $this->media[] = $media;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function addUnsupportedFeature(string $feature): void
    {
        $this->unsupportedFeatures[] = $feature;
    }

    /**
     * Get preview statistics for user confirmation.
     *
     * @return array<string, mixed>
     */
    public function getPreviewData(): array
    {
        $publishedPosts = count(array_filter($this->posts, fn($p) => $p->status === 'published'));
        $draftPosts = count($this->posts) - $publishedPosts;

        $publishedPages = count(array_filter($this->pages, fn($p) => $p->status === 'published'));
        $draftPages = count($this->pages) - $publishedPages;

        $categories = array_filter($this->taxonomies, fn($t) => $t->type === 'category');
        $tags = array_filter($this->taxonomies, fn($t) => $t->type === 'tag');

        return [
            'source_id'            => $this->sourceId,
            'source_name'          => $this->sourceName,
            'source_version'       => $this->sourceVersion,
            'counts'               => [
                'posts'           => count($this->posts),
                'posts_published' => $publishedPosts,
                'posts_draft'     => $draftPosts,
                'pages'           => count($this->pages),
                'pages_published' => $publishedPages,
                'pages_draft'     => $draftPages,
                'comments'        => count($this->comments),
                'categories'      => count($categories),
                'tags'            => count($tags),
                'authors'         => count($this->authors),
                'media'           => count($this->media),
            ],
            'categories'           => array_map(fn($c) => $c->name, array_slice(array_values($categories), 0, 30)),
            'tags'                 => array_map(fn($t) => $t->name, array_slice(array_values($tags), 0, 30)),
            'authors'              => array_map(fn($a) => $a->name ?: $a->username ?: 'Unknown', $this->authors),
            'sample_posts'         => array_map(fn($p) => [
                'title'     => $p->title ?: '(Untitled Post)',
                'slug'      => $p->slug,
                'status'    => $p->status,
                'date'      => $p->publishedAt ?? $p->createdAt ?? 'Unknown date',
                'author'    => $p->authorName ?? 'Admin',
                'tags'      => $p->tags,
                'categories'=> $p->categories,
            ], array_slice($this->posts, 0, 5)),
            'sample_pages'         => array_map(fn($p) => [
                'title'  => $p->title ?: '(Untitled Page)',
                'slug'   => $p->slug,
                'status' => $p->status,
                'date'   => $p->createdAt ?? 'Unknown date',
            ], array_slice($this->pages, 0, 5)),
            'warnings'             => array_values(array_unique($this->warnings)),
            'unsupported_features' => array_values(array_unique($this->unsupportedFeatures)),
        ];
    }
}
