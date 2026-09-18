<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Services\Import\Adapters\RssAtomAdapter;
use PHPUnit\Framework\TestCase;

class RssAtomAdapterTest extends TestCase
{
    protected RssAtomAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new RssAtomAdapter();
    }

    public function testParsesRss2Feed(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Tech News Feed</title>
    <link>https://technews.test</link>
    <description>Latest in tech</description>
    <item>
      <title>PHP 8.3 Features Released</title>
      <link>https://technews.test/php-8-3-released</link>
      <pubDate>Thu, 23 Nov 2023 12:00:00 GMT</pubDate>
      <creator>Editor Bob</creator>
      <category>PHP</category>
      <category>Programming</category>
      <description>Exciting additions in PHP 8.3.</description>
      <enclosure url="https://technews.test/images/php83.jpg" type="image/jpeg" />
      <guid>https://technews.test/item/100</guid>
    </item>
  </channel>
</rss>
XML;

        $this->assertTrue($this->adapter->detect($rss));
        $parsed = $this->adapter->parse($rss);

        $this->assertSame('Tech News Feed', $parsed->sourceMetadata['site_title'] ?? '');
        $this->assertCount(1, $parsed->posts);

        $post = $parsed->posts[0];
        $this->assertSame('PHP 8.3 Features Released', $post->title);
        $this->assertSame('php-8-3-released', $post->slug);
        $this->assertSame('https://technews.test/images/php83.jpg', $post->featuredImageUrl);
        $this->assertContains('PHP', $post->categories);
        $this->assertContains('Programming', $post->categories);
    }

    public function testParsesAtom1Feed(): void
    {
        $atom = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Developer Journal</title>
  <entry>
    <title>Scaling Web Apps</title>
    <id>urn:uuid:1225c695-cfb8-4ebb-aaaa-80da344efa6a</id>
    <published>2024-02-10T09:00:00Z</published>
    <author><name>Dev Jane</name></author>
    <category term="Architecture" />
    <link rel="alternate" href="https://devjournal.test/scaling-web-apps" />
    <content type="html">&lt;p&gt;Key strategies for scaling.&lt;/p&gt;</content>
  </entry>
</feed>
XML;

        $this->assertTrue($this->adapter->detect($atom));
        $parsed = $this->adapter->parse($atom);

        $this->assertSame('Developer Journal', $parsed->sourceMetadata['site_title'] ?? '');
        $this->assertCount(1, $parsed->posts);

        $post = $parsed->posts[0];
        $this->assertSame('Scaling Web Apps', $post->title);
        $this->assertSame('Dev Jane', $post->authorName);
        $this->assertContains('Architecture', $post->categories);
    }
}
