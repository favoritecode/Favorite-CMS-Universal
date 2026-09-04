<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\User;
use InvalidArgumentException;
use Throwable;

class BloggerImportService
{
    protected Application $app;
    protected Database $db;

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? Application::getInstance();
        $this->db = $this->app->make(Database::class);
    }

    /**
     * Safely load and parse Atom XML content, mitigating XXE vulnerabilities.
     */
    public function loadXml(string $xmlContent): DOMDocument
    {
        $trimmed = trim($xmlContent);
        if ($trimmed === '') {
            throw new InvalidArgumentException('XML content is empty.');
        }

        // XXE prevention: reject external DOCTYPE / ENTITY declarations
        if (preg_match('/<!ENTITY|<!DOCTYPE[^>]*SYSTEM/i', $trimmed)) {
            throw new InvalidArgumentException('XML contains disallowed DOCTYPE or ENTITY definitions.');
        }

        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            /** @noinspection PhpDeprecationInspection */
            libxml_disable_entity_loader(true);
        }

        $prevInternalErrors = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $options = LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR;

        $loaded = $doc->loadXML($trimmed, $options);
        libxml_use_internal_errors($prevInternalErrors);

        if (!$loaded || !$doc->documentElement) {
            throw new InvalidArgumentException('Invalid XML structure. Could not parse document.');
        }

        // Validate that this looks like an Atom feed
        $rootTag = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);
        if ($rootTag !== 'feed') {
            throw new InvalidArgumentException('The supplied XML file is not a valid Atom feed (root element is not <feed>).');
        }

        return $doc;
    }

    /**
     * Parse the Atom feed into organized structures (posts, pages, comments, tags).
     */
    public function parse(string $xmlContent): array
    {
        $doc = $this->loadXml($xmlContent);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $xpath->registerNamespace('app', 'http://purl.org/atom/app#');
        $xpath->registerNamespace('app2007', 'http://www.w3.org/2007/app');
        $xpath->registerNamespace('thr', 'http://purl.org/syndication/thread/1.0');

        $entries = $xpath->query('//atom:entry');
        if ($entries === false) {
            $entries = $doc->getElementsByTagName('entry');
        }

        $posts = [];
        $pages = [];
        $comments = [];
        $allTags = [];

        foreach ($entries as $entry) {
            if (!($entry instanceof DOMElement)) {
                continue;
            }

            // Determine entry kind
            $kind = $this->determineKind($entry);
            if ($kind === 'template' || $kind === 'settings' || $kind === 'unknown') {
                continue;
            }

            $item = $this->extractEntryData($entry, $kind);

            if (!empty($item['tags'])) {
                foreach ($item['tags'] as $tag) {
                    $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
                }
            }

            if ($kind === 'post') {
                $posts[] = $item;
            } elseif ($kind === 'page') {
                $pages[] = $item;
            } elseif ($kind === 'comment') {
                $comments[] = $item;
            }
        }

        return [
            'posts'    => $posts,
            'pages'    => $pages,
            'comments' => $comments,
            'tags'     => array_keys($allTags),
            'tag_counts' => $allTags,
        ];
    }

    /**
     * Extract preview summary statistics from Blogger XML export.
     */
    public function preview(string $xmlContent): array
    {
        try {
            $data = $this->parse($xmlContent);

            $draftPosts = count(array_filter($data['posts'], fn($p) => $p['status'] === 'draft'));
            $publishedPosts = count($data['posts']) - $draftPosts;

            $draftPages = count(array_filter($data['pages'], fn($p) => $p['status'] === 'draft'));
            $publishedPages = count($data['pages']) - $draftPages;

            $samplePosts = array_slice($data['posts'], 0, 5);

            return [
                'success' => true,
                'counts'  => [
                    'posts'           => count($data['posts']),
                    'posts_published' => $publishedPosts,
                    'posts_draft'     => $draftPosts,
                    'pages'           => count($data['pages']),
                    'pages_published' => $publishedPages,
                    'pages_draft'     => $draftPages,
                    'comments'        => count($data['comments']),
                    'tags'            => count($data['tags']),
                ],
                'tags'         => array_slice($data['tags'], 0, 30),
                'sample_posts' => array_map(fn($p) => [
                    'title'     => $p['title'],
                    'slug'      => $p['slug'],
                    'status'    => $p['status'],
                    'date'      => $p['published_at'],
                    'tags'      => $p['tags'],
                    'author'    => $p['author_name'],
                ], $samplePosts),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute full import from parsed Blogger XML.
     *
     * @param string $xmlContent
     * @param array $options [
     *     'author_id' => int,
     *     'import_posts' => bool,
     *     'import_pages' => bool,
     *     'import_comments' => bool,
     *     'default_status' => 'preserve'|'draft'|'published'
     * ]
     * @return array
     */
    public function import(string $xmlContent, array $options = []): array
    {
        $parsed = $this->parse($xmlContent);

        $authorId = (int)($options['author_id'] ?? ($_SESSION['auth_user_id'] ?? 1));
        $importPosts = $options['import_posts'] ?? true;
        $importPages = $options['import_pages'] ?? true;
        $importComments = $options['import_comments'] ?? true;
        $defaultStatus = (string)($options['default_status'] ?? 'preserve');

        $importedPosts = 0;
        $importedPages = 0;
        $importedComments = 0;
        $importedTags = 0;
        $skipped = 0;
        $errors = [];

        // Map Blogger post atom ID => created local post ID (for comment linking)
        $postIdMap = [];

        // 1. Import Posts
        if ($importPosts && !empty($parsed['posts'])) {
            foreach ($parsed['posts'] as $p) {
                try {
                    $status = $this->resolveStatus($p['status'], $defaultStatus);
                    $slug = $this->generateUniqueSlug('posts', $p['slug'] ?: $p['title']);
                    $cleanContent = ContentSanitizer::clean($p['content'], $authorId);

                    $postId = $this->db->insert('posts', [
                        'title'        => $p['title'] ?: 'Untitled Post',
                        'slug'         => $slug,
                        'content'      => $cleanContent,
                        'excerpt'      => $p['excerpt'] ?: null,
                        'status'       => $status,
                        'type'         => 'post',
                        'author_id'    => $authorId,
                        'published_at' => $p['published_at'],
                        'created_at'   => $p['created_at'],
                        'updated_at'   => $p['updated_at'],
                    ]);

                    if ($postId > 0) {
                        $importedPosts++;
                        if (!empty($p['blogger_id'])) {
                            $postIdMap[$p['blogger_id']] = $postId;
                        }

                        // Attach tags
                        if (!empty($p['tags'])) {
                            foreach ($p['tags'] as $tagName) {
                                $tag = Taxonomy::findOrCreate($tagName, 'tag');
                                if ($tag) {
                                    $this->db->execute(
                                        "INSERT IGNORE INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)",
                                        [$postId, $tag->id]
                                    );
                                    $importedTags++;
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = "Failed to import post '{$p['title']}': " . $e->getMessage();
                    $skipped++;
                }
            }
        }

        // 2. Import Pages
        if ($importPages && !empty($parsed['pages'])) {
            foreach ($parsed['pages'] as $pageData) {
                try {
                    $status = $this->resolveStatus($pageData['status'], $defaultStatus);
                    if ($status !== 'published' && $status !== 'draft') {
                        $status = 'draft';
                    }
                    $slug = $this->generateUniqueSlug('pages', $pageData['slug'] ?: $pageData['title']);
                    $cleanContent = ContentSanitizer::clean($pageData['content'], $authorId);

                    $pageId = $this->db->insert('pages', [
                        'title'      => $pageData['title'] ?: 'Untitled Page',
                        'slug'       => $slug,
                        'content'    => $cleanContent,
                        'status'     => $status,
                        'author_id'  => $authorId,
                        'menu_order' => 0,
                        'created_at' => $pageData['created_at'],
                        'updated_at' => $pageData['updated_at'],
                    ]);

                    if ($pageId > 0) {
                        $importedPages++;
                    }
                } catch (Throwable $e) {
                    $errors[] = "Failed to import page '{$pageData['title']}': " . $e->getMessage();
                    $skipped++;
                }
            }
        }

        // 3. Import Comments
        if ($importComments && !empty($parsed['comments'])) {
            foreach ($parsed['comments'] as $c) {
                try {
                    $targetPostId = null;
                    if (!empty($c['in_reply_to_ref']) && isset($postIdMap[$c['in_reply_to_ref']])) {
                        $targetPostId = $postIdMap[$c['in_reply_to_ref']];
                    }

                    // Fallback to first imported post or 0 if unlinked
                    if ($targetPostId === null && !empty($postIdMap)) {
                        $targetPostId = reset($postIdMap);
                    }

                    if ($targetPostId !== null) {
                        $commentId = $this->db->insert('comments', [
                            'post_id'      => $targetPostId,
                            'author_name'  => $c['author_name'] ?: 'Anonymous',
                            'author_email' => $c['author_email'] ?: 'anonymous@example.com',
                            'content'      => strip_tags($c['content']),
                            'status'       => 'approved',
                            'created_at'   => $c['created_at'],
                            'updated_at'   => $c['updated_at'],
                        ]);

                        if ($commentId > 0) {
                            $importedComments++;
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = "Failed to import comment: " . $e->getMessage();
                    $skipped++;
                }
            }
        }

        return [
            'success'  => empty($errors) || ($importedPosts > 0 || $importedPages > 0),
            'counts'   => [
                'posts'    => $importedPosts,
                'pages'    => $importedPages,
                'comments' => $importedComments,
                'tags'     => $importedTags,
            ],
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Extract structured information from an Atom <entry> element.
     */
    protected function extractEntryData(DOMElement $entry, string $kind): array
    {
        $title = '';
        $content = '';
        $publishedAt = date('Y-m-d H:i:s');
        $updatedAt = date('Y-m-d H:i:s');
        $authorName = '';
        $authorEmail = '';
        $slug = '';
        $bloggerId = '';
        $isDraft = false;
        $tags = [];
        $inReplyToRef = '';

        // Extract blogger entry ID
        $idNode = $entry->getElementsByTagName('id')->item(0);
        if ($idNode) {
            $bloggerId = trim($idNode->textContent);
        }

        // Title
        $titleNode = $entry->getElementsByTagName('title')->item(0);
        if ($titleNode) {
            $title = trim($titleNode->textContent);
        }

        // Content
        $contentNode = $entry->getElementsByTagName('content')->item(0);
        if ($contentNode) {
            $content = $contentNode->textContent;
        }

        // Published & Updated dates
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

        // Author
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
        }

        // Draft check via <app:control><app:draft>yes</app:draft></app:control>
        $draftNodes = $entry->getElementsByTagName('draft');
        foreach ($draftNodes as $dn) {
            if (strtolower(trim($dn->textContent)) === 'yes') {
                $isDraft = true;
                break;
            }
        }

        // Links & Slug
        $links = $entry->getElementsByTagName('link');
        foreach ($links as $link) {
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');

            if ($rel === 'alternate' && !empty($href)) {
                $slug = $this->extractSlugFromUrl($href);
            }
        }

        // In-reply-to check for comments
        $replyNodes = $entry->getElementsByTagName('in-reply-to');
        if ($replyNodes->length > 0) {
            $inReplyToRef = $replyNodes->item(0)->getAttribute('ref');
        }

        // Categories / Tags
        $categories = $entry->getElementsByTagName('category');
        foreach ($categories as $cat) {
            $scheme = $cat->getAttribute('scheme');
            $term = $cat->getAttribute('term');

            // Blogger user tags have scheme "http://www.blogger.com/atom/ns#"
            if ($scheme === 'http://www.blogger.com/atom/ns#' && !empty($term)) {
                $tags[] = trim($term);
            }
        }

        if (empty($slug)) {
            $slug = !empty($title) ? str_slug($title) : 'imported-' . bin2hex(random_bytes(3));
        }

        return [
            'kind'            => $kind,
            'blogger_id'      => $bloggerId,
            'title'           => $title,
            'slug'            => $slug,
            'content'         => $content,
            'excerpt'         => substr(strip_tags($content), 0, 200),
            'status'          => $isDraft ? 'draft' : 'published',
            'published_at'    => $publishedAt,
            'created_at'      => $publishedAt,
            'updated_at'      => $updatedAt,
            'author_name'     => $authorName,
            'author_email'    => $authorEmail,
            'tags'            => array_unique($tags),
            'in_reply_to_ref' => $inReplyToRef,
        ];
    }

    /**
     * Determine if an <entry> is a post, page, comment, or settings.
     */
    protected function determineKind(DOMElement $entry): string
    {
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

        // Fallback checks
        if ($entry->getElementsByTagName('in-reply-to')->length > 0) {
            return 'comment';
        }

        return 'unknown';
    }

    /**
     * Extract slug from a Blogspot URL (e.g. /2023/08/my-post-title.html -> my-post-title).
     */
    protected function extractSlugFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return '';
        }

        $base = basename($path);
        $clean = preg_replace('/\.html?$/i', '', $base);
        return $clean ? str_slug($clean) : '';
    }

    /**
     * Resolve imported status based on user import preference.
     */
    protected function resolveStatus(string $detectedStatus, string $defaultStatus): string
    {
        if ($defaultStatus === 'draft') {
            return 'draft';
        }
        if ($defaultStatus === 'published') {
            return 'published';
        }
        return $detectedStatus;
    }

    /**
     * Generate unique slug by checking if slug already exists in table.
     */
    protected function generateUniqueSlug(string $table, string $preferred): string
    {
        $base = str_slug($preferred);
        if ($base === '') {
            $base = 'imported-' . bin2hex(random_bytes(3));
        }

        $slug = $base;
        $counter = 1;

        while (true) {
            $existing = $this->db->selectOne("SELECT id FROM `{$table}` WHERE `slug` = ? LIMIT 1", [$slug]);
            if (!$existing) {
                return $slug;
            }
            $counter++;
            $slug = "{$base}-{$counter}";
        }
    }
}
