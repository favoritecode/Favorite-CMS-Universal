<?php
require APP_ROOT . '/themes/default/header.php';
?>

<main class="main-content" role="main" style="grid-column: 1 / -1; max-width: 760px; margin: 0 auto; width: 100%;">
    <article class="single-article">
        <header class="single-header" style="border-bottom: none; margin-bottom: 1.5rem; padding-bottom: 0;">
            <div style="display: inline-block; background: #eff6ff; color: #1d4ed8; padding: 4px 12px; border-radius: 9999px; font-weight: 700; font-size: 12px; text-transform: uppercase; margin-bottom: 12px;">
                Plugin Route Demo
            </div>
            <h1 class="single-title" style="margin-bottom: 0.75rem;">
                <?php echo htmlspecialchars($greeting ?? 'Hello from Plugin!', ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <p style="color: #64748b; font-size: 1.05rem;">
                Greeting to: <strong style="color: #0f172a;"><?php echo htmlspecialchars($visitor ?? 'Developer', ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
        </header>

        <div class="entry-content">
            <p>
                This public page is served entirely through the <strong>Hello Favorite</strong> reference plugin using 
                <code>add_route()</code> and resolved via the Core template rendering engine.
            </p>
            <blockquote>
                "Favorite CMS Universal: One CMS. Any Website."
            </blockquote>
            <p>
                Plugins can define dynamic public endpoints, render theme-integrated templates, interact with isolated plugin settings, 
                and securely manage administrative views.
            </p>
            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #e2e8f0; display: flex; gap: 12px;">
                <a href="/" class="btn-primary">&larr; Return to Homepage</a>
                <a href="/admin/page/hello-favorite" class="btn-secondary">Edit in Admin &rarr;</a>
            </div>
        </div>
    </article>
</main>

<?php
require APP_ROOT . '/themes/default/footer.php';
