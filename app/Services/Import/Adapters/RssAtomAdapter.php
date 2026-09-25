<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Adapters;

use DOMElement;
use FavoriteCMS\Services\Import\Contracts\ImporterInterface;
use FavoriteCMS\Services\Import\Models\NormalizedAuthor;
use FavoriteCMS\Services\Import\Models\NormalizedImport;
use FavoriteCMS\Services\Import\Models\NormalizedMedia;
use FavoriteCMS\Services\Import\Models\NormalizedPost;
use FavoriteCMS\Services\Import\Models\NormalizedTaxonomy;
use FavoriteCMS\Services\Import\Security\SafeXmlParser;
use Throwable;

class RssAtomAdapter implements ImporterInterface
{
    public function getId(): string
    {
        return 'rss_atom';
    }

    public function getName(): string
    {
        return 'Generic RSS 2.0 / Atom 1.0 Feed';
    }

    public function getDescription(): string
    {
        return 'Import articles from any standard RSS 2.0 or Atom 1.0 XML web syndication feed.';
    }

    public function getSupportedExtensions(): array
    {
        return ['xml', 'rss', 'atom'];
    }

    public function detect(string $content, ?string $filename = null, ?string $mimeType = null): bool
    {
        $snippet = substr($content, 0, 4096);

        // Exclude WordPress and Blogger specific exports
        if (str_contains($snippet, 'wordpress.org/export/') || str_contains($snippet, 'schemas.google.com/blogger')) {
            return false;
        }

        if (str_contains($snippet, '<rss') || str_contains($snippet, '<feed')) {
            return true;
        }

        return false;
    }

    public function validate(string $content): array
    {
        $errors = [];
        $warnings = [];

        try {
            $doc = SafeXmlParser::parse($content);
            $root = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);
            if ($root !== 'rss' && $root !== 'feed') {
                $errors[] = "Root XML element is '<{$root}>', expected '<rss>' or '<feed>' for a syndication feed.";
            }
        } catch (Throwable $e) {
            $errors[] = 'Feed XML validation error: ' . $e->getMessage();
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

        $root = strtolower($doc->documentElement->localName ?? $doc->documentElement->nodeName);
        $isAtom = ($root === 'feed');

        $import = new NormalizedImport();
        $import->sourceId = 'rss_atom';
        $import->sourceName = $isAtom ? 'Atom 1.0 Syndication Feed' : 'RSS 2.0 Syndication Feed';

        if ($isAtom) {
            $this->parseAtomFeed($xpath, $import);
        } else {
            $this->parseRssFeed($xpath, $import);
        }

        return $import;
    }

    protected function parseRssFeed(\DOMXPath $xpath, NormalizedImport $import): void
    {
        $titleNode = $xpath->query('/rss/channel/title')->item(0);
        if ($titleNode) {
            $import->sourceMetadata['site_title'] = trim($titleNode->textContent);
        }

        $items = $xpath->query('/rss/channel/item');
        if ($items === false) return;

        foreach ($items as $item) {
            if (!($item instanceof DOMElement)) continue;

            $title = $this->getChildText($item, 'title');
            $link = $this->getChildText($item, 'link');
            $guid = $this->getChildText($item, 'guid') ?: $link;
            $pubDate = $this->getChildText($item, 'pubDate');
            $creator = $this->getChildText($item, 'creator') ?: $this->getChildText($item, 'author');

            // Content priority: content:encoded > description
            $contentNode = $xpath->query('.//content:encoded', $item)->item(0);
            $content = $contentNode ? $contentNode->textContent : $this->getChildText($item, 'description');
            $excerpt = $this->getChildText($item, 'description');
            if ($contentNode && $excerpt === $content) {
                $excerpt = '';
            }

            // Categories
            $categories = [];
            $catNodes = $item->getElementsByTagName('category');
            foreach ($catNodes as $cn) {
                $catName = trim($cn->textContent);
                if ($catName !== '') {
                    $categories[] = $catName;
                    $import->addTaxonomy(new NormalizedTaxonomy([
                        'name' => $catName,
                        'slug' => $this->slugify($catName),
                        'type' => 'category',
                    ]));
                }
            }

            // Enclosures / media:content
            $enclosureUrl = null;
            $encNodes = $item->getElementsByTagName('enclosure');
            foreach ($encNodes as $enc) {
                $encType = strtolower($enc->getAttribute('type'));
                $encUrl = $enc->getAttribute('url');
                if (str_starts_with($encType, 'image/') && $encUrl !== '') {
                    $enclosureUrl = $encUrl;
                    break;
                }
            }

            // Inline media
            $mediaUrls = $this->extractImageUrls($content);
            if ($enclosureUrl && !in_array($enclosureUrl, $mediaUrls, true)) {
                $mediaUrls[] = $enclosureUrl;
            }

            foreach ($mediaUrls as $u) {
                $import->addMedia(new NormalizedMedia(['sourceUrl' => $u]));
            }

            if ($creator !== '') {
                $import->addAuthor(new NormalizedAuthor(['name' => $creator]));
            }

            $date = date('Y-m-d H:i:s');
            if (!empty($pubDate)) {
                $t = strtotime($pubDate);
                if ($t !== false) $date = date('Y-m-d H:i:s', $t);
            }

            $slug = '';
            if ($link) {
                $path = parse_url($link, PHP_URL_PATH);
                if ($path) {
                    $slug = basename($path, '.html');
                }
            }

            $import->addPost(new NormalizedPost([
                'sourceId'         => $guid ?: bin2hex(random_bytes(6)),
                'sourceGuid'       => $guid,
                'sourceUrl'        => $link,
                'title'            => $title ?: '(Untitled Item)',
                'slug'             => $slug ?: $this->slugify($title ?: 'item'),
                'content'          => $content,
                'excerpt'          => $excerpt,
                'status'           => 'published',
                'authorName'       => $creator ?: 'Admin',
                'publishedAt'      => $date,
                'createdAt'        => $date,
                'categories'       => array_values(array_unique($categories)),
                'featuredImageUrl' => $enclosureUrl ?: (!empty($mediaUrls) ? $mediaUrls[0] : null),
                'inlineMediaUrls'  => $mediaUrls,
            ]));
        }
    }

    protected function parseAtomFeed(\DOMXPath $xpath, NormalizedImport $import): void
    {
        $titleNode = $xpath->query('/atom:feed/atom:title')->item(0);
        if ($titleNode) {
            $import->sourceMetadata['site_title'] = trim($titleNode->textContent);
        }

        $entries = $xpath->query('//atom:entry');
        if ($entries === false) return;

        foreach ($entries as $entry) {
            if (!($entry instanceof DOMElement)) continue;

            $id = $this->getChildText($entry, 'id');
            $title = $this->getChildText($entry, 'title');
            $contentNode = $entry->getElementsByTagName('content')->item(0);
            $content = $contentNode ? $contentNode->textContent : $this->getChildText($entry, 'summary');
            $summary = $this->getChildText($entry, 'summary');

            $pubNode = $entry->getElementsByTagName('published')->item(0) ?: $entry->getElementsByTagName('updated')->item(0);
            $date = date('Y-m-d H:i:s');
            if ($pubNode && !empty($pubNode->textContent)) {
                $t = strtotime($pubNode->textContent);
                if ($t !== false) $date = date('Y-m-d H:i:s', $t);
            }

            $author = '';
            $authorNode = $entry->getElementsByTagName('author')->item(0);
            if ($authorNode) {
                $nameNode = $authorNode->getElementsByTagName('name')->item(0);
                if ($nameNode) $author = trim($nameNode->textContent);
            }

            $link = '';
            $links = $entry->getElementsByTagName('link');
            foreach ($links as $l) {
                if ($l->getAttribute('rel') === 'alternate' || $l->getAttribute('rel') === '') {
                    $link = $l->getAttribute('href');
                    break;
                }
            }

            $categories = [];
            $catNodes = $entry->getElementsByTagName('category');
            foreach ($catNodes as $cn) {
                $term = $cn->getAttribute('term') ?: trim($cn->textContent);
                if ($term !== '') {
                    $categories[] = $term;
                    $import->addTaxonomy(new NormalizedTaxonomy([
                        'name' => $term,
                        'slug' => $this->slugify($term),
                        'type' => 'category',
                    ]));
                }
            }

            $mediaUrls = $this->extractImageUrls($content);
            foreach ($mediaUrls as $u) {
                $import->addMedia(new NormalizedMedia(['sourceUrl' => $u]));
            }

            if ($author !== '') {
                $import->addAuthor(new NormalizedAuthor(['name' => $author]));
            }

            $slug = '';
            if ($link) {
                $path = parse_url($link, PHP_URL_PATH);
                if ($path) {
                    $slug = basename($path, '.html');
                }
            }

            $import->addPost(new NormalizedPost([
                'sourceId'         => $id ?: bin2hex(random_bytes(6)),
                'sourceGuid'       => $id,
                'sourceUrl'        => $link,
                'title'            => $title ?: '(Untitled Entry)',
                'slug'             => $slug ?: $this->slugify($title ?: 'entry'),
                'content'          => $content,
                'excerpt'          => $summary,
                'status'           => 'published',
                'authorName'       => $author ?: 'Admin',
                'publishedAt'      => $date,
                'createdAt'        => $date,
                'categories'       => array_values(array_unique($categories)),
                'featuredImageUrl' => !empty($mediaUrls) ? $mediaUrls[0] : null,
                'inlineMediaUrls'  => $mediaUrls,
            ]));
        }
    }

    protected function getChildText(DOMElement $element, string $tagName): string
    {
        $nodes = $element->getElementsByTagName($tagName);
        if ($nodes->length > 0 && $nodes->item(0)) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
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
