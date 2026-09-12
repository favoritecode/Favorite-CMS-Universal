<?php
require_once __DIR__ . '/functions.php';

$siteTitle   = $siteTitle ?? \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$siteTagline = $siteTagline ?? \FavoriteCMS\Models\Setting::get('general', 'site_description', '');
$metaTitle   = $metaTitle ?? $siteTitle;
$metaDesc    = $metaDescription ?? \FavoriteCMS\Models\Setting::get('seo', 'meta_description', '');

$siteLogoUrl    = function_exists('get_site_logo_url') ? get_site_logo_url() : get_theme_mod('site_logo_url');
$siteFaviconUrl = function_exists('get_site_favicon_url') ? get_site_favicon_url() : get_theme_mod('site_favicon_url');
$accentColor    = fcd_accent_color();
$siteLayout     = fcd_site_layout();
$hasSidebar     = ($fcdHasSidebar ?? true) && $siteLayout !== 'none';
$currentUser    = function_exists('current_user') ? current_user() : null;
$primaryMenu    = fcd_menu_items('primary', 4);
$headerWidgets  = has_region_widgets('header-right');
$stylesheetUrl  = function_exists('theme_asset_url') ? theme_asset_url('assets/css/style.css') : fcd_url('/themes/default/assets/css/style.css');

$bodyClasses = ['layout-' . $siteLayout, $hasSidebar ? 'has-sidebar' : 'no-sidebar'];
if (!empty($bodyClass) && is_string($bodyClass)) {
    $bodyClasses[] = $bodyClass;
}
?>
<!DOCTYPE html>
<html lang="<?php echo fcd_e(function_exists('site_language') ? site_language() : 'en'); ?>" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars((string)$metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (!empty($metaDesc)): ?>
        <meta name="description" content="<?php echo htmlspecialchars((string)$metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <script>document.documentElement.className = document.documentElement.className.replace(/\bno-js\b/, 'js');</script>
    <?php if (!empty($siteFaviconUrl) && is_string($siteFaviconUrl)): ?>
        <?php
        $favExt = strtolower(pathinfo(parse_url($siteFaviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $favType = match ($favExt) {
            'ico'   => 'image/x-icon',
            'png'   => 'image/png',
            'svg'   => 'image/svg+xml',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            default => 'image/x-icon',
        };
        ?>
        <link rel="icon" type="<?php echo fcd_e($favType); ?>" href="<?php echo fcd_e(fcd_url($siteFaviconUrl)); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo fcd_e($stylesheetUrl); ?>">
    <?php if ($accentColor !== ''): ?>
        <style>:root { --accent: <?php echo $accentColor; ?>; }</style>
    <?php endif; ?>
    <?php
    // Centralized Frontend SEO, social meta, schema, verification and tracking tags
    if (class_exists(\FavoriteCMS\Services\FrontendSeoService::class)) {
        echo \FavoriteCMS\Services\FrontendSeoService::renderHeadTags(get_defined_vars());
    }
    ?>
</head>
<body class="<?php echo fcd_e(implode(' ', $bodyClasses)); ?>">
<?php
// GTM noscript iframe or body tags immediately after <body> opening
if (class_exists(\FavoriteCMS\Services\FrontendSeoService::class)) {
    echo \FavoriteCMS\Services\FrontendSeoService::renderBodyTags();
}
?>
<a class="skip-link" href="#main-content">Skip to content</a>

<header class="site-header" role="banner">
    <div class="container header-bar">
        <a href="<?php echo fcd_e(fcd_url('/')); ?>" class="site-branding" aria-label="<?php echo fcd_e($siteTitle); ?> Homepage">
            <?php if (!empty($siteLogoUrl) && is_string($siteLogoUrl)): ?>
                <img src="<?php echo fcd_e(fcd_url($siteLogoUrl)); ?>" alt="<?php echo fcd_e($siteTitle); ?>" class="site-custom-logo" decoding="async">
            <?php else: ?>
                <span class="site-logo-icon" aria-hidden="true">&#9733;</span>
                <span class="brand-text">
                    <span class="site-title"><?php echo fcd_e($siteTitle); ?></span>
                    <?php if (!empty($siteTagline)): ?>
                        <span class="site-tagline"><?php echo fcd_e($siteTagline); ?></span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </a>

        <button type="button" class="mobile-nav-toggle" id="mobile-nav-btn" aria-controls="header-nav-wrap" aria-expanded="false">
            <svg class="icon icon-menu" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
            <svg class="icon icon-close" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
            <span class="visually-hidden">Menu</span>
        </button>

        <div class="header-nav-wrap" id="header-nav-wrap">
            <nav class="main-nav" aria-label="Main navigation">
                <?php fcd_partial('nav-menu', [
                    'items'       => $primaryMenu['items'],
                    'menuClass'   => 'menu main-menu',
                    'prependHome' => !$primaryMenu['assigned'],
                ]); ?>
            </nav>

            <?php if ($headerWidgets): ?>
                <div class="header-right-widgets">
                    <?php echo render_region('header-right'); ?>
                </div>
            <?php else: ?>
                <div class="header-search">
                    <?php fcd_partial('search-form', ['inputId' => 'header-search-input', 'formClass' => 'search-form--header', 'placeholder' => 'Search…']); ?>
                </div>
            <?php endif; ?>

            <?php if ($currentUser): ?>
                <?php if (function_exists('current_user_can') && current_user_can('publish_posts')): ?>
                    <div class="header-actions">
                        <a class="button button--primary button--small" href="<?php echo fcd_e(fcd_url('/admin/posts/new')); ?>">+ Create Post</a>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="header-actions">
                    <a class="header-link" href="<?php echo fcd_e(fcd_url('/admin/login')); ?>">Log In</a>
                    <?php if (fcd_registration_enabled()): ?>
                        <a class="button button--primary button--small" href="<?php echo fcd_e(fcd_url('/register')); ?>">Sign Up</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($currentUser): ?>
                <div class="header-account-wrap">
                    <?php echo function_exists('render_account_menu') ? render_account_menu() : '<a href="' . fcd_e(fcd_url('/admin')) . '">Account</a>'; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="site-content<?php echo $siteLayout === 'left' ? ' site-content-sidebar-left' : ''; ?>">
    <div class="container layout <?php echo $hasSidebar ? 'layout--with-sidebar' : 'layout--full'; ?>">
