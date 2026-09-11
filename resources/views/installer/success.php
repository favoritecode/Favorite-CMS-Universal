<?php
['e' => $h] = require __DIR__ . '/../partials/standalone/view-helpers.php';

$isRestore = str_ends_with((string)$siteName, '(Restored)');
$migrationCount = is_countable($migrations ?? null) ? count($migrations) : 0;
$pageTitle = $isRestore ? 'Site restored - Favorite CMS' : 'Favorite CMS installed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../partials/standalone/head.php'; ?>
</head>
<body>
<main class="fc-auth">
    <div class="fc-auth__inner fc-auth__inner--wide">
        <header class="fc-auth__header">
            <span class="fc-brand"><span class="fc-brand__mark" aria-hidden="true">&#9733;</span><span class="fc-brand__name">Favorite CMS</span></span>
        </header>

        <section class="fc-auth__card" aria-labelledby="success-title">
            <span class="fc-status-icon fc-status-icon--success" aria-hidden="true">&#10003;</span>
            <header>
                <h1 class="fc-auth__title" id="success-title"><?php echo $isRestore ? 'Your site has been restored' : 'You&rsquo;re all set'; ?></h1>
                <p class="fc-auth__subtitle"><?php echo $isRestore ? 'The backup was restored and site links now point to this address.' : 'Your new website is installed and ready to use.'; ?></p>
            </header>

            <div class="fc-alert fc-alert--success" role="status">
                <p><?php echo $isRestore ? 'Favorite CMS site restored successfully.' : 'Favorite CMS installed successfully.'; ?></p>
            </div>

            <dl class="fc-review">
                <div class="fc-review__row"><dt>Site name</dt><dd><?php echo $h($siteName); ?></dd></div>
                <div class="fc-review__row"><dt>Site URL</dt><dd><?php echo $h($siteUrl); ?></dd></div>
                <div class="fc-review__row"><dt>Administrator</dt><dd><strong><?php echo $h($adminUsername); ?></strong></dd></div>
                <div class="fc-review__row"><dt>Administrator email</dt><dd><?php echo $h($adminEmail); ?></dd></div>
                <div class="fc-review__row"><dt><?php echo $isRestore ? 'Tables restored' : 'Migrations'; ?></dt><dd><?php echo (int)$migrationCount; ?> <?php echo $isRestore ? 'in this run' : 'applied this run'; ?></dd></div>
            </dl>

            <div>
                <h2 class="fc-legend">What to do next</h2>
                <ol class="fc-next-steps">
                    <li><span class="fc-next-steps__num" aria-hidden="true">1</span><span><?php echo $isRestore ? 'Log in with the administrator credentials from the original site.' : 'Log in with the administrator account you just created.'; ?></span></li>
                    <li><span class="fc-next-steps__num" aria-hidden="true">2</span><span>Review your site settings, theme and navigation menus.</span></li>
                    <li><span class="fc-next-steps__num" aria-hidden="true">3</span><span><?php echo $isRestore ? 'Check a few pages and media files to confirm everything came across.' : 'Publish your first page or post.'; ?></span></li>
                </ol>
            </div>

            <div class="fc-auth__actions">
                <a class="fc-btn fc-btn--primary" href="<?php echo $h($loginUrl); ?>">Log in to the dashboard</a>
                <a class="fc-btn fc-btn--secondary" href="<?php echo $h($homeUrl); ?>">Visit your site</a>
            </div>

            <p class="fc-hint">The installer is now locked. To start over you would need to remove the installation, so keep a backup before making major changes.</p>
        </section>
    </div>
</main>
</body>
</html>
