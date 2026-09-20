<?php
/**
 * Shared <head> content for standalone screens (installer and authentication).
 * Styles are inlined so these screens never depend on static asset routing or base-path rewrites.
 *
 * @var string|null $pageTitle
 */
$standaloneTheme = 'dark';
if (isset($_COOKIE['favorite_admin_theme']) && $_COOKIE['favorite_admin_theme'] === 'light') {
    $standaloneTheme = 'light';
} elseif (isset($_SESSION['admin_theme']) && $_SESSION['admin_theme'] === 'light') {
    $standaloneTheme = 'light';
}
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <title><?php echo htmlspecialchars((string)($pageTitle ?? 'Favorite CMS'), ENT_QUOTES, 'UTF-8'); ?></title>
    <script>
    (function() {
        try {
            var localTheme = localStorage.getItem('favorite_admin_theme');
            if (localTheme === 'light' || localTheme === 'dark') {
                document.documentElement.setAttribute('data-admin-theme', localTheme);
            } else {
                var serverTheme = <?php echo json_encode($standaloneTheme); ?>;
                document.documentElement.setAttribute('data-admin-theme', serverTheme);
            }
        } catch (e) {
            document.documentElement.setAttribute('data-admin-theme', 'dark');
        }
    })();
    </script>
    <style><?php readfile(__DIR__ . '/ui.css'); ?></style>
