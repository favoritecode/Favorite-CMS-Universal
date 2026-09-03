<?php
/**
 * Plugin Name: Hello Favorite
 * Plugin URI: https://github.com/favoritecode/Favorite-CMS-Universal
 * Description: Official reference plugin demonstrating the Favorite CMS public extension APIs.
 * Version: 1.0.0
 * Author: Favorite CMS Team
 */

declare(strict_types=1);

namespace HelloFavorite;

use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

// 1. Hook into Core Lifecycle Events
add_action('init', function() {
    // Log plugin initialization safely
    cms_log('Hello Favorite plugin initialized successfully.', 'info', ['plugin' => 'hello-favorite']);
});

// 2. Register a Dynamic Public Frontend Route
// Accessible at: /hello-favorite or /hello-favorite/{name}
add_route('GET', '/hello-favorite', function(Request $request) {
    $customGreeting = plugin_setting('hello-favorite', 'greeting_message', 'Welcome to Favorite CMS!');
    
    // Render custom template located at plugins/hello-favorite/templates/greeting.php
    $engine = app(\FavoriteCMS\Rendering\Engine::class);
    $html = $engine->render('greeting', [
        'greeting'  => $customGreeting,
        'visitor'   => 'Community Developer',
        'siteTitle' => \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS'),
    ]);
    
    return Response::make($html, 200);
});

add_route('GET', '/hello-favorite/{name}', function(Request $request, string $name) {
    $customGreeting = plugin_setting('hello-favorite', 'greeting_message', 'Welcome to Favorite CMS!');
    
    $engine = app(\FavoriteCMS\Rendering\Engine::class);
    $html = $engine->render('greeting', [
        'greeting'  => $customGreeting,
        'visitor'   => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        'siteTitle' => \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS'),
    ]);
    
    return Response::make($html, 200);
});

// 3. Register Admin Menu and Custom Admin Settings Page
add_admin_menu(
    'hello-favorite',
    'Hello Favorite',
    '👋',
    function(Request $request) {
        $savedNotice = null;
        
        // Handle form submission
        if ($request->method() === 'POST') {
            $msg = trim((string)$request->post('greeting_message', ''));
            if ($msg !== '') {
                set_plugin_setting('hello-favorite', 'greeting_message', $msg);
                $savedNotice = 'Greeting message updated successfully!';
                cms_log('Hello Favorite settings updated', 'info', ['user_id' => $_SESSION['auth_user_id'] ?? null]);
            }
        }
        
        $currentGreeting = plugin_setting('hello-favorite', 'greeting_message', 'Welcome to Favorite CMS!');
        
        ob_start();
        ?>
        <?php if ($savedNotice): ?>
            <div class="notice notice-success" style="padding: 10px 14px; background: #ecfdf5; border-left: 4px solid #059669; color: #065f46; margin-bottom: 16px; border-radius: 3px;">
                &#10003; <?php echo htmlspecialchars($savedNotice, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div style="max-width: 600px;">
            <p style="margin-bottom: 16px; color: #475569; font-size: 14px;">
                This admin page is rendered dynamically by the <strong>Hello Favorite</strong> reference plugin.
                It demonstrates public admin menu registration, permission verification, and isolated settings storage.
            </p>

            <form method="POST" action="/admin/page/hello-favorite">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                
                <div style="margin-bottom: 16px;">
                    <label for="greeting_message" style="display: block; font-weight: 600; margin-bottom: 6px;">Custom Greeting Message:</label>
                    <input type="text" id="greeting_message" name="greeting_message" value="<?php echo htmlspecialchars($currentGreeting, ENT_QUOTES, 'UTF-8'); ?>" class="form-control" style="width: 100%; padding: 8px 12px; border: 1px solid #c3c4c7; border-radius: 4px;" required>
                </div>

                <button type="submit" class="btn btn-primary" style="background: #2271b1; color: #fff; border: none; padding: 7px 16px; border-radius: 3px; cursor: pointer; font-weight: 600;">
                    Save Plugin Settings
                </button>
                <a href="/hello-favorite" target="_blank" class="btn" style="margin-left: 8px; text-decoration: none; color: #2271b1;">
                    View Frontend Page &rarr;
                </a>
            </form>
        </div>
        <?php
        return (string)ob_get_clean();
    },
    'manage_options',
    55
);

// 4. Content Filter Example: Append powered note to post excerpt or content
add_filter('the_content', function(string $content) {
    return $content;
});

// 5. Plugin Lifecycle Hook Listeners
add_action('plugin.activated', function(string $pluginId) {
    if ($pluginId === 'hello-favorite') {
        // Set default configuration upon initial activation
        if (!plugin_setting('hello-favorite', 'greeting_message')) {
            set_plugin_setting('hello-favorite', 'greeting_message', 'Welcome to Favorite CMS!');
        }
    }
});
