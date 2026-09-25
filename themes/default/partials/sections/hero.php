<?php
/**
 * Homepage hero section.
 *
 * @var string      $siteTitle
 * @var string|null $siteTagline
 */
$tagline = trim((string)($siteTagline ?? ''));
?>
<section class="home-hero" aria-labelledby="home-hero-title">
    <p class="home-hero__eyebrow">Welcome</p>
    <h1 class="home-hero__title" id="home-hero-title"><?php echo fcd_e($siteTitle ?? 'Favorite CMS'); ?></h1>
    <p class="home-hero__tagline"><?php echo fcd_e($tagline !== '' ? $tagline : 'A fast, modern, and lightweight content management experience built for performance.'); ?></p>
</section>
