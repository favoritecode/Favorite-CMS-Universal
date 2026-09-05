<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\ContentSanitizer;
use FavoriteCMS\Services\Import\Adapters\BloggerAdapter;
use FavoriteCMS\Services\Import\Adapters\JsonAdapter;
use FavoriteCMS\Services\Import\Adapters\RssAtomAdapter;
use FavoriteCMS\Services\Import\Adapters\WordPressAdapter;
use FavoriteCMS\Services\Import\Contracts\ImporterInterface;
use FavoriteCMS\Services\Import\Models\NormalizedImport;
use InvalidArgumentException;
use Throwable;

class ImportEngine
{
    protected Application $app;
    protected ?Database $db;
    protected MediaMigrator $mediaMigrator;

    /** @var array<string, ImporterInterface> */
    protected array $adapters = [];

    /**
     * Documented audit registry of sources, formats, and readiness status.
     */
    protected array $platformRegistry = [
        'blogger' => [
            'name'        => 'Google Blogger (Blogspot)',
            'format'      => 'Atom XML (feed.atom / blog-*.xml)',
            'status'      => 'READY',
            'features'    => ['posts', 'pages', 'comments', 'tags', 'media', 'dates'],
            'description' => 'Fully supported via Blogger official backup export files.',
        ],
        'wordpress' => [
            'name'        => 'WordPress',
            'format'      => 'WXR XML (v1.0, v1.1, v1.2)',
            'status'      => 'READY',
            'features'    => ['posts', 'pages', 'comments', 'categories', 'tags', 'authors', 'media', 'hierarchical_pages'],
            'description' => 'Fully supported via official WordPress WXR XML export files.',
        ],
        'rss_atom' => [
            'name'        => 'Generic RSS 2.0 / Atom 1.0',
            'format'      => 'Standard Web Syndication Feed',
            'status'      => 'READY',
            'features'    => ['posts', 'categories', 'enclosures', 'inline_media'],
            'description' => 'Fully supported standard syndication feed imports.',
        ],
        'json' => [
            'name'        => 'Universal JSON Content Export',
            'format'      => 'JSON (Favorite CMS Standard Content Schema)',
            'status'      => 'READY',
            'features'    => ['posts', 'pages', 'comments', 'taxonomies', 'media', 'metadata'],
            'description' => 'Fully supported structured JSON content migrations.',
        ],
        'ghost' => [
            'name'        => 'Ghost CMS',
            'format'      => 'JSON (Ghost Lexical / Mobiledoc)',
            'status'      => 'NOT_READY',
            'features'    => [],
            'description' => 'Ghost uses internal Lexical/Mobiledoc AST document structures; planned for future ecosystem release.',
        ],
        'medium' => [
            'name'        => 'Medium',
            'format'      => 'Multi-file HTML ZIP archive',
            'status'      => 'NOT_READY',
            'features'    => [],
            'description' => 'Medium exports individual unindexed HTML files without central metadata index; planned for future release.',
        ],
        'drupal' => [
            'name'        => 'Drupal',
            'format'      => 'No single standard core export file',
            'status'      => 'NOT_READY',
            'features'    => [],
            'description' => 'Drupal does not provide a standard single-file core content export without custom modules.',
        ],
        'joomla' => [
            'name'        => 'Joomla',
            'format'      => 'Third-party extension archives',
            'status'      => 'NOT_READY',
            'features'    => [],
            'description' => 'Joomla requires third-party extension packages for content export; not yet supported in Core.',
        ],
    ];

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? Application::getInstance();
        try {
            $this->db = $this->app->make(Database::class);
        } catch (Throwable) {
            $this->db = null;
        }
        $this->mediaMigrator = new MediaMigrator($this->app);

        // Register default adapters
        $this->registerAdapter(new BloggerAdapter());
        $this->registerAdapter(new WordPressAdapter());
        $this->registerAdapter(new RssAtomAdapter());
        $this->registerAdapter(new JsonAdapter());
    }

    public function registerAdapter(ImporterInterface $adapter): void
    {
        $this->adapters[$adapter->getId()] = $adapter;
    }

    /**
     * @return array<string, ImporterInterface>
     */
    public function getAdapters(): array
    {
        return $this->adapters;
    }

    public function getAdapter(string $id): ?ImporterInterface
    {
        return $this->adapters[$id] ?? null;
    }

    public function getPlatformRegistry(): array
    {
        return $this->platformRegistry;
    }

    /**
     * Detect matching importer adapter from file content, name, and MIME.
     */
    public function detectAdapter(string $content, ?string $filename = null, ?string $mimeType = null): ?ImporterInterface
    {
        // Try specific adapters first (Blogger and WordPress before generic RSS)
        $priorityOrder = ['blogger', 'wordpress', 'json', 'rss_atom'];

        foreach ($priorityOrder as $id) {
            if (isset($this->adapters[$id])) {
                $adapter = $this->adapters[$id];
                if ($adapter->detect($content, $filename, $mimeType)) {
                    return $adapter;
                }
            }
        }

        // Check any custom registered adapters
        foreach ($this->adapters as $adapter) {
            if (!in_array($adapter->getId(), $priorityOrder, true)) {
                if ($adapter->detect($content, $filename, $mimeType)) {
                    return $adapter;
                }
            }
        }

        return null;
    }

    /**
     * Generate preview statistics for an export file.
     *
     * @return array<string, mixed>
     */
    public function preview(string $content, ?string $adapterId = null, ?string $filename = null): array
    {
        $adapter = $adapterId ? $this->getAdapter($adapterId) : $this->detectAdapter($content, $filename);

        if (!$adapter) {
            return [
                'success' => false,
                'error'   => 'Could not detect export file format. Please manually select the source CMS.',
            ];
        }

        $validation = $adapter->validate($content);
        if (!$validation['valid']) {
            return [
                'success'  => false,
                'error'    => implode('; ', $validation['errors']),
                'warnings' => $validation['warnings'],
            ];
        }

        try {
            $parsed = $adapter->parse($content);
            $preview = $parsed->getPreviewData();
            $preview['success'] = true;
            $preview['adapter_id'] = $adapter->getId();
            $preview['adapter_name'] = $adapter->getName();

            return $preview;
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Failed to analyze export content: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute full import into Favorite CMS database.
     *
     * @param string $content Raw export file content
     * @param array $options User options:
     *   - 'deduplication_mode' => 'skip' (default) | 'update' | 'create_new'
     *   - 'import_media'       => bool (default true)
     *   - 'author_handling'    => 'admin' (default) | 'map_existing' | 'create_author'
     *   - 'default_status'     => 'preserve' (default) | 'draft' | 'published'
     *   - 'import_posts'       => bool (default true)
     *   - 'import_pages'       => bool (default true)
     *   - 'import_comments'    => bool (default true)
     *   - 'author_id'          => int (fallback admin ID)
     * @param string|null $adapterId
     * @return array Granular execution report
     */
    public function import(string $content, array $options = [], ?string $adapterId = null): array
    {
        $adapter = $adapterId ? $this->getAdapter($adapterId) : $this->detectAdapter($content);
        if (!$adapter) {
            throw new InvalidArgumentException('No matching import adapter found for supplied content.');
        }

        $parsed = $adapter->parse($content);

        $dedupMode = (string)($options['deduplication_mode'] ?? 'skip');
        $importMedia = (bool)($options['import_media'] ?? true);
        $authorHandling = (string)($options['author_handling'] ?? 'admin');
        $defaultStatus = (string)($options['default_status'] ?? 'preserve');
        $importPosts = (bool)($options['import_posts'] ?? true);
        $importPages = (bool)($options['import_pages'] ?? true);
        $importComments = (bool)($options['import_comments'] ?? true);
        $currentUserId = (int)($options['author_id'] ?? ($_SESSION['auth_user_id'] ?? 1));

        $report = [
            'success'   => true,
            'source'    => $adapter->getName(),
            'posts'     => ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            'pages'     => ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            'comments'  => ['imported' => 0, 'skipped' => 0, 'failed' => 0],
            'media'     => ['downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'preserved_externally' => 0],
            'taxonomy'  => ['categories_created' => 0, 'tags_created' => 0],
            'authors'   => ['mapped' => 0, 'created' => 0, 'reassigned' => 0],
            'errors'    => [],
        ];

        // 1. Media Migration (if enabled)
        $urlMap = [];
        if ($importMedia && !empty($parsed->media)) {
            foreach ($parsed->media as $mediaItem) {
                if (empty($mediaItem->sourceUrl)) continue;

                $downloaded = $this->mediaMigrator->downloadMedia($mediaItem->sourceUrl, $currentUserId);
                if ($downloaded->status === 'downloaded' && $downloaded->localUrl) {
                    $urlMap[$mediaItem->sourceUrl] = $downloaded->localUrl;
                    $report['media']['downloaded']++;
                } else {
                    $report['media']['failed']++;
                    $report['media']['preserved_externally']++;
                }
            }
        } else {
            $report['media']['skipped'] = count($parsed->media);
        }

        // 2. Author Resolution Cache
        $authorMap = [];
        $resolvedCurrentUserId = $currentUserId;

        // 3. Taxonomies Pre-Population
        $taxCache = [];
        foreach ($parsed->taxonomies as $tax) {
            try {
                $t = Taxonomy::findOrCreate($tax->name, $tax->type);
                if ($t) {
                    $taxCache[$tax->type . ':' . $tax->name] = $t->id;
                    if ($tax->type === 'category') {
                        $report['taxonomy']['categories_created']++;
                    } else {
                        $report['taxonomy']['tags_created']++;
                    }
                }
            } catch (Throwable) {
                // Ignore taxonomy insertion errors
            }
        }

        // Map source post ID / GUID => local post ID
        $postIdMap = [];
        // Map source page ID => local page ID (for parent resolution)
        $pageIdMap = [];
        // Deferred parent updates for pages: [ [localPageId, parentSourceId], ... ]
        $deferredPageParents = [];

        // 4. Import Posts
        if ($importPosts && !empty($parsed->posts)) {
            foreach ($parsed->posts as $p) {
                try {
                    $targetAuthorId = $this->resolveAuthorId($p->authorName, $p->authorEmail, $authorHandling, $resolvedCurrentUserId, $report);

                    // Check deduplication
                    $existing = $this->findExistingPost($p->slug, $p->title, $p->publishedAt);

                    if ($existing) {
                        if ($dedupMode === 'skip') {
                            $report['posts']['skipped']++;
                            $postIdMap[$p->sourceId] = (int)$existing->id;
                            if ($p->sourceGuid) $postIdMap[$p->sourceGuid] = (int)$existing->id;
                            continue;
                        }

                        if ($dedupMode === 'update') {
                            $cleanContent = $this->mediaMigrator->rewriteContentUrls($p->content, $urlMap);
                            $cleanContent = ContentSanitizer::clean($cleanContent, $targetAuthorId);
                            $status = $this->resolveStatus($p->status, $defaultStatus);

                            if ($this->db) {
                                $this->db->update('posts', [
                                    'title'        => $p->title ?: 'Untitled Post',
                                    'content'      => $cleanContent,
                                    'excerpt'      => $p->excerpt ?: null,
                                    'status'       => $status,
                                    'updated_at'   => $p->updatedAt ?? date('Y-m-d H:i:s'),
                                ], ['id' => $existing->id]);
                            }

                            $postIdMap[$p->sourceId] = (int)$existing->id;
                            if ($p->sourceGuid) $postIdMap[$p->sourceGuid] = (int)$existing->id;
                            $report['posts']['updated']++;
                            continue;
                        }
                    }

                    // Insert New Post
                    $status = $this->resolveStatus($p->status, $defaultStatus);
                    $slug = $this->generateUniqueSlug('posts', $p->slug ?: $p->title);
                    $cleanContent = $this->mediaMigrator->rewriteContentUrls($p->content, $urlMap);
                    $cleanContent = ContentSanitizer::clean($cleanContent, $targetAuthorId);

                    $pubDate = $p->publishedAt ?? date('Y-m-d H:i:s');
                    $createDate = $p->createdAt ?? $pubDate;
                    $updateDate = $p->updatedAt ?? $pubDate;

                    $featuredImgId = null;
                    if ($p->featuredImageUrl && isset($urlMap[$p->featuredImageUrl])) {
                        // find media ID if possible
                    }

                    $postId = 0;
                    if ($this->db) {
                        $postId = $this->db->insert('posts', [
                            'title'        => $p->title ?: 'Untitled Post',
                            'slug'         => $slug,
                            'content'      => $cleanContent,
                            'excerpt'      => $p->excerpt ?: null,
                            'status'       => $status,
                            'type'         => 'post',
                            'author_id'    => $targetAuthorId,
                            'published_at' => $pubDate,
                            'created_at'   => $createDate,
                            'updated_at'   => $updateDate,
                        ]);
                    }

                    if ($postId > 0) {
                        $report['posts']['imported']++;
                        $postIdMap[$p->sourceId] = $postId;
                        if ($p->sourceGuid) $postIdMap[$p->sourceGuid] = $postId;

                        // Attach categories & tags
                        $this->attachTaxonomies($postId, $p->categories, 'category', $taxCache);
                        $this->attachTaxonomies($postId, $p->tags, 'tag', $taxCache);
                    } else {
                        $report['posts']['failed']++;
                    }

                } catch (Throwable $e) {
                    $report['posts']['failed']++;
                    $report['errors'][] = "Post '{$p->title}' error: " . $e->getMessage();
                }
            }
        }

        // 5. Import Pages (Pass 1: Insert, Pass 2: Hierarchy)
        if ($importPages && !empty($parsed->pages)) {
            foreach ($parsed->pages as $pageItem) {
                try {
                    $targetAuthorId = $this->resolveAuthorId($pageItem->authorName, $pageItem->authorEmail, $authorHandling, $resolvedCurrentUserId, $report);

                    $existing = $this->findExistingPage($pageItem->slug, $pageItem->title);
                    if ($existing) {
                        if ($dedupMode === 'skip') {
                            $report['pages']['skipped']++;
                            $pageIdMap[$pageItem->sourceId] = (int)$existing->id;
                            continue;
                        }

                        if ($dedupMode === 'update') {
                            $cleanContent = $this->mediaMigrator->rewriteContentUrls($pageItem->content, $urlMap);
                            $cleanContent = ContentSanitizer::clean($cleanContent, $targetAuthorId);
                            $status = $this->resolveStatus($pageItem->status, $defaultStatus);
                            if ($status !== 'published' && $status !== 'draft') $status = 'draft';

                            if ($this->db) {
                                $this->db->update('pages', [
                                    'title'      => $pageItem->title ?: 'Untitled Page',
                                    'content'    => $cleanContent,
                                    'excerpt'    => $pageItem->excerpt ?: null,
                                    'status'     => $status,
                                    'menu_order' => $pageItem->menuOrder,
                                    'updated_at' => $pageItem->updatedAt ?? date('Y-m-d H:i:s'),
                                ], ['id' => $existing->id]);
                            }

                            $pageIdMap[$pageItem->sourceId] = (int)$existing->id;
                            $report['pages']['updated']++;
                            continue;
                        }
                    }

                    // Insert New Page
                    $status = $this->resolveStatus($pageItem->status, $defaultStatus);
                    if ($status !== 'published' && $status !== 'draft') $status = 'draft';

                    $slug = $this->generateUniqueSlug('pages', $pageItem->slug ?: $pageItem->title);
                    $cleanContent = $this->mediaMigrator->rewriteContentUrls($pageItem->content, $urlMap);
                    $cleanContent = ContentSanitizer::clean($cleanContent, $targetAuthorId);

                    $createDate = $pageItem->createdAt ?? date('Y-m-d H:i:s');
                    $updateDate = $pageItem->updatedAt ?? $createDate;

                    $pageId = 0;
                    if ($this->db) {
                        $pageId = $this->db->insert('pages', [
                            'title'      => $pageItem->title ?: 'Untitled Page',
                            'slug'       => $slug,
                            'content'    => $cleanContent,
                            'excerpt'    => $pageItem->excerpt ?: null,
                            'status'     => $status,
                            'parent_id'  => null, // Set in pass 2
                            'author_id'  => $targetAuthorId,
                            'menu_order' => $pageItem->menuOrder,
                            'created_at' => $createDate,
                            'updated_at' => $updateDate,
                        ]);
                    }

                    if ($pageId > 0) {
                        $report['pages']['imported']++;
                        $pageIdMap[$pageItem->sourceId] = $pageId;
                        if ($pageItem->parentSourceId) {
                            $deferredPageParents[] = [$pageId, $pageItem->parentSourceId];
                        }
                    } else {
                        $report['pages']['failed']++;
                    }

                } catch (Throwable $e) {
                    $report['pages']['failed']++;
                    $report['errors'][] = "Page '{$pageItem->title}' error: " . $e->getMessage();
                }
            }

            // Pass 2: Resolve Page Hierarchy
            if ($this->db && !empty($deferredPageParents)) {
                foreach ($deferredPageParents as [$childLocalId, $parentSourceId]) {
                    if (isset($pageIdMap[$parentSourceId])) {
                        $parentLocalId = $pageIdMap[$parentSourceId];
                        $this->db->update('pages', ['parent_id' => $parentLocalId], ['id' => $childLocalId]);
                    }
                }
            }
        }

        // 6. Import Comments
        if ($importComments && !empty($parsed->comments)) {
            foreach ($parsed->comments as $comm) {
                try {
                    $targetPostId = null;
                    if ($comm->postSourceId && isset($postIdMap[$comm->postSourceId])) {
                        $targetPostId = $postIdMap[$comm->postSourceId];
                    } elseif (!empty($postIdMap)) {
                        // Link to first imported post if unmapped
                        $targetPostId = reset($postIdMap);
                    }

                    if ($targetPostId === null) {
                        $report['comments']['skipped']++;
                        continue;
                    }

                    // Check comment duplicate
                    if ($this->isCommentDuplicate($targetPostId, $comm->authorName, $comm->content)) {
                        $report['comments']['skipped']++;
                        continue;
                    }

                    $commId = 0;
                    if ($this->db) {
                        $commId = $this->db->insert('comments', [
                            'post_id'      => $targetPostId,
                            'author_name'  => $comm->authorName ?: 'Anonymous',
                            'author_email' => $comm->authorEmail ?: 'anonymous@example.com',
                            'author_url'   => $comm->authorUrl,
                            'author_ip'    => $comm->authorIp,
                            'content'      => strip_tags($comm->content),
                            'status'       => $comm->status,
                            'created_at'   => $comm->createdAt ?? date('Y-m-d H:i:s'),
                            'updated_at'   => $comm->updatedAt ?? $comm->createdAt ?? date('Y-m-d H:i:s'),
                        ]);
                    }

                    if ($commId > 0) {
                        $report['comments']['imported']++;
                    } else {
                        $report['comments']['failed']++;
                    }

                } catch (Throwable $e) {
                    $report['comments']['failed']++;
                    $report['errors'][] = 'Comment error: ' . $e->getMessage();
                }
            }
        }

        return $report;
    }

    protected function resolveAuthorId(?string $name, ?string $email, string $handling, int $fallbackId, array &$report): int
    {
        if ($handling === 'admin' || !$this->db) {
            $report['authors']['reassigned']++;
            return $fallbackId;
        }

        if ($handling === 'map_existing') {
            if ($email) {
                $user = User::findByEmail($email);
                if ($user) {
                    $report['authors']['mapped']++;
                    return (int)$user->id;
                }
            }
            if ($name) {
                $user = User::findByUsername($this->slugify($name));
                if ($user) {
                    $report['authors']['mapped']++;
                    return (int)$user->id;
                }
            }
            $report['authors']['reassigned']++;
            return $fallbackId;
        }

        if ($handling === 'create_author') {
            if ($email) {
                $existing = User::findByEmail($email);
                if ($existing) {
                    $report['authors']['mapped']++;
                    return (int)$existing->id;
                }
            }

            // Create safe unprivileged author
            $username = $this->generateUniqueUsername($name ?: 'imported_author');
            $userEmail = $email ?: ($username . '@import.local');
            $now = date('Y-m-d H:i:s');
            $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

            $userId = $this->db->insert('users', [
                'username'   => $username,
                'name'       => $name ?: 'Imported Author',
                'email'      => $userEmail,
                'password'   => $dummyHash,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($userId > 0) {
                // Assign role 'author' (never admin)
                try {
                    $authorRole = $this->db->selectOne("SELECT id FROM roles WHERE name = 'author'");
                    if ($authorRole) {
                        $this->db->execute("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)", [$userId, $authorRole->id]);
                    }
                } catch (Throwable) {}

                $report['authors']['created']++;
                return $userId;
            }
        }

        $report['authors']['reassigned']++;
        return $fallbackId;
    }

    protected function findExistingPost(?string $slug, string $title, ?string $publishedAt): ?Post
    {
        if ($slug !== '') {
            $found = Post::findBySlug($slug);
            if ($found) return $found;
        }

        if ($this->db && $title !== '' && $publishedAt) {
            $dateOnly = substr($publishedAt, 0, 10);
            $row = $this->db->selectOne(
                "SELECT * FROM posts WHERE title = ? AND DATE(published_at) = ? LIMIT 1",
                [$title, $dateOnly]
            );
            if ($row) {
                return new Post((array)$row);
            }
        }

        return null;
    }

    protected function findExistingPage(?string $slug, string $title): ?Page
    {
        if ($slug !== '') {
            $found = Page::findBySlug($slug);
            if ($found) return $found;
        }

        if ($this->db && $title !== '') {
            $row = $this->db->selectOne("SELECT * FROM pages WHERE title = ? LIMIT 1", [$title]);
            if ($row) {
                return new Page((array)$row);
            }
        }

        return null;
    }

    protected function isCommentDuplicate(int $postId, string $authorName, string $content): bool
    {
        if (!$this->db) return false;

        $cleanContent = strip_tags($content);
        $row = $this->db->selectOne(
            "SELECT id FROM comments WHERE post_id = ? AND author_name = ? AND content = ? LIMIT 1",
            [$postId, $authorName, $cleanContent]
        );

        return !empty($row);
    }

    protected function attachTaxonomies(int $postId, array $items, string $type, array &$taxCache): void
    {
        if (!$this->db || empty($items)) return;

        foreach ($items as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;

            $cacheKey = $type . ':' . $name;
            $taxId = $taxCache[$cacheKey] ?? null;

            if (!$taxId) {
                $t = Taxonomy::findOrCreate($name, $type);
                if ($t) {
                    $taxId = $t->id;
                    $taxCache[$cacheKey] = $taxId;
                }
            }

            if ($taxId) {
                $this->db->execute(
                    "INSERT IGNORE INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)",
                    [$postId, $taxId]
                );
            }
        }
    }

    protected function resolveStatus(string $sourceStatus, string $defaultHandling): string
    {
        if ($defaultHandling === 'draft') {
            return 'draft';
        }
        if ($defaultHandling === 'published') {
            return 'published';
        }

        return match (strtolower($sourceStatus)) {
            'published', 'publish' => 'published',
            'pending'              => 'pending',
            'trash'                => 'trash',
            default                => 'draft',
        };
    }

    protected function generateUniqueSlug(string $table, string $source): string
    {
        $base = $this->slugify($source);
        if ($base === '') $base = 'item';

        if (!$this->db) return $base;

        $slug = $base;
        $counter = 1;

        while (true) {
            $exists = $this->db->selectOne("SELECT id FROM `{$table}` WHERE `slug` = ? LIMIT 1", [$slug]);
            if (!$exists) {
                return $slug;
            }
            $counter++;
            $slug = $base . '-' . $counter;
        }
    }

    protected function generateUniqueUsername(string $source): string
    {
        $base = $this->slugify($source);
        if ($base === '') $base = 'author';

        if (!$this->db) return $base;

        $username = $base;
        $counter = 1;

        while (true) {
            $exists = $this->db->selectOne("SELECT id FROM `users` WHERE `username` = ? LIMIT 1", [$username]);
            if (!$exists) {
                return $username;
            }
            $counter++;
            $username = $base . $counter;
        }
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
