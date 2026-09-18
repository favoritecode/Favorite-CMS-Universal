<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\CustomizeController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Themes\BuilderElementRegistry;
use FavoriteCMS\Themes\ThemeLayoutService;
use PHPUnit\Framework\TestCase;

class CoreCustomizerFreezeTest extends TestCase
{
    protected static Application $app;
    protected static Database $db;
    protected ThemeLayoutService $layoutService;
    private static int $adminUserId = 0;
    private static ?string $originalActiveTheme = null;
    private static string $mockThemeId = 'hypothetical_theme';
    private static string $mockThemeDir = '';

    public static function setUpBeforeClass(): void
    {
        static::$app = require APP_ROOT . '/bootstrap.php';
        static::$app->setInstalled(true);
        static::$db = static::$app->make(Database::class);

        static::$originalActiveTheme = (string)Setting::get('theme', 'active_theme', 'default');

        // Ensure admin role exists
        static::$db->execute("INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES ('Administrator', 'admin', 'Site administrator', 1)");

        // Create test admin user
        $username = 'freeze_admin_' . bin2hex(random_bytes(4));
        $email = "{$username}@example.com";
        static::$db->execute(
            "INSERT INTO `users` (`username`, `name`, `email`, `password`, `email_verified_at`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), 'active', NOW(), NOW())",
            [$username, 'Freeze Admin', $email, password_hash('Secret123!', PASSWORD_DEFAULT)]
        );
        static::$adminUserId = (int)static::$db->lastInsertId();

        $roleRow = static::$db->selectOne("SELECT `id` FROM `roles` WHERE `slug` = 'admin'");
        if ($roleRow) {
            static::$db->execute("INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [static::$adminUserId, (int)$roleRow->id]);
        }

        // Create mock hypothetical theme directory
        static::$mockThemeDir = APP_ROOT . '/themes/' . static::$mockThemeId;
        if (!is_dir(static::$mockThemeDir)) {
            mkdir(static::$mockThemeDir, 0755, true);
        }

        // Mock theme.json
        $manifest = [
            'id'       => static::$mockThemeId,
            'name'     => 'Hypothetical Agency Theme',
            'version'  => '1.0.0',
            'sections' => [
                ['id' => 'showcase', 'name' => 'Portfolio Showcase', 'enabled' => true],
                ['id' => 'team', 'name' => 'Executive Team', 'enabled' => true],
                ['id' => 'contact', 'name' => 'Contact Inquiry', 'enabled' => true],
            ],
        ];
        file_put_contents(static::$mockThemeDir . '/theme.json', json_encode($manifest));

        // Mock customizer.php
        $customizerContent = <<<'PHP'
<div class="hypothetical-customizer-app">
    <h1>Hypothetical Theme Customizer</h1>
    <div id="mock-preview-frame"></div>
</div>
PHP;
        file_put_contents(static::$mockThemeDir . '/customizer.php', $customizerContent);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$adminUserId > 0) {
            static::$db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [static::$adminUserId]);
            static::$db->execute("DELETE FROM `users` WHERE `id` = ?", [static::$adminUserId]);
        }

        // Clean up mock theme files
        if (file_exists(static::$mockThemeDir . '/theme.json')) unlink(static::$mockThemeDir . '/theme.json');
        if (file_exists(static::$mockThemeDir . '/customizer.php')) unlink(static::$mockThemeDir . '/customizer.php');
        if (is_dir(static::$mockThemeDir)) rmdir(static::$mockThemeDir);

        // Clean up mock theme settings
        static::$db->execute("DELETE FROM `settings` WHERE `group_name` LIKE 'theme_%hypothetical%'");

        // Restore original active theme
        Setting::set('theme', 'active_theme', static::$originalActiveTheme ?: 'default');
        Setting::clearCache();
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION = [
            'auth_user_id' => static::$adminUserId,
            '_token'       => 'test_csrf_token',
        ];
        Setting::clearCache();
        $this->layoutService = new ThemeLayoutService(static::$app);
    }

    public function testHypotheticalThemeLoadsWithoutFavoriteWebCoupling(): void
    {
        Setting::set('theme', 'active_theme', static::$mockThemeId);
        Setting::clearCache();

        $controller = new CustomizeController(static::$app);
        $response = $controller->index(Request::create('GET', '/admin/customize'));

        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();

        // Must contain hypothetical theme's customizer output
        $this->assertStringContainsString('Hypothetical Theme Customizer', $html);
        $this->assertStringContainsString('Hypothetical Agency Theme', $html);

        // Must NOT render standard admin sidebar or topbar
        $this->assertStringNotContainsString('<nav class="wp-sidebar">', $html);
        $this->assertStringNotContainsString('<div class="wp-topbar">', $html);
    }

    public function testBuilderElementRegistryExtensionAndRendering(): void
    {
        $registry = BuilderElementRegistry::getInstance();

        // Verify built-in generic elements exist
        $this->assertTrue($registry->has('heading'));
        $this->assertTrue($registry->has('text'));
        $this->assertTrue($registry->has('image'));
        $this->assertTrue($registry->has('video'));
        $this->assertTrue($registry->has('button'));
        $this->assertTrue($registry->has('divider'));
        $this->assertTrue($registry->has('spacer'));
        $this->assertTrue($registry->has('html'));

        // Register custom element for hypothetical theme
        $registry->register('portfolio_card', [
            'name'            => 'Portfolio Card',
            'icon'            => 'briefcase',
            'category'        => 'agency',
            'defaultSettings' => [
                'project_title' => 'Sample Project',
                'client'        => 'Acme Client',
            ],
            'renderCallback'  => function(array $settings): string {
                $p = htmlspecialchars($settings['project_title'], ENT_QUOTES, 'UTF-8');
                $c = htmlspecialchars($settings['client'], ENT_QUOTES, 'UTF-8');
                return "<div class=\"portfolio-card\"><h3>{$p}</h3><span>Client: {$c}</span></div>";
            },
        ]);

        $this->assertTrue($registry->has('portfolio_card'));

        // Render custom element
        $rendered = $registry->renderElement([
            'type'     => 'portfolio_card',
            'settings' => ['project_title' => 'Fintech Redesign', 'client' => 'NeoBank'],
        ]);

        $this->assertStringContainsString('Fintech Redesign', $rendered);
        $this->assertStringContainsString('Client: NeoBank', $rendered);
    }

    public function testHtmlElementStrictSanitization(): void
    {
        $unsafeInput = '
            <p>Valid text paragraph</p>
            <script>alert("xss")</script>
            <a href="javascript:alert(1)" onclick="stealCookies()">Malicious link</a>
            <iframe src="javascript:evil()"></iframe>
            <object data="malware.swf"></object>
        ';

        $sanitized = BuilderElementRegistry::sanitizeHtml($unsafeInput);

        $this->assertStringContainsString('<p>Valid text paragraph</p>', $sanitized);
        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('<object', $sanitized);
    }

    public function testBuilderTreeValidationAndLimits(): void
    {
        $validTree = [
            [
                'id'       => 'sec_showcase',
                'type'     => 'section',
                'label'    => 'Showcase',
                'settings' => ['padding' => '40px 0'],
                'children' => [
                    [
                        'id'       => 'el_title',
                        'type'     => 'heading',
                        'label'    => 'Headline',
                        'settings' => ['text' => 'Our Work', 'tag' => 'h2'],
                        'children' => [],
                    ],
                ],
            ],
        ];

        // Save valid tree
        $saved = $this->layoutService->saveBuilderTree($validTree, static::$mockThemeId);
        $this->assertTrue($saved);

        $loaded = $this->layoutService->getBuilderTree(static::$mockThemeId);
        $this->assertCount(1, $loaded);
        $this->assertSame('sec_showcase', $loaded[0]['id']);
        $this->assertSame('Our Work', $loaded[0]['children'][0]['settings']['text']);

        // Test Model A frontend renderer
        $renderedHtml = $this->layoutService->renderBuilderTree($loaded, static::$mockThemeId);
        $this->assertStringContainsString('<section id="sec_showcase"', $renderedHtml);
        $this->assertStringContainsString('>Our Work</h2>', $renderedHtml);

        // Test nesting depth enforcement
        $deepTree = [];
        $current = &$deepTree;
        for ($i = 0; $i < 15; $i++) {
            $node = ['id' => "node_{$i}", 'type' => 'container', 'children' => []];
            $current[] = $node;
            $current = &$current[0]['children'];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('depth');
        $this->layoutService->saveBuilderTree($deepTree, static::$mockThemeId);
    }

    public function testTemplatesSaveListAndDelete(): void
    {
        $tplData = [
            'type'     => 'section',
            'settings' => ['bg' => '#f1f5f9'],
            'children' => [
                ['id' => 'el_test', 'type' => 'heading', 'settings' => ['text' => 'Template Heading']],
            ],
        ];

        $tpl = $this->layoutService->saveTemplate('Callout Section Template', $tplData, static::$mockThemeId);
        $this->assertNotEmpty($tpl['id']);
        $this->assertSame('Callout Section Template', $tpl['name']);

        $templates = $this->layoutService->getTemplates(static::$mockThemeId);
        $this->assertCount(1, $templates);
        $this->assertSame($tpl['id'], $templates[0]['id']);

        $deleted = $this->layoutService->deleteTemplate($tpl['id'], static::$mockThemeId);
        $this->assertTrue($deleted);

        $templatesAfter = $this->layoutService->getTemplates(static::$mockThemeId);
        $this->assertEmpty($templatesAfter);
    }

    public function testGlobalDesignTokensSaveAndRetrieve(): void
    {
        $customTokens = [
            'colors' => [
                'primary'   => '#6366f1',
                'secondary' => '#94a3b8',
            ],
            'typography' => [
                'body' => 'Inter, sans-serif',
                'h1'   => '3rem',
            ],
        ];

        $saved = $this->layoutService->saveGlobalDesignTokens($customTokens, static::$mockThemeId);
        $this->assertTrue($saved);

        $loaded = $this->layoutService->getGlobalDesignTokens(static::$mockThemeId);
        $this->assertSame('#6366f1', $loaded['colors']['primary']);
        $this->assertSame('Inter, sans-serif', $loaded['typography']['body']);
    }

    public function testResetThemeLayoutCleansUpBuilderAndTemplates(): void
    {
        $this->layoutService->saveBuilderTree([
            ['id' => 'sec_tmp', 'type' => 'section', 'children' => []]
        ], static::$mockThemeId);

        $this->layoutService->saveTemplate('Temp Template', ['type' => 'section'], static::$mockThemeId);
        $this->layoutService->saveGlobalDesignTokens(['colors' => ['primary' => '#123456']], static::$mockThemeId);

        $this->assertNotEmpty($this->layoutService->getTemplates(static::$mockThemeId));

        // Reset
        $this->layoutService->resetThemeLayout(static::$mockThemeId);

        $this->assertEmpty($this->layoutService->getTemplates(static::$mockThemeId));
    }
}
