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
    <div class="fc-auth__inner">
        <header class="fc-auth__header">
            <a class="fc-brand" href="<?php echo $e($url('/')); ?>">
                <span class="fc-brand__mark" aria-hidden="true">&#9733;</span>
                <span class="fc-brand__name"><?php echo $e($siteName); ?></span>
            </a>
        </header>

        <?php echo $content; ?>

        <p class="fc-auth__footer"><a href="<?php echo $e($url('/')); ?>">&larr; Back to <?php echo $e($siteName); ?></a></p>
    </div>
</main>
<?php include __DIR__ . '/../partials/standalone/scripts.php'; ?>
</body>
</html>
