<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Adapters;

use DOMElement;
use DOMXPath;
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

class WordPressAdapter implements ImporterInterface
{
    public function getId(): string
    {
        return 'wordpress';
    }

    public function getName(): string
    {
        return 'WordPress (WXR XML)';
    }

    public function getDescription(): string
    {
        return 'Import from official WordPress eXtended RSS (WXR) XML export files (WordPress 2.x - 6.x) generated via Tools → Export in the WordPress dashboard.';
    }

    public function getSupportedExtensions(): array
    {
        return ['xml'];
    }

    public function detect(string $content, ?string $filename = null, ?string $mimeType = null): bool
    {
        $snippet = substr($content, 0, 4096);
        return str_contains($snippet, 'wordpress.org/export/')
            || str_contains($snippet, '<wp:wxr_version>')
            || (str_contains($snippet, '<rss') && str_contains($content, '<wp:post_type>'));
    }

    public function validate(string $content): array
    {
        $errors = [];
        $warnings = [];

        try {
            $doc = SafeXmlParser::parse($content);
            $root = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);
            if ($root !== 'rss') {
                $errors[] = "Root element is '<{$root}>', expected '<rss>' for a WordPress WXR export.";
            }

            // Check for WordPress export namespace or elements
            $hasWp = str_contains($content, 'wordpress.org/export/') || str_contains($content, '<wp:');
            if (!$hasWp) {
                $warnings[] = "Document does not appear to contain standard WordPress WXR namespace definitions.";
            }
        } catch (Throwable $e) {
            $errors[] = 'WordPress XML validation error: ' . $e->getMessage();
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
        $import->sourceId = 'wordpress';
        $import->sourceName = 'WordPress WXR';

        // 1. Site Metadata & WXR Version
        $titleNode = $xpath->query('/rss/channel/title')->item(0);
        if ($titleNode) {
            $import->sourceMetadata['site_title'] = trim($titleNode->textContent);
        }

        $wxrVerNode = $this->queryFirst($xpath, ['//wp:wxr_version', '//wp11:wxr_version', '//wp10:wxr_version']);
        if ($wxrVerNode) {
            $import->sourceVersion = trim($wxrVerNode->textContent);
        }

        // 2. Authors
        $authors = $this->queryAll($xpath, ['//wp:author', '//wp11:author', '//wp10:author']);
        foreach ($authors as $authElem) {
            if (!($authElem instanceof DOMElement)) continue;

            $login = $this->getChildText($authElem, 'author_login');
            $email = $this->getChildText($authElem, 'author_email');
            $displayName = $this->getChildText($authElem, 'author_display_name');

            $import->addAuthor(new NormalizedAuthor([
                'name'     => $displayName ?: $login,
                'username' => $login,
                'email'    => $email,
            ]));
        }

        // 3. Categories & Tags defined in header
        $categories = $this->queryAll($xpath, ['//wp:category', '//wp11:category', '//wp10:category']);
        foreach ($categories as $catElem) {
            if (!($catElem instanceof DOMElement)) continue;
            $nicename = $this->getChildText($catElem, 'category_nicename');
            $catName = $this->getChildText($catElem, 'cat_name');
            if ($catName !== '') {
                $import->addTaxonomy(new NormalizedTaxonomy([
                    'name' => $catName,
                    'slug' => $nicename ?: $this->slugify($catName),
                    'type' => 'category',
                ]));
            }
        }

        $tags = $this->queryAll($xpath, ['//wp:tag', '//wp11:tag', '//wp10:tag']);
        foreach ($tags as $tagElem) {
            if (!($tagElem instanceof DOMElement)) continue;
            $slug = $this->getChildText($tagElem, 'tag_slug');
            $name = $this->getChildText($tagElem, 'tag_name');
            if ($name !== '') {
                $import->addTaxonomy(new NormalizedTaxonomy([
                    'name' => $name,
                    'slug' => $slug ?: $this->slugify($name),
                    'type' => 'tag',
                ]));
            }
        }

        // Map attachment IDs to remote URLs (for featured image resolution)
        $attachmentsMap = [];

        // 4. First Pass: Collect Attachments
        $items = $xpath->query('/rss/channel/item');
        if ($items !== false) {
            foreach ($items as $item) {
                if (!($item instanceof DOMElement)) continue;
                $postType = $this->getChildText($item, 'post_type');
                if ($postType === 'attachment') {
                    $postId = $this->getChildText($item, 'post_id');
                    $attachmentUrl = $this->getChildText($item, 'attachment_url');
                    if ($postId !== '' && $attachmentUrl !== '') {
                        $attachmentsMap[$postId] = $attachmentUrl;
                        $import->addMedia(new NormalizedMedia([
                            'sourceId'  => $postId,
                            'sourceUrl' => $attachmentUrl,
                            'title'     => $this->getChildText($item, 'title'),
                        ]));
                    }
                }
            }
        }

        // 5. Second Pass: Process Posts, Pages, Comments
        if ($items !== false) {
            foreach ($items as $item) {
                if (!($item instanceof DOMElement)) continue;

                $postType = $this->getChildText($item, 'post_type');
                if ($postType === 'attachment' || $postType === 'nav_menu_item' || $postType === 'revision') {
                    continue;
                }

                $postId = $this->getChildText($item, 'post_id');
                $title = $this->getChildText($item, 'title');
                $guid = $this->getChildText($item, 'guid');
                $link = $this->getChildText($item, 'link');
                $slug = $this->getChildText($item, 'post_name');
                $status = $this->getChildText($item, 'status');
                $creator = $this->getChildText($item, 'creator');
                $parent = $this->getChildText($item, 'post_parent');
                $menuOrder = (int)$this->getChildText($item, 'menu_order');

                // Content & Excerpt
                $contentNode = $xpath->query('.//content:encoded', $item)->item(0);
                $content = $contentNode ? $contentNode->textContent : '';

                $excerptNode = $xpath->query('.//excerpt:encoded', $item)->item(0);
                $excerpt = $excerptNode ? $excerptNode->textContent : '';

                // Dates
                $postDate = $this->getChildText($item, 'post_date');
                $pubDate = $this->getChildText($item, 'pubDate');
                $resolvedDate = $this->resolveDate($postDate, $pubDate);

                // Featured Image (_thumbnail_id in postmeta)
                $featuredImageUrl = null;
                $postmeta = $item->getElementsByTagName('postmeta');
                foreach ($postmeta as $pm) {
                    $key = $this->getChildText($pm, 'meta_key');
                    $val = $this->getChildText($pm, 'meta_value');
                    if ($key === '_thumbnail_id' && isset($attachmentsMap[$val])) {
                        $featuredImageUrl = $attachmentsMap[$val];
                        break;
                    }
                }

                // Categories & Tags attached to this item
                $itemCategories = [];
                $itemTags = [];
                $catNodes = $item->getElementsByTagName('category');
                foreach ($catNodes as $catNode) {
                    $domain = $catNode->getAttribute('domain');
                    $termName = trim($catNode->textContent);
                    if ($termName === '') continue;

                    if ($domain === 'category') {
                        $itemCategories[] = $termName;
                        $import->addTaxonomy(new NormalizedTaxonomy([
                            'name' => $termName,
                            'slug' => $catNode->getAttribute('nicename') ?: $this->slugify($termName),
                            'type' => 'category',
                        ]));
                    } elseif ($domain === 'post_tag') {
                        $itemTags[] = $termName;
                        $import->addTaxonomy(new NormalizedTaxonomy([
                            'name' => $termName,
                            'slug' => $catNode->getAttribute('nicename') ?: $this->slugify($termName),
                            'type' => 'tag',
                        ]));
                    }
                }

                // Detect inline images
                $mediaUrls = $this->extractImageUrls($content);
                foreach ($mediaUrls as $u) {
                    $import->addMedia(new NormalizedMedia(['sourceUrl' => $u]));
                }

                if ($featuredImageUrl) {
                    $import->addMedia(new NormalizedMedia(['sourceUrl' => $featuredImageUrl]));
                }

                $statusNormalized = match ($status) {
                    'publish'   => 'published',
                    'draft'     => 'draft',
                    'pending'   => 'pending',
                    'trash'     => 'trash',
                    default     => 'draft',
                };

                if ($postType === 'page') {
                    $page = new NormalizedPage([
                        'sourceId'         => $postId,
                        'sourceGuid'       => $guid,
                        'sourceUrl'        => $link,
                        'title'            => $title,
                        'slug'             => $slug ?: $this->slugify($title),
                        'content'          => $content,
                        'excerpt'          => $excerpt,
                        'status'           => $statusNormalized,
                        'parentSourceId'   => ($parent !== '0' && $parent !== '') ? $parent : null,
                        'menuOrder'        => $menuOrder,
                        'authorName'       => $creator,
                        'publishedAt'      => $resolvedDate,
                        'createdAt'        => $resolvedDate,
                        'updatedAt'        => $resolvedDate,
                        'featuredImageUrl' => $featuredImageUrl,
                        'inlineMediaUrls'  => $mediaUrls,
                    ]);
                    $import->addPage($page);

                } else {
                    // Default to post
                    $post = new NormalizedPost([
                        'sourceId'         => $postId,
                        'sourceGuid'       => $guid,
                        'sourceUrl'        => $link,
                        'title'            => $title,
                        'slug'             => $slug ?: $this->slugify($title),
                        'content'          => $content,
                        'excerpt'          => $excerpt,
                        'status'           => $statusNormalized,
                        'authorName'       => $creator,
                        'publishedAt'      => $resolvedDate,
                        'createdAt'        => $resolvedDate,
                        'updatedAt'        => $resolvedDate,
                        'categories'       => array_values(array_unique($itemCategories)),
                        'tags'             => array_values(array_unique($itemTags)),
                        'featuredImageUrl' => $featuredImageUrl,
                        'inlineMediaUrls'  => $mediaUrls,
                    ]);
                    $import->addPost($post);
                }

                // 6. Comments inside item
                $comments = $item->getElementsByTagName('comment');
                foreach ($comments as $comm) {
                    $commId = $this->getChildText($comm, 'comment_id');
                    $commAuthor = $this->getChildText($comm, 'comment_author');
                    $commEmail = $this->getChildText($comm, 'comment_author_email');
                    $commUrl = $this->getChildText($comm, 'comment_author_url');
                    $commIp = $this->getChildText($comm, 'comment_author_IP');
                    $commDate = $this->getChildText($comm, 'comment_date');
                    $commContent = $this->getChildText($comm, 'comment_content');
                    $commApproved = $this->getChildText($comm, 'comment_approved');
                    $commParent = $this->getChildText($comm, 'comment_parent');

                    $commStatus = match ($commApproved) {
                        '1'     => 'approved',
                        'spam'  => 'spam',
                        'trash' => 'trash',
                        default => 'pending',
                    };

                    $import->addComment(new NormalizedComment([
                        'sourceId'       => $commId,
                        'postSourceId'   => $postId,
                        'parentSourceId' => ($commParent !== '0' && $commParent !== '') ? $commParent : null,
                        'authorName'     => $commAuthor ?: 'Anonymous',
                        'authorEmail'    => $commEmail ?: 'anonymous@example.com',
                        'authorUrl'      => $commUrl,
                        'authorIp'       => $commIp,
                        'content'        => $commContent,
                        'status'         => $commStatus,
                        'createdAt'      => $commDate ?: date('Y-m-d H:i:s'),
                    ]));
                }
            }
        }

        return $import;
    }

    protected function getChildText(DOMElement $element, string $tagName): string
    {
        // Try exact tag or with wp: prefix
        $nodes = $element->getElementsByTagName($tagName);
        if ($nodes->length > 0 && $nodes->item(0)) {
            return trim($nodes->item(0)->textContent);
        }

        $nodes = $element->getElementsByTagNameNS('http://wordpress.org/export/1.2/', $tagName);
        if ($nodes->length > 0 && $nodes->item(0)) {
            return trim($nodes->item(0)->textContent);
        }

        return '';
    }

    protected function queryFirst(DOMXPath $xpath, array $queries): ?DOMElement
    {
        foreach ($queries as $q) {
            $res = $xpath->query($q);
            if ($res && $res->length > 0 && $res->item(0) instanceof DOMElement) {
                return $res->item(0);
            }
        }
        return null;
    }

    protected function queryAll(DOMXPath $xpath, array $queries): array
    {
        foreach ($queries as $q) {
            $res = $xpath->query($q);
            if ($res && $res->length > 0) {
                $list = [];
                foreach ($res as $item) {
                    $list[] = $item;
                }
                return $list;
            }
        }
        return [];
    }

    protected function resolveDate(?string $postDate, ?string $pubDate): string
    {
        if (!empty($postDate) && $postDate !== '0000-00-00 00:00:00') {
            $t = strtotime($postDate);
            if ($t !== false) {
                return date('Y-m-d H:i:s', $t);
            }
        }

        if (!empty($pubDate)) {
            $t = strtotime($pubDate);
            if ($t !== false) {
                return date('Y-m-d H:i:s', $t);
            }
        }

        return date('Y-m-d H:i:s');
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
