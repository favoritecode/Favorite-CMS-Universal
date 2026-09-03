<?php
$siteTitle = \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$metaTitle = '404 Page Not Found — ' . $siteTitle;
require __DIR__ . '/header.php';
?>

<main class="main-content" role="main" style="grid-column: 1 / -1; max-width: 720px; margin: 0 auto; width: 100%;">
    <div class="error-404-container">
        <div class="error-404-code" aria-hidden="true">404</div>
        <h1 class="error-404-title">Oops! Page Not Found</h1>
        <p class="error-404-text">The page or article you are looking for might have been moved, renamed, or temporarily unavailable. Let's get you back on track!</p>

        <!-- Integrated Search on 404 -->
        <form method="GET" action="/search" style="max-width: 440px; margin: 0 auto 2rem auto;" role="search">
            <div style="display: flex; gap: 8px;">
                <input type="search" name="q" class="form-input" placeholder="Search the website..." aria-label="Search">
                <button type="submit" class="btn-primary">Search</button>
            </div>
        </form>

        <div class="error-404-actions">
            <a href="/" class="btn-primary">&larr; Return to Homepage</a>
            <?php if (!empty($_SESSION['auth_user_id'])): ?>
                <a href="/admin" class="btn-secondary">Go to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
require __DIR__ . '/footer.php';
