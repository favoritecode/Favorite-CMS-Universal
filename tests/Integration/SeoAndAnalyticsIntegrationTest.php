<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Services\FrontendSeoService;
use PHPUnit\Framework\TestCase;

class SeoAndAnalyticsIntegrationTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static int $adminUserId;
    protected static int $testPostId;
    protected static int $draftPostId;
    protected static int $testPageId;
    protected static int $draftPageId;

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);

        // Ensure roles exist
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");

        // Ensure admin user
        $user = static::$db->selectOne("SELECT id FROM `users` WHERE `username` = 'seo_admin_test' LIMIT 1");
        if (!$user) {
            static::$adminUserId = static::$db->insert('users', [
                'username'   => 'seo_admin_test',
                'name'       => 'SEO Admin',
                'email'      => 'seo_admin@example.com',
                'password'   => password_hash('AdminPass123!', PASSWORD_DEFAULT),
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$adminUserId = (int)$user->id;
        }

        $adminRole = static::$db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'admin' LIMIT 1");
        if ($adminRole) {
            static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [static::$adminUserId, $adminRole->id]);
        }

        // Create published test post
        $post = static::$db->selectOne("SELECT id FROM `posts` WHERE `slug` = 'seo-test-article' LIMIT 1");
        if (!$post) {
            static::$testPostId = static::$db->insert('posts', [
                'title'        => 'SEO Test Article <script>alert(1)</script>',
                'slug'         => 'seo-test-article',
                'content'      => '<p>Article content for SEO testing with unicode বাংলা শব্দ।</p>',
                'excerpt'      => 'SEO test article excerpt summary with special characters: "quotes" & \'apos\'.',
                'status'       => 'published',
                'type'         => 'post',
                'author_id'    => static::$adminUserId,
                'published_at' => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$testPostId = (int)$post->id;
        }

        // Create draft test post (to verify excluded from sitemap)
        $draftPost = static::$db->selectOne("SELECT id FROM `posts` WHERE `slug` = 'seo-draft-article' LIMIT 1");
        if (!$draftPost) {
            static::$draftPostId = static::$db->insert('posts', [
                'title'        => 'Draft Hidden Article',
                'slug'         => 'seo-draft-article',
                'content'      => '<p>Hidden draft content.</p>',
                'status'       => 'draft',
                'type'         => 'post',
                'author_id'    => static::$adminUserId,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$draftPostId = (int)$draftPost->id;
        }

        // Create published test page
        $page = static::$db->selectOne("SELECT id FROM `pages` WHERE `slug` = 'seo-test-page' LIMIT 1");
        if (!$page) {
            static::$testPageId = static::$db->insert('pages', [
                'title'      => 'SEO Test Page',
                'slug'       => 'seo-test-page',
                'content'    => '<p>Static page content for SEO testing.</p>',
                'status'     => 'published',
                'author_id'  => static::$adminUserId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$testPageId = (int)$page->id;
        }

        // Create draft test page (to verify excluded from sitemap)
        $draftPage = static::$db->selectOne("SELECT id FROM `pages` WHERE `slug` = 'seo-draft-page' LIMIT 1");
        if (!$draftPage) {
            static::$draftPageId = static::$db->insert('pages', [
                'title'      => 'Draft Hidden Page',
                'slug'       => 'seo-draft-page',
                'content'    => '<p>Hidden draft page.</p>',
                'status'     => 'draft',
                'author_id'  => static::$adminUserId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            static::$draftPageId = (int)$draftPage->id;
        }
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        Setting::clearCache();
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        unset($GLOBALS['favorite_cms_base_path']);
    }

    /**
     * 1. VERIFY SEO/ANALYTICS RUNTIME BEHAVIOR
     */
    public function testAnalyticsRuntimeCombinationsAndEscaping(): void
    {
        // Scenario A: GA4 ON + GTM OFF -> GA4 script appears exactly once
        Setting::set('seo', 'ga4_enabled', 1, 'integer');
        Setting::set('seo', 'ga4_measurement_id', 'G-EXACTLY1');
        Setting::set('seo', 'gtm_enabled', 0, 'integer');
        Setting::set('seo', 'gtm_container_id', '');

        $htmlA = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $this->assertSame(1, substr_count($htmlA, 'https://www.googletagmanager.com/gtag/js?id=G-EXACTLY1'));
        $this->assertStringContainsString("gtag('config', 'G-EXACTLY1');", $htmlA);
        $this->assertStringNotContainsString('googletagmanager.com/gtm.js', $htmlA);
        $this->assertEmpty(FrontendSeoService::renderBodyTags());

        // Scenario B: GA4 OFF + GTM ON -> GTM head integration and body noscript appear
        Setting::set('seo', 'ga4_enabled', 0, 'integer');
        Setting::set('seo', 'ga4_measurement_id', 'G-EXACTLY1');
        Setting::set('seo', 'gtm_enabled', 1, 'integer');
        Setting::set('seo', 'gtm_container_id', 'GTM-HEADBODY1');

        $htmlB = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $bodyB = FrontendSeoService::renderBodyTags();
        $this->assertStringContainsString('googletagmanager.com/gtm.js?id=', $htmlB);
        $this->assertStringContainsString('GTM-HEADBODY1', $htmlB);
        $this->assertStringNotContainsString('gtag/js?id=', $htmlB);
        $this->assertStringContainsString('<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-HEADBODY1"', $bodyB);

        // Scenario C: GA4 ON + GTM ON -> standalone GA4 suppressed, no accidental duplicate GA4
        Setting::set('seo', 'ga4_enabled', 1, 'integer');
        Setting::set('seo', 'ga4_measurement_id', 'G-SUPPRESSED1');
        Setting::set('seo', 'gtm_enabled', 1, 'integer');
        Setting::set('seo', 'gtm_container_id', 'GTM-PRIMARY1');

        $htmlC = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $this->assertStringContainsString('GTM-PRIMARY1', $htmlC);
        $this->assertStringNotContainsString('G-SUPPRESSED1', $htmlC);
        $this->assertStringNotContainsString('googletagmanager.com/gtag/js', $htmlC);

        // Scenario D: GA4 OFF + GTM OFF -> no tracking code rendered
        Setting::set('seo', 'ga4_enabled', 0, 'integer');
        Setting::set('seo', 'ga4_measurement_id', 'G-NOTUSED');
        Setting::set('seo', 'gtm_enabled', 0, 'integer');
        Setting::set('seo', 'gtm_container_id', 'GTM-NOTUSED');

        $htmlD = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $bodyD = FrontendSeoService::renderBodyTags();
        $this->assertStringNotContainsString('googletagmanager.com', $htmlD);
        $this->assertStringNotContainsString('gtag(', $htmlD);
        $this->assertEmpty($bodyD);

        // Scenario E: Admin pages -> no GA4 and no GTM tracking code
        Setting::set('seo', 'ga4_enabled', 1, 'integer');
        Setting::set('seo', 'ga4_measurement_id', 'G-ADMINCHECK');
        Setting::set('seo', 'gtm_enabled', 1, 'integer');
        Setting::set('seo', 'gtm_container_id', 'GTM-ADMINCHECK');

        $_SERVER['REQUEST_URI'] = '/admin/posts';
        $htmlE = FrontendSeoService::renderHeadTags([]);
        $bodyE = FrontendSeoService::renderBodyTags();
        $this->assertStringNotContainsString('G-ADMINCHECK', $htmlE);
        $this->assertStringNotContainsString('GTM-ADMINCHECK', $htmlE);
        $this->assertEmpty($bodyE);
        unset($_SERVER['REQUEST_URI']);
    }

    /**
     * 2. VERIFY SEARCH ENGINE VERIFICATION SECURITY & SANITIZATION
     */
    public function testSearchEngineVerificationSecurity(): void
    {
        // 1. Empty values -> tags omitted
        Setting::set('seo', 'google_site_verification', '');
        Setting::set('seo', 'bing_site_verification', '');

        $html1 = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $this->assertStringNotContainsString('google-site-verification', $html1);
        $this->assertStringNotContainsString('msvalidate.01', $html1);

        // 2. Valid values -> tags rendered properly
        Setting::set('seo', 'google_site_verification', 'google1234567890abcdef');
        Setting::set('seo', 'bing_site_verification', 'bing0987654321fedcba');

        $html2 = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $this->assertStringContainsString('<meta name="google-site-verification" content="google1234567890abcdef">', $html2);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="bing0987654321fedcba">', $html2);

        // 3. Malicious HTML/script input and quotes/special characters
        Setting::set('seo', 'google_site_verification', '"><script>alert("gsc")</script><tag');
        Setting::set('seo', 'bing_site_verification', '"><svg/onload=alert("bing")>');

        $html3 = FrontendSeoService::renderHeadTags(['isHome' => true]);
        // Must be safely attribute escaped; NO executable user input reaches page
        $this->assertStringNotContainsString('<script>alert("gsc")</script>', $html3);
        $this->assertStringNotContainsString('<svg/onload=alert("bing")>', $html3);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(&quot;gsc&quot;)&lt;/script&gt;&lt;tag', $html3);
        $this->assertStringContainsString('&quot;&gt;&lt;svg/onload=alert(&quot;bing&quot;)&gt;', $html3);
    }

    /**
     * 3. VERIFY SITEMAP HTTP BEHAVIOR (ROOT & SUBDIRECTORY)
     */
    public function testSitemapHttpBehaviorRootAndSubdirectory(): void
    {
        // 1. Root /sitemap.xml
        $reqRoot = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/sitemap.xml',
            'HTTP_HOST'      => 'example.org',
            'SERVER_PORT'    => '80',
        ]);
        $resRoot = static::$kernel->handle($reqRoot);
        $this->assertSame(200, $resRoot->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $resRoot->getHeader('Content-Type'));

        $xmlStrRoot = $resRoot->getContent();
        $xmlRoot = simplexml_load_string($xmlStrRoot);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xmlRoot);
        $this->assertSame('urlset', $xmlRoot->getName());
        $this->assertTrue(in_array('http://www.sitemaps.org/schemas/sitemap/0.9', $xmlRoot->getDocNamespaces(true), true));

        // Verify content inclusion and exclusion
        $this->assertStringContainsString('http://example.org/</loc>', $xmlStrRoot);
        $this->assertStringContainsString('http://example.org/post/seo-test-article</loc>', $xmlStrRoot);
        $this->assertStringContainsString('http://example.org/page/seo-test-page</loc>', $xmlStrRoot);
        $this->assertStringNotContainsString('seo-draft-article', $xmlStrRoot);
        $this->assertStringNotContainsString('seo-draft-page', $xmlStrRoot);
        $this->assertStringNotContainsString('/admin', $xmlStrRoot);

        // 2. Subdirectory /cms/sitemap.xml with HTTPS
        $reqSub = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/cms/sitemap.xml',
            'SCRIPT_NAME'    => '/cms/index.php',
            'HTTP_HOST'      => 'example.org',
            'SERVER_PORT'    => '443',
            'HTTPS'          => 'on',
        ]);
        $resSub = static::$kernel->handle($reqSub);
        $this->assertSame(200, $resSub->getStatusCode());

        $xmlStrSub = $resSub->getContent();
        $this->assertStringContainsString('https://example.org/cms/</loc>', $xmlStrSub);
        $this->assertStringContainsString('https://example.org/cms/post/seo-test-article</loc>', $xmlStrSub);
        $this->assertStringContainsString('https://example.org/cms/page/seo-test-page</loc>', $xmlStrSub);
        $this->assertStringNotContainsString('http://example.org/post/', $xmlStrSub);
    }

    /**
     * 4. VERIFY ROBOTS.TXT HTTP BEHAVIOR (ROOT & SUBDIRECTORY)
     */
    public function testRobotsTxtHttpBehaviorRootAndSubdirectory(): void
    {
        // Clear custom setting to test default behavior
        Setting::set('seo', 'robots_txt', '');
        Setting::clearCache();

        // 1. Root /robots.txt
        $reqRoot = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/robots.txt',
            'HTTP_HOST'      => 'example.org',
        ]);
        $resRoot = static::$kernel->handle($reqRoot);
        $this->assertSame(200, $resRoot->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $resRoot->getHeader('Content-Type'));

        $txtRoot = $resRoot->getContent();
        $this->assertStringContainsString('User-agent: *', $txtRoot);
        $this->assertStringContainsString('Allow: /', $txtRoot);
        $this->assertStringContainsString('Disallow: /admin/', $txtRoot);
        $this->assertStringContainsString('Sitemap: http://example.org/sitemap.xml', $txtRoot);

        // 2. Subdirectory /cms/robots.txt with HTTPS
        $reqSub = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/cms/robots.txt',
            'SCRIPT_NAME'    => '/cms/index.php',
            'HTTP_HOST'      => 'example.org',
            'HTTPS'          => 'on',
        ]);
        $resSub = static::$kernel->handle($reqSub);
        $this->assertSame(200, $resSub->getStatusCode());

        $txtSub = $resSub->getContent();
        $this->assertStringContainsString('Disallow: /cms/admin/', $txtSub);
        $this->assertStringContainsString('Sitemap: https://example.org/cms/sitemap.xml', $txtSub);

        // 3. Custom robots.txt setting preserves sitemap URL and safe headers
        Setting::set('seo', 'robots_txt', "User-agent: *\nDisallow: /private-area/\n");
        Setting::clearCache();
        $resCustom = static::$kernel->handle($reqRoot);
        $txtCustom = $resCustom->getContent();
        $this->assertStringContainsString('Disallow: /private-area/', $txtCustom);
        $this->assertStringContainsString('Sitemap: http://example.org/sitemap.xml', $txtCustom);
    }

    /**
     * 5. VERIFY CANONICAL URLS (HOMEPAGE, POST, PAGE, QUERY STRINGS, SUBDIRECTORIES)
     */
    public function testCanonicalUrlsResolutionAndQueryStripping(): void
    {
        $post = Post::find(static::$testPostId);
        $page = Page::find(static::$testPageId);

        // Clear manual overrides to test dynamic resolution
        $post->saveSeoMeta(['canonical_url' => '', 'robots' => '']);
        $page->saveSeoMeta(['canonical_url' => '', 'robots' => '']);

        // 1. Root domain post without and with query strings
        $_SERVER['HTTP_HOST'] = 'my-site.test';
        $_SERVER['REQUEST_URI'] = '/post/seo-test-article';
        $headPost = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<link rel="canonical" href="http://my-site.test/post/seo-test-article">', $headPost);

        $_SERVER['REQUEST_URI'] = '/post/seo-test-article?anything=test&utm_source=rss';
        $headPostQuery = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<link rel="canonical" href="http://my-site.test/post/seo-test-article">', $headPostQuery);

        // 2. Page canonical
        $_SERVER['REQUEST_URI'] = '/page/seo-test-page?ref=search';
        $headPage = FrontendSeoService::renderHeadTags(['page' => $page]);
        $this->assertStringContainsString('<link rel="canonical" href="http://my-site.test/page/seo-test-page">', $headPage);

        // 3. Subdirectory installation (/cms) with HTTPS
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'my-site.test';
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        $_SERVER['REQUEST_URI'] = '/cms/post/seo-test-article?any=param';
        $headSubPost = FrontendSeoService::renderHeadTags(['post' => $post]);
        // Must canonicalize to https://my-site.test/cms/post/seo-test-article, NOT /post/seo-test-article
        $this->assertStringContainsString('<link rel="canonical" href="https://my-site.test/cms/post/seo-test-article">', $headSubPost);

        // 4. Homepage in subdirectory
        $_SERVER['REQUEST_URI'] = '/cms/?filter=recent';
        $headSubHome = FrontendSeoService::renderHeadTags(['isHome' => true]);
        $this->assertStringContainsString('<link rel="canonical" href="https://my-site.test/cms/">', $headSubHome);
    }

    /**
     * 6. VERIFY ROBOTS META DIRECTIVES
     */
    public function testRobotsMetaDirectives(): void
    {
        $post = Post::find(static::$testPostId);
        $page = Page::find(static::$testPageId);

        // 1. Normal published post & page default to index,follow
        $post->saveSeoMeta(['robots' => '']);
        $headPost = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $headPost);

        $page->saveSeoMeta(['robots' => '']);
        $headPage = FrontendSeoService::renderHeadTags(['page' => $page]);
        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $headPage);

        // 2. Per-content noindex,follow
        $post->saveSeoMeta(['robots' => 'noindex,follow']);
        $headNoIndex = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $headNoIndex);

        // 3. Per-content noindex,nofollow
        $post->saveSeoMeta(['robots' => 'noindex,nofollow']);
        $headNoIndexNoFollow = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $headNoIndexNoFollow);

        // 4. Query string does not alter robots meta
        $_SERVER['REQUEST_URI'] = '/post/seo-test-article?query=yes';
        $headQuery = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $headQuery);

        // Reset
        $post->saveSeoMeta(['robots' => 'index,follow']);
    }

    /**
     * 7. VERIFY OPEN GRAPH AND TWITTER META (WITH UNICODE & ESCAPING)
     */
    public function testOpenGraphAndTwitterTagsWithUnicodeAndEscaping(): void
    {
        $post = Post::find(static::$testPostId);
        $post->saveSeoMeta([
            'og_title'       => 'বাংলা শিরোনাম & Special <Chars>',
            'og_description' => 'বাংলা বিবরণী "quoted text" & symbols',
            'meta_title'     => 'Fallback Title',
            'canonical_url'  => '',
        ]);

        $_SERVER['HTTP_HOST'] = 'news.test';
        $_SERVER['REQUEST_URI'] = '/post/seo-test-article';

        $head = FrontendSeoService::renderHeadTags(['post' => $post]);

        // OG tags
        $this->assertStringContainsString('<meta property="og:title" content="বাংলা শিরোনাম &amp; Special &lt;Chars&gt;">', $head);
        $this->assertStringContainsString('<meta property="og:description" content="বাংলা বিবরণী &quot;quoted text&quot; &amp; symbols">', $head);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $head);
        $this->assertStringContainsString('<meta property="og:url" content="http://news.test/post/seo-test-article">', $head);

        // Twitter tags
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $head);
        $this->assertStringContainsString('<meta name="twitter:title" content="বাংলা শিরোনাম &amp; Special &lt;Chars&gt;">', $head);
        $this->assertStringContainsString('<meta name="twitter:description" content="বাংলা বিবরণী &quot;quoted text&quot; &amp; symbols">', $head);

        // Post without custom OG metadata falls back gracefully to post title/excerpt
        $post->title = 'XSS Test Title <script>alert(1)</script>';
        $post->saveSeoMeta(['canonical_url' => '']);
        $headFallback = FrontendSeoService::renderHeadTags(['post' => $post]);
        $this->assertStringContainsString('og:title', $headFallback);
        $this->assertStringContainsString('twitter:title', $headFallback);
        // Script tags in title must be escaped, never raw
        $this->assertStringNotContainsString('<script>alert(1)</script>', $headFallback);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $headFallback);
        $post->title = 'SEO Test Article';
    }

    /**
     * 8. VERIFY JSON-LD STRUCTURED DATA AND ACTUAL SEARCH URL TARGET
     */
    public function testJsonLdStructuredDataAndSearchActionRoute(): void
    {
        $_SERVER['HTTP_HOST'] = 'demo.test';

        // 1. Homepage JSON-LD
        $_SERVER['REQUEST_URI'] = '/';
        $homeHead = FrontendSeoService::renderHeadTags(['isHome' => true]);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $homeHead, $mHome);
        $this->assertNotEmpty($mHome[1], 'JSON-LD block must exist on homepage');

        $homeJson = json_decode($mHome[1], true);
        $this->assertIsArray($homeJson, 'JSON-LD must be valid JSON');
        $this->assertSame('https://schema.org', $homeJson['@context']);
        $this->assertSame('WebSite', $homeJson['@graph'][0]['@type']);
        $this->assertSame('SearchAction', $homeJson['@graph'][0]['potentialAction']['@type']);
        // Verify SearchAction points to ACTUAL Core search route /search?q={search_term_string}
        $this->assertSame('http://demo.test/search?q={search_term_string}', $homeJson['@graph'][0]['potentialAction']['target']);

        // 2. Post JSON-LD
        $post = Post::find(static::$testPostId);
        $post->saveSeoMeta(['canonical_url' => '']);
        $_SERVER['REQUEST_URI'] = '/post/seo-test-article';
        $postHead = FrontendSeoService::renderHeadTags(['post' => $post]);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $postHead, $mPost);
        $this->assertNotEmpty($mPost[1]);

        $postJson = json_decode($mPost[1], true);
        $this->assertIsArray($postJson);
        $this->assertSame('Article', $postJson['@graph'][0]['@type']);
        $this->assertSame('BreadcrumbList', $postJson['@graph'][1]['@type']);
        $this->assertArrayHasKey('headline', $postJson['@graph'][0]);
        $this->assertArrayHasKey('datePublished', $postJson['@graph'][0]);
        $this->assertArrayHasKey('dateModified', $postJson['@graph'][0]);

        // 3. Page JSON-LD
        $page = Page::find(static::$testPageId);
        $page->saveSeoMeta(['canonical_url' => '']);
        $_SERVER['REQUEST_URI'] = '/page/seo-test-page';
        $pageHead = FrontendSeoService::renderHeadTags(['page' => $page]);

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $pageHead, $mPage);
        $this->assertNotEmpty($mPage[1]);
        $pageJson = json_decode($mPage[1], true);
        $this->assertSame('WebPage', $pageJson['@graph'][0]['@type']);
        $this->assertSame('BreadcrumbList', $pageJson['@graph'][1]['@type']);
    }

    /**
     * 9. Admin SEO form persistence & sanitization
     */
    public function testAdminSeoSettingsCanBeSavedAndSanitized(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['auth_user_id'] = static::$adminUserId;
        $_SESSION['auth_user_name'] = 'seo_admin_test';
        $_SESSION['auth_user_role'] = 'admin';
        $_SESSION['_token'] = 'valid_token_123';

        $req = new Request(
            [],
            [
                '_token'                   => 'valid_token_123',
                'separator'                => '|',
                'meta_description'         => 'Global site meta description test',
                'og_image'                 => 'https://example.com/social.png',
                'robots_txt'               => "User-agent: *\nDisallow: /private/\n",
                'google_site_verification' => '<script>alert(1)</script>gsc-verification-code-xyz',
                'bing_site_verification'   => 'bing-verification-code-123',
                'ga4_enabled'              => '1',
                'ga4_measurement_id'       => 'g-abc1234567',
                'gtm_enabled'              => '1',
                'gtm_container_id'         => 'gtm-xyz9876',
            ],
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/admin/seo/update',
            ]
        );

        $res = static::$kernel->handle($req);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/seo', $res->getHeader('Location'));

        Setting::clearCache();

        $this->assertSame('|', Setting::get('seo', 'title_separator'));
        $this->assertSame('Global site meta description test', Setting::get('seo', 'meta_description'));
        $this->assertSame('https://example.com/social.png', Setting::get('seo', 'og_image'));
        $this->assertSame("User-agent: *\nDisallow: /private/\n", Setting::get('seo', 'robots_txt'));

        $this->assertSame('alert(1)gsc-verification-code-xyz', Setting::get('seo', 'google_site_verification'));
        $this->assertSame('bing-verification-code-123', Setting::get('seo', 'bing_site_verification'));

        $this->assertSame(1, (int)Setting::get('seo', 'ga4_enabled'));
        $this->assertSame('G-ABC1234567', Setting::get('seo', 'ga4_measurement_id'));
        $this->assertSame(1, (int)Setting::get('seo', 'gtm_enabled'));
        $this->assertSame('GTM-XYZ9876', Setting::get('seo', 'gtm_container_id'));
    }
}