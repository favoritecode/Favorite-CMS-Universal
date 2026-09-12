<?php
$siteTitle = \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
$metaTitle = '404 Page Not Found — ' . $siteTitle;
$fcdHasSidebar = false;
require __DIR__ . '/header.php';
?>

<main class="site-main site-main--narrow" id="main-content" tabindex="-1">
    <?php
    $notFoundActions = [['label' => '← Return to Homepage', 'url' => '/']];
    if (!empty($_SESSION['auth_user_id'])) {
        $notFoundActions[] = ['label' => 'Go to Dashboard', 'url' => '/admin', 'variant' => 'secondary'];
    }
    fcd_partial('empty-state', [
        'code'       => '404',
        'headingTag' => 'h1',
        'title'      => 'Oops! Page Not Found',
        'message'    => "The page or article you are looking for might have been moved, renamed, or temporarily unavailable. Let's get you back on track!",
        'search'     => ['inputId' => 'notfound-search-input', 'placeholder' => 'Search the website...', 'required' => true],
        'actions'    => $notFoundActions,
    ]);
    ?>
</main>

<?php
require __DIR__ . '/footer.php';
