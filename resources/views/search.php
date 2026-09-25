<?php
/**
 * Favorite CMS Universal — Standalone Core Fallback Search View
 *
 * Rendered when no external theme is active or when themes are decoupled.
 */
$searchQuery = trim((string)($searchQuery ?? (is_string($_GET['q'] ?? null) ? $_GET['q'] : '')));
$archiveTitle = $searchQuery !== '' ? 'Search Results for: "' . $searchQuery . '"' : 'Search';
require __DIR__ . '/index.php';

