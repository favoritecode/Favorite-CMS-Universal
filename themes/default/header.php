<?php
$siteTitle = $siteTitle ?? \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$siteTagline = $siteTagline ?? \FavoriteCMS\Models\Setting::get('general', 'site_description', '');
$metaTitle = $metaTitle ?? $siteTitle;
$metaDesc = $metaDescription ?? \FavoriteCMS\Models\Setting::get('seo', 'meta_description', '');
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';

$siteLogoUrl = get_theme_mod('site_logo_url');
$accentColor = get_theme_mod('accent_color');
$siteLayout  = get_theme_mod('site_layout', 'right');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (!empty($metaDesc)): ?>
        <meta name="description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/themes/default/assets/css/style.css">
    <?php if (!empty($accentColor)): ?>
        <style>
            :root {
                --color-primary: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;
                --color-primary-hover: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>cc;
            }
        </style>
    <?php endif; ?>
</head>
<body class="layout-<?php echo htmlspecialchars($siteLayout); ?>">

<header class="site-header" role="banner">
    <div class="header-container">
        <!-- Site Brand & Logo -->
        <a href="/" class="site-branding" aria-label="<?php echo htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8'); ?> Homepage">
            <?php if (!empty($siteLogoUrl)): ?>
                <img src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8'); ?>" class="site-custom-logo" style="max-height: 38px; max-width: 180px; object-fit: contain;">
            <?php else: ?>
                <div class="site-logo-icon" aria-hidden="true">&#9733;</div>
                <div class="brand-text">
                    <span class="site-title"><?php echo htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($siteTagline)): ?>
                        <span class="site-tagline"><?php echo htmlspecialchars($siteTagline, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </a>

        <!-- Mobile Menu Toggle Button -->
        <button type="button" class="mobile-nav-toggle" id="mobile-nav-btn" aria-label="Toggle navigation menu" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>

        <!-- Navigation & Search Menu Wrap -->
        <div class="header-nav-wrap" id="header-nav-wrap">
            <nav class="main-nav" role="navigation" aria-label="Main Navigation">
                <ul>
                    <li>
                        <a href="/" class="<?php echo ($currentUri === '/' || $currentUri === '') ? 'active' : ''; ?>">Home</a>
                    </li>
                    <?php
                    // Dynamic navigation from primary menu if assigned, or published pages
                    $primaryMenu = \FavoriteCMS\Models\Menu::findByLocation('primary');
                    if ($primaryMenu) {
                        foreach ($primaryMenu->getItems() as $item) {
                            $itemUrl = $item->url ?? '#';
                            $isActive = ($currentUri === $itemUrl) ? 'active' : '';
                            echo '<li><a href="' . htmlspecialchars($itemUrl, ENT_QUOTES, 'UTF-8') . '" class="' . $isActive . '">' . htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8') . '</a></li>';
                        }
                    } else {
                        $navPages = \FavoriteCMS\Models\Page::published();
                        foreach (array_slice($navPages, 0, 4) as $p) {
                            $pageUrl = '/page/' . $p->slug;
                            $isActive = ($currentUri === $pageUrl) ? 'active' : '';
                            echo '<li><a href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '" class="' . $isActive . '">' . htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8') . '</a></li>';
                        }
                    }
                    ?>
                    <?php if (!empty($_SESSION['auth_user_id'])): ?>
                        <li>
                            <a href="/admin" style="color: var(--color-primary); font-weight: 600;">Dashboard</a>
                        </li>
                    <?php else: ?>
                        <li>
                            <a href="/admin/login" style="color: var(--color-muted); font-size: 0.875rem;">Log In</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>

            <?php if (has_region_widgets('header-right')): ?>
                <div class="header-right-widgets">
                    <?php echo render_region('header-right'); ?>
                </div>
            <?php else: ?>
                <!-- Header Quick Search -->
                <form method="GET" action="/search" class="header-search" role="search">
                    <svg class="header-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="search" 
                           name="q" 
                           class="header-search-input" 
                           placeholder="Search..." 
                           value="<?php echo htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           aria-label="Search posts">
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="site-content <?php echo ($siteLayout === 'left') ? 'site-content-sidebar-left' : ''; ?>">
