<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Themes\ThemeLayoutService;
use PHPUnit\Framework\TestCase;

/**
 * Default theme rendering and bounded, paginated frontend listings.
 */
class FrontendListingAndThemeTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static Kernel $kernel;
    protected static string $token;
    protected static int $authorId;
    protected static int $categoryId;
    protected static string $categorySlug;
    protected static int $tagId;
    protected static string $tagSlug;
    protected static int $pageId;
    protected static string $banglaSlug;
    protected static int $commentId;
    protected static array $categoryTitles = [];
    protected static array $postIds = [];
    protected static array $originalSettings = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$kernel = new Kernel(static::$app);
        static::$token = 'p3' . bin2hex(random_bytes(3));

        foreach ([['reading', 'posts_per_page'], ['reading', 'front_page_type'], ['reading', 'front_page_id'], ['general', 'allow_registration']] as [$group, $key]) {
            static::$originalSettings[$group . '.' . $key] = Setting::get($group, $key, null);
        }
        Setting::set('reading', 'posts_per_page', 10, 'int');
        Setting::set('reading', 'front_page_type', 'posts');

        $now = date('Y-m-d H:i:s');
        static::$authorId = static::$db->insert('users', [
            'username' => 'p3author_' . static::$token, 'name' => 'রহিম উদ্দিন', 'email' => 'p3author_' . static::$token . '@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT), 'status' => 'active', 'email_verified_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        static::$categorySlug = 'p3-cat-' . static::$token;
        static::$categoryId = static::$db->insert('taxonomies', [
            'name' => 'P3 Category ' . static::$token, 'slug' => static::$categorySlug, 'taxonomy' => 'category',
            'description' => 'Listing pagination category', 'post_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        static::$tagSlug = 'p3-tag-' . static::$token;
        static::$tagId = static::$db->insert('taxonomies', [
            'name' => 'P3 Tag ' . static::$token, 'slug' => static::$tagSlug, 'taxonomy' => 'tag',
            'description' => '', 'post_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $base = strtotime('2099-06-01 12:00:00');
        for ($i = 0; $i < 25; $i++) {
            $title = sprintf('P3 Cat Post %s #%02d', static::$token, $i + 1);
            $postId = static::insertPost($title, 'p3-cat-post-' . static::$token . '-' . ($i + 1), '<p>Category listing body.</p>', date('Y-m-d H:i:s', $base - $i * 60));
            static::$categoryTitles[] = $title;
            static::$db->execute('INSERT INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)', [$postId, static::$categoryId]);
            if ($i < 13) {
                static::$db->execute('INSERT INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)', [$postId, static::$tagId]);
            }
        }
        for ($i = 0; $i < 2; $i++) {
            $draftId = static::insertPost('P3 Draft ' . static::$token . ' ' . $i, 'p3-draft-' . static::$token . '-' . $i, '<p>Draft</p>', date('Y-m-d H:i:s', $base), 'draft');
            static::$db->execute('INSERT INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)', [$draftId, static::$categoryId]);
        }
        for ($i = 0; $i < 23; $i++) {
            static::insertPost('Searchable ' . static::$token . 'zeta ' . $i, 'p3-search-' . static::$token . '-' . $i, '<p>Search body.</p>', date('Y-m-d H:i:s', $base - (100 + $i) * 60));
        }

        static::$banglaSlug = 'p3-bangla-' . static::$token;
        $banglaContent = '<p>' . str_repeat('বাংলা শব্দ ', 250) . '</p>'
            . '<table><tr><th>Column A</th><th>Column B</th></tr><tr><td>1</td><td>2</td></tr></table>'
            . '<p>https://example.com/' . str_repeat('verylongsegment', 20) . '</p>';
        $banglaId = static::insertPost('বাংলা পরীক্ষা ' . static::$token, static::$banglaSlug, $banglaContent, date('Y-m-d H:i:s', $base - 400 * 60));
        static::$commentId = static::$db->insert('comments', [
            'post_id' => $banglaId, 'user_id' => static::$authorId, 'author_name' => 'রহিম উদ্দিন', 'author_email' => 'p3@example.com',
            'content' => 'চমৎকার লেখা!', 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ]);

        static::$pageId = static::$db->insert('pages', [
            'title' => 'P3 Static Home ' . static::$token, 'slug' => 'p3-static-home-' . static::$token, 'content' => '<p>Static front page body.</p>',
            'status' => 'published', 'author_id' => static::$authorId, 'menu_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$postIds !== []) {
            $in = implode(',', array_map('intval', static::$postIds));
            static::$db->execute("DELETE FROM `comments` WHERE `post_id` IN ({$in})");
            static::$db->execute("DELETE FROM `post_taxonomies` WHERE `post_id` IN ({$in})");
            static::$db->execute("DELETE FROM `posts` WHERE `id` IN ({$in})");
        }
        static::$db->execute('DELETE FROM `taxonomies` WHERE `id` IN (?, ?)', [static::$categoryId ?? 0, static::$tagId ?? 0]);
        static::$db->execute('DELETE FROM `pages` WHERE `id` = ?', [static::$pageId ?? 0]);
        static::$db->execute('DELETE FROM `users` WHERE `id` = ?', [static::$authorId ?? 0]);

        foreach (static::$originalSettings as $compound => $value) {
            [$group, $key] = explode('.', $compound, 2);
            if ($value !== null) {
                Setting::set($group, $key, $value);
            }
        }
        unset($GLOBALS['favorite_cms_base_path']);
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['_token' => 'p3_listing_token'];
        $_GET = [];
        $_SERVER['HTTP_HOST'] = 'favorite-cms.local';
        unset($GLOBALS['favorite_cms_base_path']);
        Setting::set('reading', 'posts_per_page', 10, 'int');
        Setting::set('reading', 'front_page_type', 'posts');
        Setting::set('general', 'allow_registration', 1, 'bool');
    }

    private static function insertPost(string $title, string $slug, string $content, string $publishedAt, string $status = 'published'): int
    {
        $now = date('Y-m-d H:i:s');
        $id = static::$db->insert('posts', [
            'title' => $title, 'slug' => $slug, 'content' => $content, 'excerpt' => '', 'status' => $status, 'type' => 'post',
            'author_id' => static::$authorId, 'published_at' => $publishedAt, 'created_at' => $now, 'updated_at' => $now,
        ]);
        static::$postIds[] = (int)$id;
        return (int)$id;
    }

    private function get(string $uri, array $get = [], string $scriptName = '/index.php'): Response
    {
        $_GET = $get;
        $_SERVER['REQUEST_URI'] = $uri;
        return static::$kernel->handle(new Request(
            get: $get,
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => $scriptName, 'HTTP_HOST' => 'favorite-cms.local']
        ));
    }

    private function mainHtml(string $html): string
    {
        $start = strpos($html, 'id="main-content"');
        $end = $start === false ? false : strpos($html, '</main>', $start);
        return ($start !== false && $end !== false) ? substr($html, $start, $end - $start) : '';
    }

    private function cardTitles(string $html): array
    {
        preg_match_all('#class="post-card__title"><a href="[^"]*">([^<]*)</a>#u', $this->mainHtml($html), $matches);
        return array_map(static fn(string $title): string => html_entity_decode($title, ENT_QUOTES, 'UTF-8'), $matches[1]);
    }

    public function testCategoryArchiveIsBoundedPaginatedAndAccurate(): void
    {
        $page1 = $this->get('/category/' . static::$categorySlug);
        $html1 = $page1->getContent();

        $this->assertSame(200, $page1->getStatusCode());
        $this->assertSame(array_slice(static::$categoryTitles, 0, 10), $this->cardTitles($html1), 'Page 1 renders exactly one bounded page, newest first');
        $this->assertStringContainsString('Category: P3 Category ' . static::$token, $html1);
        $this->assertStringContainsString('25 articles · Page 1 of 3', $html1, 'Draft posts are excluded from the accurate total');
        $this->assertStringContainsString('href="/category/' . static::$categorySlug . '?page=2"', $html1);

        $html2 = $this->get('/category/' . static::$categorySlug, ['page' => '2'])->getContent();
        $this->assertSame(array_slice(static::$categoryTitles, 10, 10), $this->cardTitles($html2));

        $html3 = $this->get('/category/' . static::$categorySlug, ['page' => '3'])->getContent();
        $this->assertSame(array_slice(static::$categoryTitles, 20, 5), $this->cardTitles($html3), 'Posts beyond the first page stay accessible');
        $this->assertStringContainsString('aria-current="page">3</span>', $html3);
    }

    public function testTagArchivePaginates(): void
    {
        $html1 = $this->get('/tag/' . static::$tagSlug)->getContent();
        $this->assertCount(10, $this->cardTitles($html1));
        $this->assertStringContainsString('13 articles', $html1);
        $this->assertStringContainsString('Tag: #P3 Tag ' . static::$token, $html1);

        $html2 = $this->get('/tag/' . static::$tagSlug, ['page' => '2'])->getContent();
        $this->assertSame(array_slice(static::$categoryTitles, 10, 3), $this->cardTitles($html2));
    }

    public function testSearchIsBoundedWithAccurateCountAndPaginationKeepsQuery(): void
    {
        $query = static::$token . 'zeta';
        $html1 = $this->get('/search', ['q' => $query])->getContent();

        $this->assertCount(10, $this->cardTitles($html1));
        $this->assertStringContainsString('Found 23 matching articles for &quot;' . $query . '&quot;', $html1, 'Total must not be capped at the page size');
        $this->assertStringContainsString('Search Results for: &quot;' . $query . '&quot;', $html1);
        $this->assertStringContainsString('href="/search?q=' . $query . '&amp;page=2"', $html1);

        $html3 = $this->get('/search', ['q' => $query, 'page' => '3'])->getContent();
        $this->assertCount(3, $this->cardTitles($html3));
        $this->assertStringContainsString('Page 3 of 3', $html3);
    }

    public function testSearchTitleIsEscapedExactlyOnce(): void
    {
        $query = 'Tom & "Jerry" ' . static::$token;
        $html = $this->get('/search', ['q' => $query])->getContent();

        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Search results for &quot;Tom &amp; &quot;Jerry&quot; ' . static::$token . '&quot;', $html);
    }

    public function testPaginatedCanonicalAndMetaTitles(): void
    {
        $html2 = $this->get('/category/' . static::$categorySlug, ['page' => '2'])->getContent();
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/category/' . static::$categorySlug . '\?page=2">#', $html2);
        $this->assertMatchesRegularExpression('#<title>Category: P3 Category ' . static::$token . ' .+ Page 2</title>#u', $html2);

        $html1 = $this->get('/category/' . static::$categorySlug)->getContent();
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/category/' . static::$categorySlug . '">#', $html1);

        $query = static::$token . 'zeta';
        $search2 = $this->get('/search', ['q' => $query, 'page' => '2'])->getContent();
        $this->assertMatchesRegularExpression('#<link rel="canonical" href="[^"]*/search\?q=' . $query . '&amp;page=2">#', $search2);
    }

    public function testHomepageFeaturedPostsAreNotRepeatedAndPageTwoIsAPlainListing(): void
    {
        $layout = new ThemeLayoutService(static::$app);
        $originalSections = $layout->getSections();
        foreach (['hero', 'featured-posts', 'latest-posts'] as $sectionId) {
            $layout->updateSection($sectionId, ['enabled' => true]);
        }

        try {
            $html1 = $this->get('/')->getContent();
            $main1 = $this->mainHtml($html1);
            $titles1 = $this->cardTitles($html1);

            $this->assertStringContainsString('class="home-hero"', $main1);
            $this->assertStringContainsString('home-featured', $main1);
            $this->assertSame(array_map(static fn(Post $post): string => $post->title, Post::published(10, 0)), $titles1);
            $this->assertSame($titles1, array_values(array_unique($titles1)), 'Featured posts must not be repeated in Latest');

            $html2 = $this->get('/', ['page' => '2'])->getContent();
            $main2 = $this->mainHtml($html2);
            $this->assertStringNotContainsString('home-hero', $main2);
            $this->assertStringNotContainsString('home-featured', $main2);
            $this->assertStringContainsString('Latest Articles', $main2);
            $this->assertSame(array_map(static fn(Post $post): string => $post->title, Post::published(10, 10)), $this->cardTitles($html2));
        } finally {
            foreach ($originalSections as $section) {
                $layout->updateSection($section['id'], ['enabled' => !empty($section['enabled'])]);
            }
        }
    }

    public function testThemeMarkupIsSemanticWithoutInlineStylesOrHandlers(): void
    {
        $html = $this->get('/')->getContent();
        $body = (string)preg_replace('#<noscript>.*?</noscript>#s', '', substr($html, (int)strpos($html, '<body')));

        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sstyle="/i', $body);
        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringContainsString('<a class="skip-link" href="#main-content">', $html);
        $this->assertStringContainsString('id="main-content"', $html);
        $this->assertStringContainsString('aria-controls="header-nav-wrap"', $html);
        $this->assertMatchesRegularExpression('/<html lang="[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*" class="no-js">/', $html);
    }

    public function testSubdirectoryInstallUsesBasePathForAllThemeUrls(): void
    {
        $html = $this->get('/cms/', [], '/cms/index.php')->getContent();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/cms/themes/[A-Za-z0-9_-]+/assets/css/style\.css\?v=\d+">#', $html);
        $this->assertMatchesRegularExpression('#<script src="/cms/themes/[A-Za-z0-9_-]+/assets/js/main\.js\?v=\d+" defer>#', $html);
        $this->assertStringContainsString('action="/cms/search"', $html);
        $this->assertStringContainsString('href="/cms/" class="site-branding"', $html);
        $this->assertStringContainsString('href="/cms/post/', $html);
        $this->assertStringContainsString('href="/cms/admin/login"', $html);
        $this->assertStringNotContainsString('href="/post/', $html);
        $this->assertStringNotContainsString('href="/category/', $html);
        $this->assertStringNotContainsString('href="/tag/', $html);
        $this->assertStringNotContainsString('action="/search"', $html);
        $this->assertStringNotContainsString('href="/themes/', $html);
    }

    public function testSinglePostHasCommentAnchorsResponsiveTablesAndBanglaSupport(): void
    {
        $html = $this->get('/post/' . static::$banglaSlug)->getContent();

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('id="comment-' . static::$commentId . '"', $html);
        $this->assertStringContainsString('<div class="table-scroll" role="region" aria-label="Scrollable table" tabindex="0"><table>', $html);
        $this->assertStringContainsString('3 min read', $html, 'Bangla words are counted with Unicode-aware tokens');
        $this->assertMatchesRegularExpression('#<span class="avatar" aria-hidden="true">\s*র\s*</span>#u', $html);
        $this->assertStringContainsString('class="author-box"', $html);
        $this->assertStringContainsString('Log in to comment', $html);
    }

    public function testPrimaryMenuRendersNestedItemsWithoutDuplicateHome(): void
    {
        $existing = static::$db->select("SELECT `id` FROM `menus` WHERE `location` = 'primary'");
        foreach ($existing as $menu) {
            static::$db->execute('UPDATE `menus` SET `location` = NULL WHERE `id` = ?', [$menu->id]);
        }

        $now = date('Y-m-d H:i:s');
        $menuId = static::$db->insert('menus', ['name' => 'P3 Primary ' . static::$token, 'slug' => 'p3-primary-' . static::$token, 'location' => 'primary', 'created_at' => $now, 'updated_at' => $now]);
        try {
            static::insertMenuItem(['menu_id' => $menuId, 'title' => 'Home', 'url' => '/', 'type' => 'custom', 'parent_id' => null, 'sort_order' => 1]);
            $aboutId = static::insertMenuItem(['menu_id' => $menuId, 'title' => 'About P3', 'url' => '/page/about-p3', 'type' => 'custom', 'parent_id' => null, 'sort_order' => 2]);
            static::insertMenuItem(['menu_id' => $menuId, 'title' => 'Team P3', 'url' => '/page/team-p3', 'type' => 'custom', 'parent_id' => $aboutId, 'sort_order' => 1]);

            $html = $this->get('/')->getContent();
            $navStart = (int)strpos($html, 'class="main-nav"');
            $nav = substr($html, $navStart, (int)strpos($html, '</nav>', $navStart) - $navStart);

            $this->assertSame(1, substr_count($nav, '>Home</a>'), 'An assigned primary menu must not get a duplicate hard-coded Home link');
            $this->assertMatchesRegularExpression('#<a class="menu-link" href="/page/about-p3">About P3</a>\s*<ul class="sub-menu">.*Team P3#s', $nav);

            $current = $this->get('/page/team-p3')->getContent();
            $this->assertStringContainsString('href="/page/team-p3" aria-current="page">Team P3</a>', $current);
        } finally {
            static::$db->execute('DELETE FROM `menu_items` WHERE `menu_id` = ?', [$menuId]);
            static::$db->execute('DELETE FROM `menus` WHERE `id` = ?', [$menuId]);
            foreach ($existing as $menu) {
                static::$db->execute("UPDATE `menus` SET `location` = 'primary' WHERE `id` = ?", [$menu->id]);
            }
        }
    }

    private static function insertMenuItem(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        try {
            return (int)static::$db->insert('menu_items', $data + ['created_at' => $now, 'updated_at' => $now]);
        } catch (\Throwable) {
            return (int)static::$db->insert('menu_items', $data);
        }
    }

    public function testHeaderKeepsAuthLinksAndFooterHidesAdminAreaFromGuests(): void
    {
        $guest = $this->get('/')->getContent();
        $guestFooter = substr($guest, (int)strpos($guest, '<footer'));
        $this->assertStringContainsString('>Log In</a>', $guest);
        $this->assertStringContainsString('>Sign Up</a>', $guest);
        $this->assertStringNotContainsString('Admin Area', $guestFooter);
        $this->assertStringContainsString('>Sitemap</a>', $guestFooter);

        $_SESSION['auth_user_id'] = static::$authorId;
        $member = $this->get('/')->getContent();
        $this->assertStringContainsString('class="header-account-wrap"', $member);
        $this->assertStringContainsString('Admin Area', substr($member, (int)strpos($member, '<footer')));
    }

    public function testLayoutModesAndAccentColorValidation(): void
    {
        $originalLayout = get_theme_mod('site_layout', null);
        $originalAccent = get_theme_mod('accent_color', null);

        try {
            set_theme_mod('site_layout', 'left');
            $left = $this->get('/')->getContent();
            $this->assertStringContainsString('class="layout-left has-sidebar"', $left);
            $this->assertStringContainsString('site-content-sidebar-left', $left);
            $this->assertStringContainsString('<aside class="sidebar sidebar-left"', $left);

            set_theme_mod('site_layout', 'none');
            $none = $this->get('/')->getContent();
            $this->assertStringContainsString('class="layout-none no-sidebar"', $none);
            $this->assertStringNotContainsString('<aside class="sidebar', $none);

            set_theme_mod('site_layout', 'right');
            set_theme_mod('accent_color', 'red;}body{display:none');
            $unsafe = $this->get('/')->getContent();
            $this->assertStringNotContainsString('body{display:none', $unsafe, 'Invalid accent values must never reach the stylesheet');
            $this->assertStringNotContainsString('--accent: red', $unsafe);
            $this->assertStringNotContainsString(':root { --accent:', $unsafe);

            set_theme_mod('accent_color', '#1A2B3C');
            $this->assertStringContainsString(':root { --accent: #1a2b3c; }', $this->get('/')->getContent());
        } finally {
            set_theme_mod('site_layout', $originalLayout ?? 'right');
            set_theme_mod('accent_color', $originalAccent ?? '');
        }
    }

    public function testStaticFrontPageSettingIsPreserved(): void
    {
        Setting::set('reading', 'front_page_type', 'page');
        Setting::set('reading', 'front_page_id', static::$pageId, 'int');

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('entry--page', $html);
        $this->assertStringContainsString('P3 Static Home ' . static::$token, $html);
    }

    public function testOutOfRangeArchivePageShowsEmptyStateWithPagination(): void
    {
        $response = $this->get('/category/' . static::$categorySlug, ['page' => '99']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('No articles on this page', $response->getContent());
        $this->assertStringContainsString('class="pagination"', $response->getContent());
    }

    public function testStaticAssetRouteNeverServesThemePhpSource(): void
    {
        $source = $this->get('/themes/default/header.php');
        $this->assertSame(404, $source->getStatusCode());
        $this->assertStringNotContainsString('require_once __DIR__', $source->getContent());

        $partial = $this->get('/themes/default/partials/comments.php');
        $this->assertSame(404, $partial->getStatusCode());

        $css = $this->get('/themes/default/assets/css/style.css');
        $this->assertSame(200, $css->getStatusCode());
        $this->assertSame('text/css; charset=utf-8', $css->getHeader('Content-Type'));
        $this->assertStringContainsString('--accent', $css->getContent());
    }
}
