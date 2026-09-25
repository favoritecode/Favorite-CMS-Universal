<?php
/**
 * Empty state (no posts, no results, 404).
 *
 * @var string      $title
 * @var string|null $message
 * @var string|null $code        Large decorative code such as 404
 * @var string|null $headingTag  'h1' or 'h2'
 * @var array|null  $search      Variables for an embedded search form
 * @var array|null  $actions     List of ['label' => ..., 'url' => ..., 'variant' => 'primary'|'secondary']
 */
$headingTag = ($headingTag ?? 'h2') === 'h1' ? 'h1' : 'h2';
$actions = is_array($actions ?? null) ? $actions : [];
?>
<section class="empty-state">
    <?php if (!empty($code)): ?>
        <p class="empty-state__code" aria-hidden="true"><?php echo fcd_e($code); ?></p>
    <?php endif; ?>
    <<?php echo $headingTag; ?> class="empty-state__title"><?php echo fcd_e($title ?? ''); ?></<?php echo $headingTag; ?>>
    <?php if (!empty($message)): ?>
        <p class="empty-state__message"><?php echo fcd_e($message); ?></p>
    <?php endif; ?>
    <?php if (!empty($search)) { fcd_partial('search-form', $search); } ?>
    <?php if ($actions !== []): ?>
        <div class="empty-state__actions">
            <?php foreach ($actions as $action): ?>
                <a class="button button--<?php echo ($action['variant'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary'; ?>" href="<?php echo fcd_e(fcd_url((string)($action['url'] ?? '/'))); ?>"><?php echo fcd_e($action['label'] ?? ''); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
