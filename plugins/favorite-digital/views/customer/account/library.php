<?php
/**
 * Customer Digital Library View
 *
 * @var array  $items
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var int    $totalPages
 * @var array  $typeCounts
 * @var string $activeType
 * @var string $activeStatus
 * @var string $searchTerm
 * @var array  $wallet
 * @var int    $userId
 * @var string $activeTab
 */

$buildLibraryUrl = function (array $overrides = []) use ($searchTerm, $activeType, $activeStatus, $page): string {
    $current = [
        'search'       => $searchTerm ?: ($_GET['search'] ?? $_GET['q'] ?? ''),
        'product_type' => $activeType ?: ($_GET['product_type'] ?? ''),
        'status'       => $activeStatus ?: ($_GET['status'] ?? ''),
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
    if (!empty($merged['status'])) {
        $params['status'] = $merged['status'];
    }
    if (isset($merged['page']) && (int)$merged['page'] > 1) {
        $params['page'] = (int)$merged['page'];
    }
    return '/account/digital' . (!empty($params) ? '?' . http_build_query($params) : '');
};

$GLOBALS['_libraryBuildUrl'] = $buildLibraryUrl;

if (!function_exists('buildLibraryUrl')) {
    function buildLibraryUrl(array $overrides = []): string {
        if (isset($GLOBALS['_libraryBuildUrl']) && is_callable($GLOBALS['_libraryBuildUrl'])) {
            return ($GLOBALS['_libraryBuildUrl'])($overrides);
        }
        return '/account/digital';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Digital Library — Favorite Digital</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.5; }
        .library-wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px 48px; }
        
        /* Header */
        .library-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .library-title-area h1 { margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #0f172a; }
        .library-title-area p { margin: 0; font-size: 14px; color: #64748b; }
        .btn-storefront { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .btn-storefront:hover { background: #1d4ed8; }

        /* Controls Card */
        .controls-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .search-row { display: flex; gap: 10px; margin-bottom: 14px; }
        .search-input { flex: 1; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; outline: none; }
        .search-input:focus { border-color: #2563eb; }
        .btn-search { padding: 10px 20px; background: #0f172a; color: #ffffff; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-reset { padding: 10px 16px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; }

        .filter-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; }
        .type-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
        .tab-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; }
        .tab-btn.active { background: #0f172a; color: #ffffff; border-color: #0f172a; }
        .tab-badge { font-size: 11px; padding: 2px 6px; border-radius: 10px; background: #e2e8f0; color: #334155; }
        .tab-btn.active .tab-badge { background: #334155; color: #ffffff; }

        .select-filter { padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; background: #fff; color: #334155; font-weight: 500; }

        /* Grid */
        .library-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .item-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .item-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.05); }

        .card-header { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
        .card-type-tag { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; }
        .type-digital { background: #eff6ff; color: #1d4ed8; }
        .type-service { background: #fef3c7; color: #b45309; }
        .type-package { background: #fae8ff; color: #86198f; }
        .type-membership { background: #dcfce7; color: #15803d; }

        .card-state-tag { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
        .state-accessible { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .state-revoked { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .state-expired { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .state-unavailable { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .card-body { padding: 16px; flex: 1; display: flex; flex-direction: column; }
        .item-title { margin: 0 0 8px; font-size: 17px; font-weight: 700; color: #0f172a; line-height: 1.3; }
        .item-title a { color: inherit; text-decoration: none; }
        .item-title a:hover { color: #2563eb; }

        .source-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .source-tag { font-size: 11px; font-weight: 600; padding: 2px 7px; background: #f1f5f9; color: #475569; border-radius: 4px; }
        .source-membership { background: #dcfce7; color: #166534; }
        .source-package { background: #fdf4ff; color: #701a75; }

        .item-desc { font-size: 13px; color: #475569; margin-bottom: 14px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1; }

        /* Meta details */
        .item-specs { background: #f8fafc; border-radius: 6px; padding: 10px 12px; font-size: 12px; color: #475569; margin-bottom: 14px; }
        .spec-line { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .spec-line:last-child { margin-bottom: 0; }

        .card-footer { padding: 14px 16px; border-top: 1px solid #f1f5f9; background: #fafafa; }
        .btn-action { display: block; width: 100%; text-align: center; padding: 10px 14px; border-radius: 6px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; box-sizing: border-box; transition: background 0.15s; }
        .btn-primary { background: #2563eb; color: #ffffff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #0f172a; color: #ffffff; }
        .btn-secondary:hover { background: #1e293b; }
        .btn-warning { background: #d97706; color: #ffffff; }
        .btn-warning:hover { background: #b45309; }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

        .download-meta { display: block; font-size: 11px; color: #64748b; text-align: center; margin-top: 6px; }

        /* Empty State */
        .empty-state { background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 48px 24px; text-align: center; }
        .empty-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
        .empty-desc { font-size: 14px; color: #64748b; max-width: 440px; margin: 0 auto 20px; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 16px; }
        .page-link { padding: 8px 14px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; font-size: 13px; font-weight: 600; }
        .page-link.active { background: #0f172a; color: #fff; border-color: #0f172a; }
        .page-link:hover:not(.active) { background: #f1f5f9; }
    </style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="library-wrap">
    <!-- Header Area -->
    <header class="library-header">
        <div class="library-title-area">
            <h1>Digital Library</h1>
            <p>Access your purchased software, downloads, services, packages, and membership perks.</p>
        </div>
        <div>
            <a href="/store" class="btn-storefront">🛍️ Browse Store</a>
        </div>
    </header>

    <!-- Controls Card -->
    <section class="controls-card" aria-label="Library Controls">
        <form method="GET" action="/account/digital" id="libraryFilterForm">
            <div class="search-row">
                <input type="text"
                       name="search"
                       class="search-input"
                       placeholder="Search your library products..."
                       value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>"
                       aria-label="Search library">
                <button type="submit" class="btn-search">Search</button>
                <?php if ($searchTerm !== '' || $activeType !== '' || $activeStatus !== ''): ?>
                    <a href="/account/digital" class="btn-reset" title="Reset filters">Reset</a>
                <?php endif; ?>
            </div>

            <div class="filter-row">
                <!-- Type Tabs -->
                <div class="type-tabs" role="tablist">
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['product_type' => '', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === '' ? 'active' : '' ?>">
                        All Items <span class="tab-badge"><?= (int)($typeCounts['all'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['product_type' => 'digital', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'digital' ? 'active' : '' ?>">
                        Downloads <span class="tab-badge"><?= (int)($typeCounts['digital'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['product_type' => 'service', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'service' ? 'active' : '' ?>">
                        Services <span class="tab-badge"><?= (int)($typeCounts['service'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['product_type' => 'package', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'package' ? 'active' : '' ?>">
                        Packages <span class="tab-badge"><?= (int)($typeCounts['package'] ?? 0) ?></span>
                    </a>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['product_type' => 'membership', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="tab-btn <?= $activeType === 'membership' ? 'active' : '' ?>">
                        Membership <span class="tab-badge"><?= (int)($typeCounts['membership'] ?? 0) ?></span>
                    </a>
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="statusFilter" style="font-size: 13px; font-weight: 600; color: #475569; margin-right: 6px;">Status:</label>
                    <select name="status" id="statusFilter" class="select-filter" onchange="document.getElementById('libraryFilterForm').submit()">
                        <option value="">All Access States</option>
                        <option value="accessible" <?= $activeStatus === 'accessible' ? 'selected' : '' ?>>Active Access</option>
                        <option value="revoked" <?= $activeStatus === 'revoked' ? 'selected' : '' ?>>Revoked / Refunded</option>
                        <option value="expired" <?= $activeStatus === 'expired' ? 'selected' : '' ?>>Expired</option>
                    </select>
                </div>
            </div>
        </form>
    </section>

    <!-- Library Content Grid -->
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <div class="empty-icon">📂</div>
            <div class="empty-title">Your Digital Library is Empty</div>
            <div class="empty-desc">
                <?php if ($searchTerm !== '' || $activeType !== '' || $activeStatus !== ''): ?>
                    No digital products match your active search and filter criteria.
                <?php else: ?>
                    You have not acquired any digital goods, services, packages, or membership plans yet.
                <?php endif; ?>
            </div>
            <a href="/store" class="btn-storefront">Explore Digital Store</a>
        </div>
    <?php else: ?>
        <div class="library-grid">
            <?php foreach ($items as $item): ?>
                <article class="item-card" data-product-id="<?= (int)$item['product_id'] ?>">
                    <div class="card-header">
                        <span class="card-type-tag type-<?= htmlspecialchars($item['product_type'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= strtoupper(htmlspecialchars($item['product_type'], ENT_QUOTES, 'UTF-8')) ?>
                        </span>
                        <span class="card-state-tag <?= htmlspecialchars($item['state_class'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($item['state_label'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="card-body">
                        <h2 class="item-title">
                            <a href="/store/<?= htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </h2>

                        <!-- Source Badges -->
                        <div class="source-badges">
                            <?php foreach ($item['sources'] as $src): ?>
                                <?php
                                    $label = is_array($src) ? ($src['label'] ?? $src['type']) : (string)$src;
                                    $type = is_array($src) ? ($src['type'] ?? 'direct') : (str_contains(strtolower((string)$src), 'membership') ? 'membership' : (str_contains(strtolower((string)$src), 'package') ? 'package' : 'direct'));
                                ?>
                                <span class="source-tag source-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($item['description'])): ?>
                            <div class="item-desc">
                                <?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <!-- Type Specifications -->
                        <?php if ($item['product_type'] === 'digital'): ?>
                            <div class="item-specs">
                                <?php if (!empty($item['file_size_formatted'])): ?>
                                    <div class="spec-line">
                                        <span>File Size:</span>
                                        <strong><?= htmlspecialchars($item['file_size_formatted'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['version'])): ?>
                                    <div class="spec-line">
                                        <span>Version:</span>
                                        <strong><?= htmlspecialchars($item['version'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['expires_at'])): ?>
                                    <div class="spec-line">
                                        <span>Expires:</span>
                                        <strong><?= htmlspecialchars(substr((string)$item['expires_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($item['product_type'] === 'service'): ?>
                            <div class="item-specs">
                                <?php if (!empty($item['delivery_time_days'])): ?>
                                    <div class="spec-line">
                                        <span>Turnaround:</span>
                                        <strong><?= (int)$item['delivery_time_days'] ?> business days</strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['deliverables'])): ?>
                                    <div class="spec-line">
                                        <span>Scope:</span>
                                        <strong><?= htmlspecialchars($item['deliverables'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($item['product_type'] === 'package' && !empty($item['included_items'])): ?>
                            <div class="item-specs">
                                <div class="spec-line">
                                    <span>Included Products:</span>
                                    <strong><?= count($item['included_items']) ?> items</strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-footer">
                        <?php if ($item['product_type'] === 'digital'): ?>
                            <?php if ($item['state'] === 'accessible'): ?>
                                <?php if ($item['can_download']): ?>
                                    <a href="<?= htmlspecialchars($item['download_url'], ENT_QUOTES, 'UTF-8') ?>"
                                       class="btn-action btn-primary">
                                        📥 <?= htmlspecialchars($item['action_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <button disabled class="btn-action btn-disabled">
                                        <?= htmlspecialchars($item['action_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                <?php endif; ?>

                                <?php if (!empty($item['is_unlimited'])): ?>
                                    <span class="download-meta">✨ Unlimited Downloads (Active Membership)</span>
                                <?php elseif (isset($item['remaining'])): ?>
                                    <span class="download-meta">
                                        <?= (int)$item['remaining'] ?> / <?= (int)$item['max_limit'] ?> downloads remaining
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <button disabled class="btn-action btn-disabled">
                                    <?= htmlspecialchars($item['action_label'], ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <?php if ($item['state'] === 'revoked'): ?>
                                    <span class="download-meta" style="color: #dc2626;">Order was refunded. Access revoked.</span>
                                <?php elseif ($item['state'] === 'membership_expired'): ?>
                                    <span class="download-meta" style="color: #d97706;">Membership expired. <a href="/store?product_type=membership">Renew plan</a></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php elseif ($item['product_type'] === 'service'): ?>
                            <a href="/store/<?= htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-secondary">
                                View Service Scope
                            </a>
                        <?php elseif ($item['product_type'] === 'package'): ?>
                            <a href="/store/<?= htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-secondary">
                                View Included Products
                            </a>
                        <?php else: ?>
                            <a href="/account/membership" class="btn-action btn-primary">
                                Manage Membership
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Library Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link" aria-label="Previous page">&larr; Prev</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link <?= $p === $page ? 'active' : '' ?>"
                       <?= $p === $page ? 'aria-current="page"' : '' ?>>
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($buildLibraryUrl(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>"
                       class="page-link" aria-label="Next page">Next &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
