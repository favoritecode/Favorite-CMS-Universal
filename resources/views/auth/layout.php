<?php
/**
 * Authentication shell (login, registration, verification screens).
 *
 * @var \Closure $e       HTML escaper
 * @var \Closure $url     Base-path aware URL builder
 * @var string   $siteName
 * @var string   $content Rendered screen body
 * @var string   $pageTitle
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../partials/standalone/head.php'; ?>
</head>
<body>
<main class="fc-auth">
    <div class="fc-auth__inner fc-auth__inner--account">
        <header class="fc-auth__header">
            <a class="fc-brand" href="<?php echo $e($url('/')); ?>">
                <span class="fc-brand__mark" aria-hidden="true">&#9733;</span>
                <span class="fc-brand__name"><?php echo $e($siteName); ?></span>
            </a>
            <span class="fc-auth__header-label">Account access</span>
        </header>

        <div class="fc-auth__frame">
            <aside class="fc-auth__intro" aria-label="Welcome">
                <div class="fc-auth__intro-copy">
                    <span class="fc-auth__eyebrow">A space to connect</span>
                    <h2>Good to have<br>you here.</h2>
                    <p>Manage your profile, join the conversation, and make yourself at home.</p>
                </div>
                <div class="fc-auth__art" aria-hidden="true">
                    <span class="fc-auth__art-orbit"></span>
                    <span class="fc-auth__art-card"><span></span><span></span><span></span></span>
                    <span class="fc-auth__art-dot"></span>
                </div>
                <p class="fc-auth__intro-note">Your connection to <?php echo $e($siteName); ?>.</p>
            </aside>
            <?php echo $content; ?>
        </div>

        <p class="fc-auth__footer"><a href="<?php echo $e($url('/')); ?>">&larr; Back to <?php echo $e($siteName); ?></a></p>
    </div>
</main>
<?php include __DIR__ . '/../partials/standalone/scripts.php'; ?>
</body>
</html>
