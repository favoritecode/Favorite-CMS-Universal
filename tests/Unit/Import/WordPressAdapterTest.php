<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Import;

use FavoriteCMS\Services\Import\Adapters\WordPressAdapter;
use PHPUnit\Framework\TestCase;

class WordPressAdapterTest extends TestCase
{
    protected WordPressAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new WordPressAdapter();
    }

    protected function getSampleWxrXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/"
>
<channel>
    <title>WordPress Test Site</title>
    <link>https://example.org</link>
    <description>Just another WordPress site</description>
    <wp:wxr_version>1.2</wp:wxr_version>

    <!-- Authors -->
    <wp:author>
        <wp:author_id>1</wp:author_id>
        <wp:author_login><![CDATA[johndoe]]></wp:author_login>
        <wp:author_email><![CDATA[john@example.org]]></wp:author_email>
        <wp:author_display_name><![CDATA[John Doe]]></wp:author_display_name>
    </wp:author>

    <!-- Categories -->
    <wp:category>
        <wp:term_id>3</wp:term_id>
        <wp:category_nicename><![CDATA[engineering]]></wp:category_nicename>
        <wp:category_parent><![CDATA[]]></wp:category_parent>
        <wp:cat_name><![CDATA[Engineering]]></wp:cat_name>
    </wp:category>

    <!-- Attachment / Media Item -->
    <item>
        <title><![CDATA[Hero Image]]></title>
        <wp:post_id>50</wp:post_id>
        <wp:post_type><![CDATA[attachment]]></wp:post_type>
        <wp:attachment_url><![CDATA[https://example.org/wp-content/uploads/2024/03/hero.jpg]]></wp:attachment_url>
    </item>

    <!-- Standard Blog Post with Attached Media -->
    <item>
        <title><![CDATA[Migrating from WordPress to Favorite CMS]]></title>
        <link>https://example.org/2024/03/migrating-to-favorite-cms/</link>
        <pubDate>Mon, 18 Mar 2024 14:00:00 +0000</pubDate>
        <dc:creator><![CDATA[johndoe]]></dc:creator>
        <guid isPermaLink="false">https://example.org/?p=101</guid>
        <description></description>
        <content:encoded><![CDATA[<p>WordPress migration works beautifully. <img src="https://example.org/wp-content/uploads/2024/03/inline.png" alt="Architecture" /></p>]]></content:encoded>
        <excerpt:encoded><![CDATA[A guide on moving your content.]]></excerpt:encoded>
        <wp:post_id>101</wp:post_id>
        <wp:post_date><![CDATA[2024-03-18 14:00:00]]></wp:post_date>
        <wp:post_name><![CDATA[migrating-to-favorite-cms]]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_type><![CDATA[post]]></wp:post_type>
        <wp:postmeta>
            <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
            <wp:meta_value><![CDATA[50]]></wp:meta_value>
        </wp:postmeta>
        <category domain="category" nicename="engineering"><![CDATA[Engineering]]></category>
        <category domain="post_tag" nicename="cms"><![CDATA[CMS]]></category>

        <!-- Comment -->
        <wp:comment>
            <wp:comment_id>201</wp:comment_id>
            <wp:comment_author><![CDATA[Sarah Smith]]></wp:comment_author>
            <wp:comment_author_email><![CDATA[sarah@example.org]]></wp:comment_author_email>
            <wp:comment_date><![CDATA[2024-03-19 09:15:00]]></wp:comment_date>
            <wp:comment_content><![CDATA[Very excited about the universal migration!]]></wp:comment_content>
            <wp:comment_approved><![CDATA[1]]></wp:comment_approved>
            <wp:comment_parent>0</wp:comment_parent>
        </wp:comment>
    </item>

    <!-- Hierarchical Standalone Pages (Parent & Child) -->
    <item>
        <title><![CDATA[Company Info]]></title>
        <link>https://example.org/company/</link>
        <wp:post_id>301</wp:post_id>
        <wp:post_date><![CDATA[2024-01-10 10:00:00]]></wp:post_date>
        <wp:post_name><![CDATA[company]]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_type><![CDATA[page]]></wp:post_type>
        <wp:post_parent>0</wp:post_parent>
        <wp:menu_order>1</wp:menu_order>
        <content:encoded><![CDATA[<p>About our company.</p>]]></content:encoded>
    </item>

    <item>
        <title><![CDATA[Our Team]]></title>
        <link>https://example.org/company/team/</link>
        <wp:post_id>302</wp:post_id>
        <wp:post_date><![CDATA[2024-01-11 11:00:00]]></wp:post_date>
        <wp:post_name><![CDATA[team]]></wp:post_name>
        <wp:status><![CDATA[publish]]></wp:status>
        <wp:post_type><![CDATA[page]]></wp:post_type>
        <wp:post_parent>301</wp:post_parent>
        <wp:menu_order>2</wp:menu_order>
        <content:encoded><![CDATA[<p>Meet our engineers.</p>]]></content:encoded>
    </item>
</channel>
</rss>
XML;
    }

    public function testDetectsWordPressWxr(): void
    {
        $xml = $this->getSampleWxrXml();
        $this->assertTrue($this->adapter->detect($xml));
        $this->assertFalse($this->adapter->detect('<feed xmlns="http://www.w3.org/2005/Atom"></feed>'));
    }

    public function testParsesWxrPostsPagesCommentsTaxonomiesAndMedia(): void
    {
        $xml = $this->getSampleWxrXml();
        $validation = $this->adapter->validate($xml);
        $this->assertTrue($validation['valid']);

        $parsed = $this->adapter->parse($xml);
        $this->assertSame('wordpress', $parsed->sourceId);
        $this->assertSame('WordPress Test Site', $parsed->sourceMetadata['site_title'] ?? '');
        $this->assertSame('1.2', $parsed->sourceVersion);

        // Authors
        $this->assertCount(1, $parsed->authors);
        $this->assertSame('John Doe', $parsed->authors[0]->name);
        $this->assertSame('johndoe', $parsed->authors[0]->username);

        // Posts
        $this->assertCount(1, $parsed->posts);
        $post = $parsed->posts[0];
        $this->assertSame('Migrating from WordPress to Favorite CMS', $post->title);
        $this->assertSame('migrating-to-favorite-cms', $post->slug);
        $this->assertSame('published', $post->status);
        $this->assertSame('https://example.org/wp-content/uploads/2024/03/hero.jpg', $post->featuredImageUrl);
        $this->assertContains('Engineering', $post->categories);
        $this->assertContains('CMS', $post->tags);
        $this->assertContains('https://example.org/wp-content/uploads/2024/03/inline.png', $post->inlineMediaUrls);

        // Pages (Parent & Child)
        $this->assertCount(2, $parsed->pages);
        $parentPage = $parsed->pages[0];
        $childPage = $parsed->pages[1];
        $this->assertSame('Company Info', $parentPage->title);
        $this->assertNull($parentPage->parentSourceId);

        $this->assertSame('Our Team', $childPage->title);
        $this->assertSame('301', $childPage->parentSourceId);
        $this->assertSame(2, $childPage->menuOrder);

        // Comments
        $this->assertCount(1, $parsed->comments);
        $comment = $parsed->comments[0];
        $this->assertSame('Sarah Smith', $comment->authorName);
        $this->assertSame('sarah@example.org', $comment->authorEmail);
        $this->assertSame('approved', $comment->status);
        $this->assertSame('101', $comment->postSourceId);

        // Media references (hero attachment + inline image)
        $this->assertGreaterThanOrEqual(2, count($parsed->media));
    }
}
