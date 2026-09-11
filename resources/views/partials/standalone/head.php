<?php
/**
 * Shared <head> content for standalone screens (installer and authentication).
 * Styles are inlined so these screens never depend on static asset routing or base-path rewrites.
 *
 * @var string|null $pageTitle
 */
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title><?php echo htmlspecialchars((string)($pageTitle ?? 'Favorite CMS'), ENT_QUOTES, 'UTF-8'); ?></title>
    <style><?php readfile(__DIR__ . '/ui.css'); ?></style>
