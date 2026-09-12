<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Core widgets: semantic markup without inline styles, base-path-aware URLs and bounded queries.
 */
class CoreWidgetMarkupTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected static string $token;
    protected static int $authorId;
    protected static int $categoryId;
    protected static int $emptyCategoryId;
    protected static string $categorySlug;
    protected static string $emptyCategorySlug;
    protected static int $featuredPostId;
    protected static string $featuredSlug;
    protected static int $commentId;
    protected static int $mediaId;
    protected static int $pageId;
    protected static string $pageSlug;
    protected static int $menuId;
    protected static array $postIds = [];
    protected static array $categoryPostTitles = [];
    private array $serverBackup = [];

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);
        static::$token = 'w3' . bin2hex(random_bytes(3));
        WidgetRegistry::getInstance()->ensureBooted();

        $now = date('Y-m-d H:i:s');
        $future = '2099-07-01 10:00:00';
        static::$authorId = static::$db->insert('users', [
            'username' => 'w3author_' . static::$token, 'name' => 'Widget Author', 'email' => 'w3_' . static::$token . '@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        static::$categorySlug = 'w3-cat-' . static::$token;
        static::$categoryId = static::$db->insert('taxonomies', ['name' => 'W3 Category ' . static::$token, 'slug' => static::$categorySlug, 'taxonomy' => 'category', 'description' => '', 'post_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
        static::$emptyCategorySlug = 'w3-empty-' . static::$token;
        static::$emptyCategoryId = static::$db->insert('taxonomies', ['name' => 'W3 Empty ' . static::$token, 'slug' => static::$emptyCategorySlug, 'taxonomy' => 'category', 'description' => '', 'post_count' => 0, 'created_at' => $now, 'updated_at' => $now]);

        static::$mediaId = static::$db->insert('media', [
            'filename' => 'w3-' . static::$token . '.jpg', 'stored_filename' => 'w3-' . static::$token . '.jpg', 'path' => 'uploads/w3/w3.jpg',
            'url' => '/uploads/w3/' . static::$token . '.jpg', 'mime_type' => 'image/jpeg', 'size' => 1234, 'width' => 800, 'height' => 450,
            'alt_text' => 'Widget image', 'uploader_id' => static::$authorId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $status = $i < 3 ? 'published' : 'draft';
            $title = 'W3 Post ' . static::$token . ' ' . $i;
            $slug = 'w3-post-' . static::$token . '-' . $i;
            $postId = static::$db->insert('posts', [
                'title' => $title, 'slug' => $slug, 'content' => '<p>Widget post</p>', 'excerpt' => 'Widget excerpt', 'status' => $status, 'type' => 'post',
                'author_id' => static::$authorId, 'featured_image_id' => $i === 0 ? static::$mediaId : null,
                'published_at' => date('Y-m-d H:i:s', strtotime($future) - $i * 60), 'created_at' => $now, 'updated_at' => $now,
            ]);
            static::$postIds[] = (int)$postId;
            static::$db->execute('INSERT INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)', [$postId, static::$categoryId]);
            if ($status === 'published') {
                static::$categoryPostTitles[] = $title;
            }
            if ($i === 0) {
                static::$featuredPostId = (int)$postId;
                static::$featuredSlug = $slug;
            }
        }

        // comments.created_at is a TIMESTAMP column (max year 2038): newest possible date keeps this comment first
        $latestTimestamp = '2037-12-31 23:00:00';
        static::$commentId = static::$db->insert('comments', [
            'post_id' => static::$featuredPostId, 'user_id' => static::$authorId, 'author_name' => 'Widget Reader', 'author_email' => 'reader@example.com',
            'content' => 'Nice widget post', 'status' => 'approved', 'created_at' => $latestTimestamp, 'updated_at' => $latestTimestamp,
        ]);

        static::$pageSlug = 'w3-page-' . static::$token;
        static::$pageId = static::$db->insert('pages', ['title' => 'W3 Page ' . static::$token, 'slug' => static::$pageSlug, 'content' => '<p>Page</p>', 'status' => 'published', 'author_id' => static::$authorId, 'menu_order' => 0, 'created_at' => $now, 'updated_at' => $now]);

        static::$menuId = static::$db->insert('menus', ['name' => 'W3 Menu ' . static::$token, 'slug' => 'w3-menu-' . static::$token, 'location' => null, 'created_at' => $now, 'updated_at' => $now]);
        $parent = static::insertMenuItem(['menu_id' => static::$menuId, 'title' => 'W3 Parent', 'url' => '/page/' . static::$pageSlug, 'type' => 'custom', 'parent_id' => null, 'sort_order' => 1]);
        static::insertMenuItem(['menu_id' => static::$menuId, 'title' => 'W3 Child', 'url' => '/page/w3-child', 'type' => 'custom', 'parent_id' => $parent, 'sort_order' => 1]);
    }

    public static function tearDownAfterClass(): void
    {
        $in = implode(',', array_map('intval', static::$postIds ?: [0]));
        static::$db->execute("DELETE FROM `comments` WHERE `post_id` IN ({$in})");
        static::$db->execute("DELETE FROM `post_taxonomies` WHERE `post_id` IN ({$in})");
        static::$db->execute("DELETE FROM `posts` WHERE `id` IN ({$in})");
        static::$db->execute('DELETE FROM `taxonomies` WHERE `id` IN (?, ?)', [static::$categoryId ?? 0, static::$emptyCategoryId ?? 0]);
        static::$db->execute('DELETE FROM `media` WHERE `id` = ?', [static::$mediaId ?? 0]);
        static::$db->execute('DELETE FROM `pages` WHERE `id` = ?', [static::$pageId ?? 0]);
        static::$db->execute('DELETE FROM `menu_items` WHERE `menu_id` = ?', [static::$menuId ?? 0]);
        static::$db->execute('DELETE FROM `menus` WHERE `id` = ?', [static::$menuId ?? 0]);
        static::$db->execute('DELETE FROM `users` WHERE `id` = ?', [static::$authorId ?? 0]);
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_GET = [];
        unset($GLOBALS['favorite_cms_base_path']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        unset($GLOBALS['favorite_cms_base_path']);
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

    private function render(string $widgetId, array $settings): string
    {
        $widget = WidgetRegistry::getInstance()->get($widgetId);
        $this->assertNotNull($widget, "Core widget {$widgetId} must stay registered");
        return $widget->render($settings);
    }

    private function allWidgetOutputs(): array
    {
        return [
            'search'          => $this->render('search', ['title' => 'Search']),
            'categories'      => $this->render('categories', ['title' => 'Categories', 'show_count' => true]),
            'tags'            => $this->render('tags', ['title' => 'Tags', 'limit' => 5]),
            'recent_posts'    => $this->render('recent_posts', ['title' => 'Recent', 'number' => 3, 'show_thumb' => true, 'show_date' => true, 'category_id' => (string)static::$categoryId]),
            'recent_comments' => $this->render('recent_comments', ['title' => 'Comments', 'limit' => 5]),
            'pages'           => $this->render('pages', ['title' => 'Pages']),
            'featured_post'   => $this->render('featured_post', ['title' => 'Featured', 'post_id' => (string)static::$featuredPostId, 'show_excerpt' => true]),
            'image'           => $this->render('image', ['title' => 'Image', 'image_url' => '/uploads/w3/banner.jpg', 'alt_text' => 'Banner', 'link_url' => '/page/' . static::$pageSlug, 'caption' => 'Caption']),
            'nav_menu'        => $this->render('nav_menu', ['title' => 'Menu', 'menu_id' => (string)static::$menuId]),
            'custom_html'     => $this->render('custom_html', ['title' => 'HTML', 'content' => '<p>Hello</p>']),
        ];
    }

    public function testCoreWidgetsUseSemanticMarkupWithoutInlineStyles(): void
    {
        foreach ($this->allWidgetOutputs() as $widgetId => $html) {
            $this->assertNotSame('', $html, "Widget {$widgetId} should render with seeded data");
            $this->assertStringStartsWith('<section class="widget widget_' . $widgetId . '">', $html);
            $this->assertStringNotContainsString(' style="', $html, "Widget {$widgetId} must not output inline styles");
        }
    }

    public function testCoreWidgetUrlsAreBasePathAwareInSubdirectoryInstalls(): void
    {
        $GLOBALS['favorite_cms_base_path'] = '/cms';
        $out = $this->allWidgetOutputs();

        $this->assertStringContainsString('action="/cms/search"', $out['search']);
        $this->assertStringContainsString('href="/cms/category/' . static::$categorySlug . '"', $out['categories']);
        $this->assertStringContainsString('href="/cms/tag/', $out['tags'] === '' ? 'href="/cms/tag/' : $out['tags']);
        $this->assertStringContainsString('href="/cms/post/', $out['recent_posts']);
        $this->assertStringContainsString('src="/cms/uploads/w3/' . static::$token . '.jpg"', $out['recent_posts']);
        $this->assertStringContainsString('href="/cms/post/' . static::$featuredSlug . '#comment-' . static::$commentId . '"', $out['recent_comments']);
        $this->assertStringContainsString('href="/cms/page/' . static::$pageSlug . '"', $out['pages']);
        $this->assertStringContainsString('href="/cms/post/' . static::$featuredSlug . '"', $out['featured_post']);
        $this->assertStringContainsString('width="800" height="450"', $out['featured_post']);
        $this->assertStringContainsString('src="/cms/uploads/w3/banner.jpg"', $out['image']);
        $this->assertStringContainsString('href="/cms/page/' . static::$pageSlug . '"', $out['image']);
        $this->assertStringContainsString('<figcaption class="widget-image-caption">Caption</figcaption>', $out['image']);
        $this->assertStringContainsString('href="/cms/page/' . static::$pageSlug . '"', $out['nav_menu']);
        $this->assertMatchesRegularExpression('#W3 Parent</a><ul class="sub-menu"><li><a href="/cms/page/w3-child"[^>]*>W3 Child</a>#', $out['nav_menu']);
    }

    public function testRecentCommentsAndCategoryFilteredRecentPostsWork(): void
    {
        $comments = $this->render('recent_comments', ['limit' => 5]);
        $this->assertStringContainsString('Widget Reader', $comments);
        $this->assertStringContainsString('#comment-' . static::$commentId, $comments);

        $recent = $this->render('recent_posts', ['number' => 2, 'category_id' => (string)static::$categoryId, 'show_date' => false]);
        $this->assertSame(2, substr_count($recent, 'class="widget-recent-posts__item'));
        $this->assertStringContainsString(static::$categoryPostTitles[0], $recent);
        $this->assertStringContainsString(static::$categoryPostTitles[1], $recent);
    }

    public function testCategoriesWidgetShowsAccuratePublishedCountsAndHidesEmpty(): void
    {
        $withEmpty = $this->render('categories', ['show_count' => true, 'hide_empty' => false]);
        $this->assertMatchesRegularExpression('#href="/category/' . static::$categorySlug . '" class="category-row"><span class="category-row__name">W3 Category ' . static::$token . '</span><span class="category-badge-count">3</span>#', $withEmpty);
        $this->assertStringContainsString('W3 Empty ' . static::$token, $withEmpty);

        $hideEmpty = $this->render('categories', ['show_count' => true, 'hide_empty' => true]);
        $this->assertStringNotContainsString('W3 Empty ' . static::$token, $hideEmpty);
        $this->assertStringContainsString('W3 Category ' . static::$token, $hideEmpty);
    }

    public function testTagCloudIsBoundedAndOrderedByUsage(): void
    {
        $now = date('Y-m-d H:i:s');
        $tagIds = [];
        for ($i = 0; $i < 6; $i++) {
            $tagIds[] = static::$db->insert('taxonomies', ['name' => 'W3 Tag ' . static::$token . ' ' . $i, 'slug' => 'w3-tag-' . static::$token . '-' . $i, 'taxonomy' => 'tag', 'description' => '', 'post_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
        try {
            $html = $this->render('tags', ['limit' => 3]);
            $this->assertSame(3, substr_count($html, 'class="tag-cloud-item"'));
            preg_match_all('#data-count="(\d+)"#', $html, $matches);
            $counts = array_map('intval', $matches[1]);
            $sorted = $counts;
            rsort($sorted);
            $this->assertSame($sorted, $counts, 'Most used tags are listed first');
        } finally {
            static::$db->execute('DELETE FROM `taxonomies` WHERE `id` IN (' . implode(',', array_map('intval', $tagIds)) . ')');
        }
    }

    public function testShowOnVisibilityMatchesSubdirectoryInstallsLikeRoot(): void
    {
        $manager = new WidgetInstanceManager();
        $home = ['visibility' => ['show_on' => 'home']];
        $posts = ['visibility' => ['show_on' => 'posts']];
        $pages = ['visibility' => ['show_on' => 'pages']];

        $GLOBALS['favorite_cms_base_path'] = '/cms';
        $_SERVER['REQUEST_URI'] = '/cms/';
        $this->assertTrue($manager->isInstanceVisible($home));
        $_SERVER['REQUEST_URI'] = '/cms/?page=2';
        $this->assertTrue($manager->isInstanceVisible($home));
        $_SERVER['REQUEST_URI'] = '/cms/post/example';
        $this->assertFalse($manager->isInstanceVisible($home));
        $this->assertTrue($manager->isInstanceVisible($posts));
        $this->assertFalse($manager->isInstanceVisible($pages));
        $_SERVER['REQUEST_URI'] = '/cms/page/about';
        $this->assertTrue($manager->isInstanceVisible($pages));

        unset($GLOBALS['favorite_cms_base_path']);
        $_SERVER['REQUEST_URI'] = '/post/example';
        $this->assertTrue($manager->isInstanceVisible($posts));
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertTrue($manager->isInstanceVisible($home));
    }
}
