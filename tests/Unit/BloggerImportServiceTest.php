<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\BloggerImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BloggerImportServiceTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static int $testUserId;
    protected BloggerImportService $service;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        // Ensure we have a test user for foreign keys
        $unique = 'blogger_tester_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Secret123!', PASSWORD_DEFAULT);

        static::$testUserId = static::$db->insert('users', [
            'username'          => $unique,
            'name'              => 'Blogger Tester',
            'email'             => $unique . '@example.com',
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(static::$testUserId) && static::$testUserId > 0) {
            static::$db->execute("DELETE FROM users WHERE id = ?", [static::$testUserId]);
        }
    }

    protected function setUp(): void
    {
        $this->service = new BloggerImportService(static::$app);
    }

    protected function getSampleBloggerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
      xmlns:app="http://purl.org/atom/app#"
      xmlns:thr="http://purl.org/syndication/thread/1.0">
  <title type="text">My Blogger Blog</title>
  <subtitle type="html">A sample blog on Blogspot</subtitle>

  <!-- Published Post -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.post-1001</id>
    <published>2023-01-15T10:00:00.000Z</published>
    <updated>2023-01-15T12:00:00.000Z</updated>
    <title type="text">First Test Post</title>
    <content type="html">&lt;p&gt;Welcome to my Blogger post content!&lt;/p&gt;</content>
    <link rel="alternate" type="text/html" href="https://example.blogspot.com/2023/01/first-test-post.html"/>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>
    <category scheme="http://www.blogger.com/atom/ns#" term="Technology"/>
    <category scheme="http://www.blogger.com/atom/ns#" term="Tutorials"/>
    <author>
      <name>John Blogger</name>
      <email>john@example.com</email>
    </author>
  </entry>

  <!-- Draft Post -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.post-1002</id>
    <published>2023-02-01T08:00:00.000Z</published>
    <updated>2023-02-01T08:30:00.000Z</updated>
    <title type="text">Draft Thoughts</title>
    <content type="html">&lt;p&gt;This is an unpublished draft note.&lt;/p&gt;</content>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>
    <category scheme="http://www.blogger.com/atom/ns#" term="Personal"/>
    <app:control>
      <app:draft>yes</app:draft>
    </app:control>
    <author>
      <name>John Blogger</name>
    </author>
  </entry>

  <!-- Standalone Page -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.page-2001</id>
    <published>2023-01-10T09:00:00.000Z</published>
    <updated>2023-01-10T09:00:00.000Z</updated>
    <title type="text">About Our Team</title>
    <content type="html">&lt;p&gt;Learn all about our wonderful team here.&lt;/p&gt;</content>
    <link rel="alternate" type="text/html" href="https://example.blogspot.com/p/about-our-team.html"/>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#page"/>
    <author>
      <name>Jane Editor</name>
    </author>
  </entry>

  <!-- Approved Comment on Post 1001 -->
  <entry>
    <id>tag:blogger.com,1999:blog-123.comment-3001</id>
    <published>2023-01-16T14:22:00.000Z</published>
    <updated>2023-01-16T14:22:00.000Z</updated>
    <title type="text">Great post!</title>
    <content type="html">Thanks for sharing this tutorial!</content>
    <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#comment"/>
    <thr:in-reply-to ref="tag:blogger.com,1999:blog-123.post-1001"/>
    <author>
      <name>Reader Bob</name>
      <email>bob@example.com</email>
    </author>
  </entry>
</feed>
XML;
    }

    public function testPreviewParsesBloggerAtomXmlSuccessfully(): void
    {
        $xml = $this->getSampleBloggerXml();
        $preview = $this->service->preview($xml);

        $this->assertTrue($preview['success']);
        $this->assertSame(2, $preview['counts']['posts']);
        $this->assertSame(1, $preview['counts']['posts_published']);
        $this->assertSame(1, $preview['counts']['posts_draft']);
        $this->assertSame(1, $preview['counts']['pages']);
        $this->assertSame(1, $preview['counts']['pages_published']);
        $this->assertSame(0, $preview['counts']['pages_draft']);
        $this->assertSame(1, $preview['counts']['comments']);
        $this->assertSame(3, $preview['counts']['tags']);

        $this->assertCount(2, $preview['sample_posts']);
        $this->assertSame('First Test Post', $preview['sample_posts'][0]['title']);
        $this->assertSame('first-test-post', $preview['sample_posts'][0]['slug']);
        $this->assertSame('published', $preview['sample_posts'][0]['status']);
        $this->assertContains('Technology', $preview['sample_posts'][0]['tags']);
        $this->assertSame('John Blogger', $preview['sample_posts'][0]['author']);

        $this->assertSame('Draft Thoughts', $preview['sample_posts'][1]['title']);
        $this->assertSame('draft', $preview['sample_posts'][1]['status']);
    }

    public function testParseOrganizesEntriesCorrectly(): void
    {
        $xml = $this->getSampleBloggerXml();
        $data = $this->service->parse($xml);

        $this->assertCount(2, $data['posts']);
        $this->assertCount(1, $data['pages']);
        $this->assertCount(1, $data['comments']);

        // Check page details
        $page = $data['pages'][0];
        $this->assertSame('About Our Team', $page['title']);
        $this->assertSame('about-our-team', $page['slug']);
        $this->assertSame('published', $page['status']);
        $this->assertStringContainsString('wonderful team', $page['content']);

        // Check comment details
        $comment = $data['comments'][0];
        $this->assertSame('Reader Bob', $comment['author_name']);
        $this->assertSame('bob@example.com', $comment['author_email']);
        $this->assertSame('tag:blogger.com,1999:blog-123.post-1001', $comment['in_reply_to_ref']);
        $this->assertStringContainsString('tutorial', $comment['content']);
    }

    public function testPreviewFailsOnEmptyXml(): void
    {
        $preview = $this->service->preview('');
        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('empty', $preview['error']);
    }

    public function testPreviewFailsOnNonAtomXml(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>RSS</title></channel></rss>';
        $preview = $this->service->preview($xml);
        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('not a valid Atom feed', $preview['error']);
    }

    public function testPreviewFailsOnMalformedXml(): void
    {
        $xml = '<feed><entry><title>Missing closing tags';
        $preview = $this->service->preview($xml);
        $this->assertFalse($preview['success']);
        $this->assertStringContainsString('Invalid XML structure', $preview['error']);
    }

    public function testXxeProtectionRejectsDoctypeEntityDeclarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('disallowed DOCTYPE or ENTITY definitions');

        $xxePayload = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE test [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>&xxe;</title>
</feed>
XML;

        $this->service->loadXml($xxePayload);
    }

    public function testImportExecutesAndCreatesDatabaseRecords(): void
    {
        $xml = $this->getSampleBloggerXml();

        $result = $this->service->import($xml, [
            'author_id'       => static::$testUserId,
            'import_posts'    => true,
            'import_pages'    => true,
            'import_comments' => true,
            'default_status'  => 'preserve',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['counts']['posts']);
        $this->assertSame(1, $result['counts']['pages']);
        $this->assertSame(1, $result['counts']['comments']);
        $this->assertGreaterThanOrEqual(1, $result['counts']['tags']);

        // Verify post in database
        $postRow = static::$db->selectOne("SELECT * FROM posts WHERE title = 'First Test Post' AND author_id = ?", [static::$testUserId]);
        $this->assertNotNull($postRow);
        $this->assertSame('published', $postRow->status);
        $this->assertStringContainsString('Welcome to my Blogger post', $postRow->content);

        // Verify draft post in database
        $draftRow = static::$db->selectOne("SELECT * FROM posts WHERE title = 'Draft Thoughts' AND author_id = ?", [static::$testUserId]);
        $this->assertNotNull($draftRow);
        $this->assertSame('draft', $draftRow->status);

        // Verify page in database
        $pageRow = static::$db->selectOne("SELECT * FROM pages WHERE title = 'About Our Team' AND author_id = ?", [static::$testUserId]);
        $this->assertNotNull($pageRow);
        $this->assertSame('published', $pageRow->status);
        $this->assertStringContainsString('wonderful team', $pageRow->content);

        // Verify comment in database
        $commentRow = static::$db->selectOne("SELECT * FROM comments WHERE post_id = ?", [(int)$postRow->id]);
        $this->assertNotNull($commentRow);
        $this->assertSame('Reader Bob', $commentRow->author_name);
        $this->assertSame('approved', $commentRow->status);
        $this->assertStringContainsString('Thanks for sharing this tutorial!', $commentRow->content);

        // Clean up imported records
        static::$db->execute("DELETE FROM comments WHERE post_id = ?", [(int)$postRow->id]);
        static::$db->execute("DELETE FROM post_taxonomies WHERE post_id IN (?, ?)", [(int)$postRow->id, (int)$draftRow->id]);
        static::$db->execute("DELETE FROM posts WHERE id IN (?, ?)", [(int)$postRow->id, (int)$draftRow->id]);
        static::$db->execute("DELETE FROM pages WHERE id = ?", [(int)$pageRow->id]);
    }
}
