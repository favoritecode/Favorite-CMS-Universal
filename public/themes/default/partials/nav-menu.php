<?php
/**
 * Navigation menu with nested items.
 *
 * @var array<int, object> $items      Menu items (children in ->children)
 * @var string|null        $menuClass  CSS class of the top-level list
 * @var bool|null          $prependHome Add a Home link before the items
 */
$items     = is_array($items ?? null) ? $items : [];
$menuClass = $menuClass ?? 'menu';

if (!empty($prependHome)) {
    array_unshift($items, (object)['title' => 'Home', 'url' => '/', 'target' => '', 'children' => []]);
}

if ($items === []) {
    return;
}

$renderMenu = static function (array $items, int $depth) use (&$renderMenu, $menuClass): void {
    ?>
    <ul class="<?php echo $depth === 0 ? fcd_e($menuClass) : 'sub-menu'; ?>">
        <?php foreach ($items as $item): ?>
            <?php
            $url       = function_exists('menu_item_url') ? menu_item_url($item) : (string)($item->url ?? '#');
            $isCurrent = function_exists('is_current_url') && is_current_url($url);
            $children  = (!empty($item->children) && is_array($item->children) && $depth < 4) ? $item->children : [];
            $classes   = ['menu-item'];
            if ($children !== []) {
                $classes[] = 'menu-item-has-children';
            }
            if ($isCurrent) {
                $classes[] = 'is-current';
            }
            $custom = trim((string)(preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)($item->css_class ?? '')) ?? ''));
            if ($custom !== '') {
                $classes[] = $custom;
            }
            $target = (string)($item->target ?? '');
            $targetAttr = in_array($target, ['_blank', '_parent', '_top'], true)
                ? ' target="' . $target . '"' . ($target === '_blank' ? ' rel="noopener"' : '')
                : '';
            ?>
            <li class="<?php echo fcd_e(implode(' ', $classes)); ?>">
                <a class="menu-link" href="<?php echo fcd_e($url); ?>"<?php echo $isCurrent ? ' aria-current="page"' : ''; ?><?php echo $targetAttr; ?>><?php echo fcd_e($item->title ?? ''); ?></a>
                <?php if ($children !== []) { $renderMenu($children, $depth + 1); } ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};

$renderMenu($items, 0);
