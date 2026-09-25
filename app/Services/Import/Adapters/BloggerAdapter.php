<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Adapters;

use DOMElement;
use FavoriteCMS\Services\Import\Contracts\ImporterInterface;
use FavoriteCMS\Services\Import\Models\NormalizedAuthor;
use FavoriteCMS\Services\Import\Models\NormalizedComment;
use FavoriteCMS\Services\Import\Models\NormalizedImport;
use FavoriteCMS\Services\Import\Models\NormalizedMedia;
use FavoriteCMS\Services\Import\Models\NormalizedPage;
use FavoriteCMS\Services\Import\Models\NormalizedPost;
use FavoriteCMS\Services\Import\Models\NormalizedTaxonomy;
use FavoriteCMS\Services\Import\Security\SafeXmlParser;
use Throwable;

class BloggerAdapter implements ImporterInterface
{
    public function getId(): string
    {
        return 'blogger';
    }

    public function getName(): string
    {
        return 'Google Blogger (Blogspot)';
    }

    public function getDescription(): string
    {
        return 'Import from Google Blogger XML backup (feed.atom or blog-*.xml) exported via Blogger Settings → Manage blog → Back up content.';
    }

    public function getSupportedExtensions(): array
    {
        return ['xml', 'atom', 'zip'];
    }

    public function detect(string $content, ?string $filename = null, ?string $mimeType = null): bool
    {
        $snippet = substr($content, 0, 4096);
        if (!str_contains($snippet, '<feed')) {
            return false;
        }

        return str_contains($content, 'blogger.com')
            || str_contains($content, 'schemas.google.com/blogger')
            || str_contains($content, 'http://www.blogger.com/atom/ns#')
            || str_contains($content, 'schemas.google.com/blogger/2018')
            || str_contains($content, '<blogger:');
    }

    public function validate(string $content): array
    {
        $errors = [];
        $warnings = [];

        try {
            $doc = SafeXmlParser::parse($content);
            $root = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);
            if ($root !== 'feed') {
                $errors[] = "Root XML element is '<{$root}>', expected '<feed>' for a Blogger Atom export.";
            }
        } catch (Throwable $e) {
            $errors[] = 'Blogger XML validation error: ' . $e->getMessage();
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    public function parse(string $content): NormalizedImport
    {
        $doc = SafeXmlParser::parse($content);
        $xpath = SafeXmlParser::createXPath($doc);

        $import = new NormalizedImport();
        $import->sourceId = 'blogger';
        $import->sourceName = 'Google Blogger';

        // Extract feed title & subtitle
        $titleNode = $xpath->query('/atom:feed/atom:title')->item(0);
        if ($titleNode) {
            $import->sourceMetadata['site_title'] = trim($titleNode->textContent);
        }

        $entries = $xpath->query('//atom:entry');
        if ($entries === false) {
            return $import;
        }

        foreach ($entries as $entry) {
            if (!($entry instanceof DOMElement)) {
                continue;
            }

            $kind = $this->determineKind($entry);
            if ($kind === 'template' || $kind === 'settings' || $kind === 'unknown') {
                continue;
            }

            $itemData = $this->extractEntryData($entry);

            // Record author
            if (!empty($itemData['author_name'])) {
                $import->addAuthor(new NormalizedAuthor([
                    'name'  => $itemData['author_name'],
                    'email' => $itemData['author_email'],
                ]));
            }

            // Record tags / categories
            foreach ($itemData['tags'] as $tagName) {
                $import->addTaxonomy(new NormalizedTaxonomy([
                    'name' => $tagName,
                    'slug' => $this->slugify($tagName),
                    'type' => 'tag',
                ]));
            }

            // Detect inline media
            $mediaUrls = $this->extractImageUrls($itemData['content']);
            foreach ($mediaUrls as $url) {
                $import->addMedia(new NormalizedMedia([
                    'sourceUrl' => $url,
                ]));
            }

            if ($kind === 'post') {
                $post = new NormalizedPost([
                    'sourceId'         => $itemData['id'],
                    'sourceGuid'       => $itemData['id'],
                    'sourceUrl'        => $itemData['url'],
                    'title'            => $itemData['title'],
                    'slug'             => $itemData['slug'] ?: $this->slugify($itemData['title']),
                    'content'          => $itemData['content'],
                    'excerpt'          => $itemData['excerpt'],
                    'status'           => $itemData['status'],
                    'authorName'       => $itemData['author_name'],
                    'authorEmail'      => $itemData['author_email'],
                    'publishedAt'      => $itemData['published_at'],
                    'createdAt'        => $itemData['created_at'],
                    'updatedAt'        => $itemData['updated_at'],
                    'tags'             => $itemData['tags'],
                    'inlineMediaUrls'  => $mediaUrls,
                    'featuredImageUrl' => !empty($mediaUrls) ? $mediaUrls[0] : null,
                ]);
                $import->addPost($post);

            } elseif ($kind === 'page') {
                $page = new NormalizedPage([
                    'sourceId'         => $itemData['id'],
                    'sourceGuid'       => $itemData['id'],
                    'sourceUrl'        => $itemData['url'],
                    'title'            => $itemData['title'],
                    'slug'             => $itemData['slug'] ?: $this->slugify($itemData['title']),
                    'content'          => $itemData['content'],
                    'excerpt'          => $itemData['excerpt'],
                    'status'           => $itemData['status'],
                    'authorName'       => $itemData['author_name'],
                    'authorEmail'      => $itemData['author_email'],
                    'publishedAt'      => $itemData['published_at'],
                    'createdAt'        => $itemData['created_at'],
                    'updatedAt'        => $itemData['updated_at'],
                    'inlineMediaUrls'  => $mediaUrls,
                ]);
                $import->addPage($page);

            } elseif ($kind === 'comment') {
                $comment = new NormalizedComment([
                    'sourceId'       => $itemData['id'],
                    'postSourceId'   => $itemData['in_reply_to_ref'],
                    'authorName'     => $itemData['author_name'] ?: 'Anonymous',
                    'authorEmail'    => $itemData['author_email'] ?: 'anonymous@example.com',
                    'authorUrl'      => $itemData['author_url'],
                    'content'        => $itemData['content'],
                    'status'         => 'approved',
                    'createdAt'      => $itemData['created_at'],
                    'updatedAt'      => $itemData['updated_at'],
                ]);
                $import->addComment($comment);
            }
        }

        return $import;
    }

    protected function determineKind(DOMElement $entry): string
    {
        // 1. Google Takeout Modern Blogger Type Check (<blogger:type>POST</blogger:type>)
        $bloggerTypes = $entry->getElementsByTagNameNS('http://schemas.google.com/blogger/2018', 'type');
        if ($bloggerTypes->length > 0) {
            $t = strtoupper(trim($bloggerTypes->item(0)->textContent));
            if ($t === 'POST') return 'post';
            if ($t === 'PAGE') return 'page';
            if ($t === 'COMMENT') return 'comment';
        }

        foreach ($entry->childNodes as $child) {
            if ($child instanceof DOMElement && (strcasecmp($child->localName ?? '', 'type') === 0 || str_ends_with($child->nodeName, ':type'))) {
                $t = strtoupper(trim($child->textContent));
                if ($t === 'POST') return 'post';
                if ($t === 'PAGE') return 'page';
                if ($t === 'COMMENT') return 'comment';
            }
        }

        // 2. Classic Blogger Kind Category Check
        $categories = $entry->getElementsByTagName('category');
        foreach ($categories as $cat) {
            $scheme = $cat->getAttribute('scheme');
            $term = $cat->getAttribute('term');

            if ($scheme === 'http://schemas.google.com/g/2005#kind') {
                if (str_ends_with($term, '#post')) {
                    return 'post';
                }
                if (str_ends_with($term, '#page')) {
                    return 'page';
                }
                if (str_ends_with($term, '#comment')) {
                    return 'comment';
                }
                if (str_ends_with($term, '#template')) {
                    return 'template';
                }
                if (str_ends_with($term, '#settings')) {
                    return 'settings';
                }
            }
        }

        // 3. Comment In-Reply-To Thread Check
        if ($entry->getElementsByTagNameNS('http://purl.org/syndication/thread/1.0', 'in-reply-to')->length > 0 ||
            $entry->getElementsByTagName('in-reply-to')->length > 0) {
            return 'comment';
        }

        // 4. Fallback checks from Atom ID
        $idNode = $entry->getElementsByTagName('id')->item(0);
        if ($idNode) {
            $idText = $idNode->textContent;
            if (str_contains($idText, '.page-')) return 'page';
            if (str_contains($idText, '.comment-')) return 'comment';
            if (str_contains($idText, '.post-')) return 'post';
        }

        return 'unknown';
    }

    protected function extractEntryData(DOMElement $entry): array
    {
        $title = '';
        $content = '';
        $publishedAt = date('Y-m-d H:i:s');
        $updatedAt = date('Y-m-d H:i:s');
        $createdAt = null;
        $authorName = '';
        $authorEmail = '';
        $authorUrl = null;
        $slug = '';
        $id = '';
        $url = '';
        $status = 'published';
        $excerpt = '';
        $tags = [];
        $inReplyToRef = null;

        $idNode = $entry->getElementsByTagName('id')->item(0);
        if ($idNode) {
            $id = trim($idNode->textContent);
        }

        $titleNode = $entry->getElementsByTagName('title')->item(0);
        if ($titleNode) {
            $title = trim($titleNode->textContent);
        }

        $contentNode = $entry->getElementsByTagName('content')->item(0);
        if ($contentNode) {
            $content = $contentNode->textContent;
        }

        $pubNode = $entry->getElementsByTagName('published')->item(0);
        if ($pubNode && !empty($pubNode->textContent)) {
            $time = strtotime($pubNode->textContent);
            if ($time !== false) {
                $publishedAt = date('Y-m-d H:i:s', $time);
            }
        }

        $upNode = $entry->getElementsByTagName('updated')->item(0);
        if ($upNode && !empty($upNode->textContent)) {
            $time = strtotime($upNode->textContent);
            if ($time !== false) {
                $updatedAt = date('Y-m-d H:i:s', $time);
            }
        }

        // Google Takeout created timestamp
        $createdNodes = $entry->getElementsByTagNameNS('http://schemas.google.com/blogger/2018', 'created');
        if ($createdNodes->length > 0 && !empty($createdNodes->item(0)->textContent)) {
            $time = strtotime($createdNodes->item(0)->textContent);
            if ($time !== false) {
                $createdAt = date('Y-m-d H:i:s', $time);
            }
        } else {
            foreach ($entry->childNodes as $child) {
                if ($child instanceof DOMElement && (strcasecmp($child->localName ?? '', 'created') === 0 || str_ends_with($child->nodeName, ':created'))) {
                    $time = strtotime($child->textContent);
                    if ($time !== false) {
                        $createdAt = date('Y-m-d H:i:s', $time);
                    }
                    break;
                }
            }
        }
        $createdAt = $createdAt ?? $publishedAt;

        // Author Information
        $authorNode = $entry->getElementsByTagName('author')->item(0);
        if ($authorNode) {
            $nameNode = $authorNode->getElementsByTagName('name')->item(0);
            if ($nameNode) {
                $authorName = trim($nameNode->textContent);
            }
            $emailNode = $authorNode->getElementsByTagName('email')->item(0);
            if ($emailNode) {
                $authorEmail = trim($emailNode->textContent);
            }
            $uriNode = $authorNode->getElementsByTagName('uri')->item(0);
            if ($uriNode) {
                $authorUrl = trim($uriNode->textContent);
            }

            // In Takeout, author name can sometimes be directly inside the author element text
            if ($authorName === '' && !empty($authorNode->textContent)) {
                $lines = array_filter(array_map('trim', explode("\n", $authorNode->textContent)));
                if (!empty($lines)) {
                    $authorName = reset($lines);
                }
            }
        }

        // Google Takeout filename extraction (/2026/04/advance-qr-code-generator.html or /p/contact-us.html)
        $filenameNodes = $entry->getElementsByTagNameNS('http://schemas.google.com/blogger/2018', 'filename');
        $bloggerFilename = '';
        if ($filenameNodes->length > 0) {
            $bloggerFilename = trim($filenameNodes->item(0)->textContent);
        } else {
            foreach ($entry->childNodes as $child) {
                if ($child instanceof DOMElement && (strcasecmp($child->localName ?? '', 'filename') === 0 || str_ends_with($child->nodeName, ':filename'))) {
                    $bloggerFilename = trim($child->textContent);
                    break;
                }
            }
        }

        if ($bloggerFilename !== '') {
            $url = $bloggerFilename;
            $path = trim($bloggerFilename, '/');
            $base = basename($path, '.html');
            if (!empty($base)) {
                $slug = $base;
            }
        }

        // Alternate link extraction fallback
        $links = $entry->getElementsByTagName('link');
        foreach ($links as $link) {
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');
            if (($rel === 'alternate' || $rel === '') && $href !== '') {
                $url = $href;
                if (empty($slug)) {
                    $path = parse_url($href, PHP_URL_PATH);
                    if ($path) {
                        $base = basename($path, '.html');
                        if (!empty($base)) {
                            $slug = $base;
                        }
                    }
                }
            }
        }

        if (empty($slug) && !empty($title)) {
            $slug = $this->slugify($title);
        }

        // Categories / Tags (Support both Blogger schema and Takeout schemes)
        $categories = $entry->getElementsByTagName('category');
        foreach ($categories as $cat) {
            $scheme = $cat->getAttribute('scheme');
            $term = trim($cat->getAttribute('term'));

            if ($term === '' || $scheme === 'http://schemas.google.com/g/2005#kind' || str_contains($term, 'schemas.google.com')) {
                continue;
            }
            $tags[] = $term;
        }

        // Status determination (Google Takeout status, app:draft, draft)
        $statusNodes = $entry->getElementsByTagNameNS('http://schemas.google.com/blogger/2018', 'status');
        if ($statusNodes->length > 0) {
            $rawStatus = strtoupper(trim($statusNodes->item(0)->textContent));
            if ($rawStatus === 'LIVE') {
                $status = 'published';
            } elseif ($rawStatus === 'SOFT_TRASHED' || $rawStatus === 'TRASHED') {
                $status = 'trash';
            } elseif ($rawStatus === 'DRAFT') {
                $status = 'draft';
            }
        } else {
            foreach ($entry->childNodes as $child) {
                if ($child instanceof DOMElement && (strcasecmp($child->localName ?? '', 'status') === 0 || str_ends_with($child->nodeName, ':status'))) {
                    $rawStatus = strtoupper(trim($child->textContent));
                    if ($rawStatus === 'LIVE') {
                        $status = 'published';
                    } elseif ($rawStatus === 'SOFT_TRASHED' || $rawStatus === 'TRASHED') {
                        $status = 'trash';
                    } elseif ($rawStatus === 'DRAFT') {
                        $status = 'draft';
                    }
                    break;
                }
            }
        }

        $draftNode = $entry->getElementsByTagName('draft')->item(0);
        if ($draftNode && strtolower(trim($draftNode->textContent)) === 'yes') {
            $status = 'draft';
        }
        $appDraft = $entry->getElementsByTagNameNS('http://purl.org/atom/app#', 'draft')->item(0)
            ?? $entry->getElementsByTagNameNS('http://www.w3.org/2007/app', 'draft')->item(0);
        if ($appDraft && strtolower(trim($appDraft->textContent)) === 'yes') {
            $status = 'draft';
        }

        // Meta Description / Excerpt
        $metaDescNodes = $entry->getElementsByTagNameNS('http://schemas.google.com/blogger/2018', 'metaDescription');
        if ($metaDescNodes->length > 0) {
            $excerpt = trim($metaDescNodes->item(0)->textContent);
        } else {
            foreach ($entry->childNodes as $child) {
                if ($child instanceof DOMElement && (strcasecmp($child->localName ?? '', 'metaDescription') === 0 || str_ends_with($child->nodeName, ':metaDescription'))) {
                    $excerpt = trim($child->textContent);
                    break;
                }
            }
        }

        if ($excerpt === '' && $content !== '') {
            $stripped = strip_tags($content);
            $excerpt = mb_substr(trim((string)preg_replace('/\s+/', ' ', $stripped)), 0, 200, 'UTF-8');
        }

        // In-Reply-To for comments
        $inReplyTo = $entry->getElementsByTagNameNS('http://purl.org/syndication/thread/1.0', 'in-reply-to')->item(0)
            ?? $entry->getElementsByTagName('in-reply-to')->item(0);
        if ($inReplyTo) {
            $inReplyToRef = $inReplyTo->getAttribute('ref');
        }

        return [
            'id'             => $id,
            'url'            => $url,
            'title'          => $title,
            'slug'           => $slug,
            'content'        => $content,
            'excerpt'        => $excerpt,
            'status'         => $status,
            'published_at'   => $publishedAt,
            'created_at'     => $createdAt,
            'updated_at'     => $updatedAt,
            'author_name'    => $authorName,
            'author_email'   => $authorEmail,
            'author_url'     => $authorUrl,
            'tags'           => array_values(array_unique($tags)),
            'in_reply_to_ref'=> $inReplyToRef,
        ];
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
        $slug = preg_replace('~[^\pL\d]+~u', '-', $text);
        $slug = trim((string)$slug, '-');
        $slug = mb_strtolower($slug, 'UTF-8');

        return $slug !== '' ? $slug : 'post';
    }
}
