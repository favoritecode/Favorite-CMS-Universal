<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Services\Import\Adapters\BloggerAdapter;
use PHPUnit\Framework\TestCase;

class BloggerAdapterTest extends TestCase
{
    protected BloggerAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new BloggerAdapter();
    }

    protected function getSampleBloggerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:app="http://purl.org/atom/app#"
      xmlns:thr="http://purl.org/syndication/thread/1.0">
  <title type="text">My Blogger Blog</title>
  
  <!-- Published Post -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.post-1001</id>
    <published>2024-01-15T10:00:00.000Z</published>
    <updated>2024-01-15T12:00:00.000Z</updated>
    <title type="text">First Blogger Post</title>
    <content type="html">&lt;p&gt;Post with image &lt;img src="https://images.unsplash.com/photo-1579546929518-9e396f3cc809?w=500" alt="Gradient" /&gt;&lt;/p&gt;</content>
    <link rel="alternate" type="text/html" href="https://example.blogspot.com/2024/01/first-blogger-post.html"/>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>
    <category scheme="http://www.blogger.com/atom/ns#" term="Technology"/>
    <category scheme="http://www.blogger.com/atom/ns#" term="Tutorials"/>
    <author>
      <name>Alice Blogger</name>
      <email>alice@example.com</email>
    </author>
  </entry>

  <!-- Draft Post -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.post-1002</id>
    <published>2024-02-01T08:00:00.000Z</published>
    <title type="text">Draft Idea</title>
    <content type="html">&lt;p&gt;Unpublished draft content.&lt;/p&gt;</content>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>
    <app:control>
      <app:draft>yes</app:draft>
    </app:control>
    <author>
      <name>Alice Blogger</name>
    </author>
  </entry>

  <!-- Standalone Page -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.page-2001</id>
    <published>2024-01-01T00:00:00.000Z</published>
    <title type="text">About My Journey</title>
    <content type="html">&lt;p&gt;Static page information.&lt;/p&gt;</content>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#page"/>
  </entry>

  <!-- Comment on post 1001 -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.post-3001</id>
    <published>2024-01-16T15:30:00.000Z</published>
    <content type="text">Great write-up on Blogger migration!</content>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#comment"/>
    <thr:in-reply-to ref="tag:blogger.com,1999:blog-123.post-1001"/>
    <author>
      <name>Reader Dave</name>
      <email>dave@example.com</email>
    </author>
  </entry>
</feed>
XML;
    }

    public function testDetectsBloggerFormat(): void
    {
        $xml = $this->getSampleBloggerXml();
        $this->assertTrue($this->adapter->detect($xml));
        $this->assertFalse($this->adapter->detect('<rss version="2.0"><channel></channel></rss>'));
    }

    public function testValidatesAndParsesBloggerContent(): void
    {
        $xml = $this->getSampleBloggerXml();
        $validation = $this->adapter->validate($xml);
        $this->assertTrue($validation['valid']);

        $parsed = $this->adapter->parse($xml);
        $this->assertSame('blogger', $parsed->sourceId);
        $this->assertSame('My Blogger Blog', $parsed->sourceMetadata['site_title'] ?? '');

        // 2 posts (1 published, 1 draft)
        $this->assertCount(2, $parsed->posts);
        $p1 = $parsed->posts[0];
        $this->assertSame('First Blogger Post', $p1->title);
        $this->assertSame('published', $p1->status);
        $this->assertSame('first-blogger-post', $p1->slug);
        $this->assertContains('Technology', $p1->tags);
        $this->assertContains('Tutorials', $p1->tags);
        $this->assertCount(1, $p1->inlineMediaUrls);

        $p2 = $parsed->posts[1];
        $this->assertSame('Draft Idea', $p2->title);
        $this->assertSame('draft', $p2->status);

        // 1 page
        $this->assertCount(1, $parsed->pages);
        $this->assertSame('About My Journey', $parsed->pages[0]->title);

        // 1 comment
        $this->assertCount(1, $parsed->comments);
        $this->assertSame('Reader Dave', $parsed->comments[0]->authorName);
        $this->assertSame('tag:blogger.com,1999:blog-123.post-1001', $parsed->comments[0]->postSourceId);

        // Media extracted
        $this->assertCount(1, $parsed->media);
    }

    protected function getSampleTakeoutBloggerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:blogger="http://schemas.google.com/blogger/2018">
  <title type="text">Takeout Blog</title>

  <!-- Live Post with filename permalink and tags -->
  <entry>
    <id>tag:blogger.com,1999:blog-555.post-101</id>
    <blogger:type>POST</blogger:type>
    <blogger:status>LIVE</blogger:status>
    <title type="text">Advance QR Code Generator</title>
    <content type="html">&lt;p&gt;Generate QR codes easily. &lt;img src="https://images.unsplash.com/photo-1579546929518-9e396f3cc809" /&gt;&lt;/p&gt;</content>
    <blogger:filename>/2026/04/advance-qr-code-generator.html</blogger:filename>
    <blogger:metaDescription>Best QR Code generator tool</blogger:metaDescription>
    <blogger:created>2026-04-04T14:37:40.595Z</blogger:created>
    <published>2026-04-04T14:38:00Z</published>
    <updated>2026-07-21T00:02:39.331Z</updated>
    <category scheme="tag:blogger.com,1999:blog-555" term="Web Tools"/>
    <category scheme="tag:blogger.com,1999:blog-555" term="QR Code Generator"/>
    <author>
      <name>Takeout Author</name>
    </author>
  </entry>

  <!-- Soft Trashed Post -->
  <entry>
    <id>tag:blogger.com,1999:blog-555.post-102</id>
    <blogger:type>POST</blogger:type>
    <blogger:status>SOFT_TRASHED</blogger:status>
    <title type="text">Old Trashed Post</title>
    <content type="html">&lt;p&gt;Archived content.&lt;/p&gt;</content>
    <blogger:filename>/2026/01/old-trashed-post.html</blogger:filename>
    <published>2026-01-01T10:00:00Z</published>
  </entry>

  <!-- Live Standalone Page -->
  <entry>
    <id>tag:blogger.com,1999:blog-555.post-201</id>
    <blogger:type>PAGE</blogger:type>
    <blogger:status>LIVE</blogger:status>
    <title type="text">Contact Us</title>
    <content type="html">&lt;p&gt;Get in touch with our team.&lt;/p&gt;</content>
    <blogger:filename>/p/contact-us.html</blogger:filename>
    <published>2026-05-22T10:37:50Z</published>
  </entry>
</feed>
XML;
    }

    public function testParsesGoogleTakeoutBloggerAtomFormat(): void
    {
        $xml = $this->getSampleTakeoutBloggerXml();
        $this->assertTrue($this->adapter->detect($xml));

        $validation = $this->adapter->validate($xml);
        $this->assertTrue($validation['valid']);

        $parsed = $this->adapter->parse($xml);
        $this->assertSame('Takeout Blog', $parsed->sourceMetadata['site_title'] ?? '');

        // 2 posts (1 published, 1 soft trashed mapped to trash)
        $this->assertCount(2, $parsed->posts);
        $p1 = $parsed->posts[0];
        $this->assertSame('Advance QR Code Generator', $p1->title);
        $this->assertSame('advance-qr-code-generator', $p1->slug);
        $this->assertSame('published', $p1->status);
        $this->assertSame('Best QR Code generator tool', $p1->excerpt);
        $this->assertContains('Web Tools', $p1->tags);
        $this->assertContains('QR Code Generator', $p1->tags);
        $this->assertSame('Takeout Author', $p1->authorName);
        $this->assertCount(1, $p1->inlineMediaUrls);

        $p2 = $parsed->posts[1];
        $this->assertSame('Old Trashed Post', $p2->title);
        $this->assertSame('trash', $p2->status);
        $this->assertSame('old-trashed-post', $p2->slug);

        // 1 page
        $this->assertCount(1, $parsed->pages);
        $pg1 = $parsed->pages[0];
        $this->assertSame('Contact Us', $pg1->title);
        $this->assertSame('contact-us', $pg1->slug);
        $this->assertSame('published', $pg1->status);
    }

    public function testParsesRealGoogleTakeoutZipFixtureIfAvailable(): void
    {
        $fixturePath = 'D:/Downloads/Compressed/takeout-20260904T211210Z-1-001_2.zip';
        if (!file_exists($fixturePath)) {
            $this->markTestSkipped('Real Google Takeout ZIP fixture not found at ' . $fixturePath);
        }

        $engine = new \FavoriteCMS\Services\Import\ImportEngine();
        $rawZip = file_get_contents($fixturePath);

        $preview = $engine->preview($rawZip, null, 'takeout.zip');
        $this->assertTrue($preview['success']);
        $this->assertSame('blogger', $preview['adapter_id']);
        $this->assertSame(25, $preview['counts']['posts']);
        $this->assertSame(7, $preview['counts']['pages']);
        $this->assertGreaterThanOrEqual(10, $preview['counts']['tags']);
    }
}

