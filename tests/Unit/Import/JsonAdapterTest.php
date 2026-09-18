<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Services\Import\Adapters\JsonAdapter;
use PHPUnit\Framework\TestCase;

class JsonAdapterTest extends TestCase
{
    protected JsonAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new JsonAdapter();
    }

    public function testDetectsValidJsonContentExport(): void
    {
        $json = json_encode([
            'generator' => 'Favorite CMS Universal Exporter',
            'version'   => '1.0',
            'posts'     => [
                ['title' => 'Sample Post', 'content' => '<p>Hello world</p>'],
            ],
        ]);

        $this->assertTrue($this->adapter->detect($json));
        $this->assertFalse($this->adapter->detect('<html>Not json</html>'));
    }

    public function testParsesNormalizedJsonData(): void
    {
        $json = json_encode([
            'version'    => '1.0',
            'site'       => ['title' => 'JSON Blog', 'url' => 'https://jsonblog.test'],
            'taxonomies' => [
                ['name' => 'Guides', 'slug' => 'guides', 'type' => 'category'],
            ],
            'posts' => [
                [
                    'id'           => '101',
                    'title'        => 'First Universal JSON Post',
                    'slug'         => 'first-json-post',
                    'content'      => '<p>Article body here</p>',
                    'status'       => 'published',
                    'published_at' => '2024-05-01 10:00:00',
                    'author'       => ['name' => 'Sarah Admin', 'email' => 'sarah@test.com'],
                    'categories'   => ['Guides'],
                    'tags'         => ['JSON', 'CMS'],
                ],
            ],
            'pages' => [
                [
                    'id'      => '201',
                    'title'   => 'Privacy Statement',
                    'slug'    => 'privacy',
                    'content' => '<p>Privacy text.</p>',
                    'status'  => 'published',
                ],
            ],
            'comments' => [
                [
                    'id'          => '301',
                    'post_id'     => '101',
                    'author_name' => 'Mark User',
                    'content'     => 'Great post!',
                ],
            ],
        ]);

        $validation = $this->adapter->validate($json);
        $this->assertTrue($validation['valid']);

        $parsed = $this->adapter->parse($json);
        $this->assertSame('json', $parsed->sourceId);
        $this->assertSame('JSON Blog', $parsed->sourceMetadata['site_title'] ?? '');

        // Posts
        $this->assertCount(1, $parsed->posts);
        $this->assertSame('First Universal JSON Post', $parsed->posts[0]->title);
        $this->assertSame('Sarah Admin', $parsed->posts[0]->authorName);
        $this->assertContains('Guides', $parsed->posts[0]->categories);
        $this->assertContains('JSON', $parsed->posts[0]->tags);

        // Pages
        $this->assertCount(1, $parsed->pages);
        $this->assertSame('Privacy Statement', $parsed->pages[0]->title);

        // Comments
        $this->assertCount(1, $parsed->comments);
        $this->assertSame('Mark User', $parsed->comments[0]->authorName);
    }

    public function testRejectsMalformedJson(): void
    {
        $validation = $this->adapter->validate('{ invalid json ]');
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }
}
