<?php
/** Inline assets work before installation and in subdirectory installations. */
$appearanceTheme = \FavoriteCMS\Services\Appearance::resolve($currentAdminUser ?? null);
$appearanceConfig = ['theme' => $appearanceTheme];
if (!empty($appearancePersist)) {
    $appearanceConfig['endpoint'] = site_path('/admin/appearance/toggle');
    $appearanceConfig['token'] = $_SESSION['_token'] ?? '';
}
?>
<script>
window.favoriteAppearanceConfig = <?php echo json_encode($appearanceConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
<?php readfile(__DIR__ . '/theme.js'); ?>
</script>
