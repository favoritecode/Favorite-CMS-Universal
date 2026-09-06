<?php
/**
 * Customer Storefront Index View
 *
 * @var array  $catalog
 * @var array  $products
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $typeCounts
 * @var string $activeSort
 * @var string $searchTerm
 * @var string $activeType
 * @var string $activePrice
 * @var string $activeMembership
 * @var string $siteCurrency
 * @var int    $userId
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */

$buildStoreUrl = function (array $overrides = []) use ($searchTerm, $activeType, $activePrice, $activeMembership, $activeSort, $page): string {
    $current = [
        'search'       => $searchTerm ?: ($_GET['search'] ?? $_GET['q'] ?? ''),
        'product_type' => $activeType ?: ($_GET['product_type'] ?? ''),
        'price'        => $activePrice ?: ($_GET['price'] ?? ''),
        'membership'   => $activeMembership ?: ($_GET['membership'] ?? ''),
        'sort'         => $activeSort ?: ($_GET['sort'] ?? 'newest'),
        'page'         => $page ?: (int)($_GET['page'] ?? 1),
    ];
    $merged = array_merge($current, $overrides);
    $params = [];
    if (!empty($merged['search'])) {
        $params['search'] = $merged['search'];
    }
    if (!empty($merged['product_type'])) {
        $params['product_type'] = $merged['product_type'];
    }
    if (!empty($merged['price'])) {
        $params['price'] = $merged['price'];
    }
    if (!empty($merged['membership'])) {
        $params['membership'] = $merged['membership'];
    }
    if (!empty($merged['sort']) && $merged['sort'] !== 'newest') {
        $params['sort'] = $merged['sort'];
    } elseif (!empty($overrides['sort'])) {
        $params['sort'] = $overrides['sort'];
    }
    if (isset($merged['page']) && (int)$merged['page'] > 1) {
        $params['page'] = (int)$merged['page'];
    }
    return '/store' . (!empty($params) ? '?' . http_build_query($params) : '');
};

$GLOBALS['_storefrontBuildUrl'] = $buildStoreUrl;

if (!function_exists('buildStoreUrl')) {
    function buildStoreUrl(array $overrides = []): string {
        if (isset($GLOBALS['_storefrontBuildUrl']) && is_callable($GLOBALS['_storefrontBuildUrl'])) {
            return ($GLOBALS['_storefrontBuildUrl'])($overrides);
        }
        return '/store';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Store — Favorite CMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 24px 16px; line-height: 1.5; }
        .store-container { max-width: 1200px; margin: 0 auto; }
        .store-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; }
        .store-title-area h1 { margin: 0; font-size: 28px; color: #0f172a; font-weight: 800; letter-spacing: -0.02em; }
        .store-title-area p { margin: 4px 0 0 0; color: #64748b; font-size: 15px; }
        .store-nav-links { display: flex; gap: 12px; align-items: center; }
        .store-nav-links a { color: #2563eb; text-decoration: none; font-size: 14px; font-weight: 600; padding: 6px 12px; border-radius: 6px; background: #eff6ff; transition: background 0.2s; }
        .store-nav-links a:hover { background: #dbeafe; }
        
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }

        /* Discovery Controls */
        .controls-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .search-bar { display: flex; gap: 10px; margin-bottom: 16px; }
        .search-input-wrap { flex: 1; position: relative; }
        .search-input { width: 100%; padding: 10px 14px; font-size: 15px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .search-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .btn-search { padding: 10px 20px; background: #2563eb; color: #ffffff; border: none; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s; }
        .btn-search:hover { background: #1d4ed8; }
        .btn-reset { padding: 10px 16px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; }
        .btn-reset:hover { background: #e2e8f0; }

        .filter-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; }
        .type-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; text-decoration: none; background: #f1f5f9; color: #475569; border: 1px solid transparent; transition: all 0.2s; }
        .tab-btn:hover { background: #e2e8f0; }
        .tab-btn.active { background: #0f172a; color: #ffffff; }
        .tab-badge { font-size: 11px; padding: 2px 6px; border-radius: 10px; background: rgba(0,0,0,0.08); }
        .tab-btn.active .tab-badge { background: rgba(255,255,255,0.25); color: #fff; }

        .secondary-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .select-filter { padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; color: #334155; font-weight: 500; outline: none; }
        .select-filter:focus { border-color: #2563eb; }

        /* Product Grid */
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; margin-bottom: 36px; }
        .product-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s; position: relative; }
        .product-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.06); border-color: #cbd5e1; }
        
        .card-header-bar { padding: 14px 18px 0; display: flex; justify-content: space-between; align-items: center; }
        .type-tag { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 3px 8px; border-radius: 4px; }
        .type-digital { background: #e0e7ff; color: #4338ca; }
        .type-service { background: #fef3c7; color: #b45309; }
        .type-package { background: #fae8ff; color: #86198f; }
        .type-membership { background: #dcfce7; color: #15803d; }
        
        .state-tag { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
        .state-owned { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .state-member { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .state-required { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .state-free { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

        .card-body { padding: 16px 18px; flex: 1; display: flex; flex-direction: column; }
        .product-title { margin: 0 0 8px 0; font-size: 18px; font-weight: 700; color: #0f172a; line-height: 1.3; }
        .product-title a { color: inherit; text-decoration: none; }
        .product-title a:hover { color: #2563eb; }
        .product-desc { margin: 0 0 16px 0; font-size: 14px; color: #64748b; line-height: 1.5; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        .item-meta-info { font-size: 12px; color: #64748b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .item-meta-badge { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #475569; }

        .pricing-box { margin-top: auto; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: baseline; }
        .price-area { display: flex; align-items: baseline; gap: 8px; }
        .final-price { font-size: 20px; font-weight: 800; color: #0f172a; }
        .original-price { font-size: 14px; color: #94a3b8; text-decoration: line-through; }
        .discount-pill { font-size: 11px; font-weight: 700; color: #dc2626; background: #fef2f2; padding: 2px 6px; border-radius: 4px; border: 1px solid #fecaca; }

        .card-footer { padding: 0 18px 18px; display: flex; gap: 8px; }
        .btn-card { flex: 1; text-align: center; padding: 9px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.2s; display: inline-flex; justify-content: center; align-items: center; }
        .btn-card-primary { background: #2563eb; color: #ffffff; border: 1px solid #2563eb; }
        .btn-card-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-card-secondary { background: #ffffff; color: #334155; border: 1px solid #cbd5e1; }
        .btn-card-secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-card-owned { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .btn-card-owned:hover { background: #d1fae5; }
        .btn-card-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .btn-card-warning:hover { background: #fef3c7; }

        /* Empty State */
        .empty-state { background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 48px 24px; text-align: center; margin-bottom: 36px; }
        .empty-icon { font-size: 42px; margin-bottom: 12px; }
        .empty-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
        .empty-desc { font-size: 14px; color: #64748b; max-width: 420px; margin: 0 auto 18px; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap; }
        .page-link { padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; font-size: 14px; font-weight: 600; transition: all 0.2s; }
        .page-link:hover { background: #f1f5f9; border-color: #94a3b8; }
        .page-link.active { background: #0f172a; color: #fff; border-color: #0f172a; }
        .page-link.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    </style>
</head>
<body>

<div class="store-container">
    <!-- Header Area -->
    <header class="store-header">
        <div class="store-title-area">
            <h1>Digital Store</h1>
            <p>Explore software, digital downloads, services, bundles, and premium memberships.</p>
        </div>
        <nav class="store-nav-links" aria-label="Customer Navigation">
            <?php if ($userId > 0): ?>
                <a href="/account/downloads">📥 My Downloads</a>
                <a href="/account/orders">📋 My Orders</a>
            <?php else: ?>
                <a href="/login?redirect=/store">🔑 Sign In</a>
            <?php endif; ?>
        </nav>
    </header>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Search & Discovery Controls -->
    <section class="controls-card" aria-label="Storefront Filters">
        <form method="GET" action="/store" id="filterForm">
            <div class="search-bar">
                <div class="search-input-wrap">
                    <label for="storeSearch" class="visually-hidden" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;">Search Products</label>
                    <input type="text"
                           id="storeSearch"
                           name="search"
                           class="search-input"
                           placeholder="Search digital products, services, bundles..."
                           value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>"
                           aria-label="Search catalog">
                </div>
                <button type="submit" class="btn-search">Search</button>
                <?php if ($searchTerm !== '' || $activeType !== '' || $activePrice !== '' || $activeMembership !== '' || $activeSort !== 'newest'): ?>
                    <a href="/store" class="btn-reset" title="Reset all filters">Reset</a>
                <?php endif; ?>
            </div>

            <div class="filter-row">
                <!-- Type Tabs -->
                <div class="type-tabs" role="tablist" aria-label="Product Type Tabs">
                    <a href="<?= htmlspecialchars(buildStoreUrl(['product_type' => '', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === '' ? 'active' : '' ?>">
                        All Products <span class="tab-badge"><?= (int)($typeCounts['all'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['product_type' => 'digital', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'digital' ? 'active' : '' ?>">
                        Downloads <span class="tab-badge"><?= (int)($typeCounts['digital'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['product_type' => 'service', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'service' ? 'active' : '' ?>">
                        Services <span class="tab-badge"><?= (int)($typeCounts['service'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['product_type' => 'package', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'package' ? 'active' : '' ?>">
                        Packages <span class="tab-badge"><?= (int)($typeCounts['package'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['product_type' => 'membership', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'membership' ? 'active' : '' ?>">
                        Memberships <span class="tab-badge"><?= (int)($typeCounts['membership'] ?? 0) ?></span>
                    </a>
                </div>

                <!-- Secondary Filters & Sort Dropdown -->
                <div class="secondary-filters">
                    <?php if ($activeType !== ''): ?>
                        <input type="hidden" name="product_type" value="<?= htmlspecialchars($activeType, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>

                    <label for="priceFilter" style="font-size: 13px; font-weight: 600; color: #475569;">Price:</label>
                    <select name="price" id="priceFilter" class="select-filter" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Prices</option>
                        <option value="free" <?= $activePrice === 'free' ? 'selected' : '' ?>>Free</option>
                        <option value="paid" <?= $activePrice === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>

                    <label for="membershipFilter" style="font-size: 13px; font-weight: 600; color: #475569;">Perks:</label>
                    <select name="membership" id="membershipFilter" class="select-filter" onchange="document.getElementById('filterForm').submit()">
                        <option value="">All Items</option>
                        <option value="eligible" <?= $activeMembership === 'eligible' ? 'selected' : '' ?>>Membership Eligible</option>
                        <option value="plans" <?= $activeMembership === 'plans' ? 'selected' : '' ?>>Membership Plans</option>
                    </select>

                    <label for="sortOrder" style="font-size: 13px; font-weight: 600; color: #475569;">Sort:</label>
                    <select name="sort" id="sortOrder" class="select-filter" onchange="document.getElementById('filterForm').submit()">
                        <option value="newest" <?= $activeSort === 'newest' ? 'selected' : '' ?>>Newest Arrivals</option>
                        <option value="price_asc" <?= $activeSort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                        <option value="price_desc" <?= $activeSort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                        <option value="name_asc" <?= $activeSort === 'name_asc' ? 'selected' : '' ?>>Name: A to Z</option>
                        <option value="name_desc" <?= $activeSort === 'name_desc' ? 'selected' : '' ?>>Name: Z to A</option>
                    </select>
                </div>
            </div>
        </form>
    </section>

    <!-- Product Grid -->
    <?php if (empty($products)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <div class="empty-title">No products found</div>
            <div class="empty-desc">
                <?php if ($searchTerm !== ''): ?>
                    We couldn't find anything matching "<strong><?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?></strong>". Try checking for spelling errors or adjusting your filters.
                <?php else: ?>
                    There are no products matching your selected filter criteria at this time.
                <?php endif; ?>
            </div>
            <a href="/store" class="btn-card btn-card-secondary" style="max-width: 180px; margin: 0 auto;">Browse All Products</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <?php
                $pricing = $p['pricing'];
                $state   = $p['customer_state'];
                $type    = (string)$p['product_type'];
                ?>
                <article class="product-card" data-product-id="<?= (int)$p['id'] ?>">
                    <?php
                    $cardCover = !empty($p['cover_image_url']) ? $p['cover_image_url'] : (!empty($p['cover_image_path']) ? $p['cover_image_path'] : null);
                    ?>
                    <?php if ($cardCover): ?>
                        <div class="card-cover-image" style="width: 100%; height: 160px; overflow: hidden; border-top-left-radius: 12px; border-top-right-radius: 12px; background: #f8fafc;">
                            <a href="/store/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>" style="display: block; width: 100%; height: 100%;">
                                <img src="<?= htmlspecialchars($cardCover, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 100%; object-fit: cover; display: block;" loading="lazy">
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="card-header-bar">
                        <span class="type-tag type-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                            <?= match ($type) {
                                'digital'    => 'Digital',
                                'service'    => 'Service',
                                'package'    => 'Package',
                                'membership' => 'Membership',
                                default      => htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
                            } ?>
                        </span>

                        <?php if ($state['badge'] !== null): ?>
                            <span class="state-tag state-<?= htmlspecialchars($state['state'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($state['badge'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <h2 class="product-title">
                            <a href="/store/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h2>

                        <?php if ($type === 'package' && $p['package_count'] > 0): ?>
                            <div class="item-meta-info">
                                <span class="item-meta-badge">📦 <?= (int)$p['package_count'] ?> items included</span>
                            </div>
                        <?php elseif ($type === 'membership' && !empty($p['plan_summary'])): ?>
                            <div class="item-meta-info">
                                <span class="item-meta-badge">⏳ <?= (int)$p['plan_summary']['duration_count'] ?> <?= htmlspecialchars($p['plan_summary']['duration_unit'], ENT_QUOTES, 'UTF-8') ?> plan</span>
                            </div>
                        <?php endif; ?>

                        <div class="product-desc">
                            <?= htmlspecialchars($p['description'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="pricing-box">
                            <div class="price-area">
                                <span class="final-price"><?= htmlspecialchars($pricing['formatted_final_price'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($pricing['has_discount']): ?>
                                    <span class="original-price"><?= htmlspecialchars($pricing['formatted_original_price'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="discount-pill"><?= (float)$pricing['discount_percent'] ?>% OFF</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card-footer">
                        <a href="/store/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>"
                           class="btn-card btn-card-secondary">
                            Details
                        </a>

                        <?php if ($state['action_url'] !== null): ?>
                            <a href="<?= htmlspecialchars($state['action_url'], ENT_QUOTES, 'UTF-8') ?>"
                               class="btn-card <?= $state['is_owned'] ? 'btn-card-owned' : ($state['state'] === 'membership_required' ? 'btn-card-warning' : 'btn-card-primary') ?>">
                                <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <a href="/store/<?= htmlspecialchars($p['slug'], ENT_QUOTES, 'UTF-8') ?>"
                               class="btn-card btn-card-primary">
                                <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Server-side Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Storefront Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link" aria-label="Previous page">&larr; Prev</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['page' => $i]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link <?= $i === $page ? 'active' : '' ?>"
                       <?= $i === $page ? 'aria-current="page"' : '' ?>>
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars(buildStoreUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link" aria-label="Next page">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
