<?php
/**
 * Customer Storefront Product Detail View
 *
 * @var object $product
 * @var array  $pricing
 * @var array  $type_details
 * @var array  $package_items
 * @var array  $customer_state
 * @var string $site_currency
 * @var string $slug
 * @var int    $userId
 * @var string $csrfToken
 * @var string|null $flashSuccess
 * @var string|null $flashError
 */

$type = (string)$product->product_type;
$state = $customer_state;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8') ?> — Digital Store</title>
    <meta name="description" content="<?= htmlspecialchars(substr(strip_tags((string)$product->description), 0, 160), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="canonical" href="/store/<?= htmlspecialchars($product->slug, ENT_QUOTES, 'UTF-8') ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 24px 16px; line-height: 1.6; }
        .detail-container { max-width: 1040px; margin: 0 auto; }

        .breadcrumbs { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; margin-bottom: 24px; }
        .breadcrumbs a { color: #2563eb; text-decoration: none; font-weight: 500; }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs span.separator { color: #cbd5e1; }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 500; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }

        .detail-layout { display: grid; grid-template-columns: 1fr 340px; gap: 32px; align-items: start; }
        @media (max-width: 820px) {
            .detail-layout { grid-template-columns: 1fr; }
        }

        /* Main Content Area */
        .main-content { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .title-header { margin-bottom: 20px; }
        .badge-row { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
        .type-tag { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 4px 10px; border-radius: 4px; }
        .type-digital { background: #e0e7ff; color: #4338ca; }
        .type-service { background: #fef3c7; color: #b45309; }
        .type-package { background: #fae8ff; color: #86198f; }
        .type-membership { background: #dcfce7; color: #15803d; }

        .state-tag { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 4px; }
        .state-owned { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .state-member { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .state-required { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

        h1.product-title { margin: 0; font-size: 28px; font-weight: 800; color: #0f172a; line-height: 1.25; }
        .description-box { font-size: 15px; color: #334155; margin-bottom: 32px; white-space: pre-line; }

        /* Specific Specs Card */
        .specs-section { border-top: 1px solid #e2e8f0; padding-top: 24px; margin-top: 24px; }
        .specs-section h2 { font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 16px; }
        .specs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
        .spec-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; }
        .spec-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 4px; }
        .spec-value { font-size: 14px; font-weight: 700; color: #0f172a; }

        /* Package Items List */
        .package-items-list { list-style: none; padding: 0; margin: 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .package-item-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; background: #fff; }
        .package-item-row:last-child { border-bottom: none; }
        .package-item-title { font-weight: 600; font-size: 14px; color: #0f172a; }
        .package-item-type { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-left: 6px; }
        .package-item-price { font-size: 13px; font-weight: 700; color: #475569; }

        /* Sidebar Action Card */
        .sidebar-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); position: sticky; top: 24px; }
        .pricing-block { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
        .price-label { font-size: 13px; color: #64748b; font-weight: 600; margin-bottom: 6px; }
        .price-display { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .final-price { font-size: 32px; font-weight: 800; color: #0f172a; line-height: 1; }
        .original-price { font-size: 18px; color: #94a3b8; text-decoration: line-through; }
        .discount-badge { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }

        .ownership-banner { padding: 12px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
        .ownership-banner-owned { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .ownership-banner-required { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .ownership-banner-member { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        .btn-action { display: block; width: 100%; padding: 14px; border-radius: 8px; font-size: 15px; font-weight: 700; text-align: center; text-decoration: none; cursor: pointer; transition: background 0.2s, transform 0.1s; border: none; }
        .btn-primary { background: #2563eb; color: #ffffff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #059669; color: #ffffff; }
        .btn-success:hover { background: #047857; }
        .btn-warning { background: #d97706; color: #ffffff; }
        .btn-warning:hover { background: #b45309; }

        .guarantee-note { margin-top: 18px; font-size: 12px; color: #64748b; text-align: center; }
    </style>
</head>
<body>

<div class="detail-container">
    <!-- Breadcrumb -->
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="/store">Digital Store</a>
        <span class="separator">/</span>
        <a href="/store?product_type=<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
            <?= match ($type) {
                'digital'    => 'Downloads',
                'service'    => 'Services',
                'package'    => 'Packages',
                'membership' => 'Memberships',
                default      => htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
            } ?>
        </a>
        <span class="separator">/</span>
        <span aria-current="page"><?= htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success" role="alert"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="detail-layout">
        <!-- Main Description & Specs -->
        <main class="main-content">
            <?php
            $coverImg = !empty($product->cover_image_url) ? $product->cover_image_url : (!empty($product->cover_image_path) ? $product->cover_image_path : null);
            ?>
            <?php if ($coverImg): ?>
                <div class="product-cover-banner" style="margin-bottom: 24px; border-radius: 12px; overflow: hidden; max-height: 380px; border: 1px solid #e2e8f0; background: #f8fafc;">
                    <img src="<?= htmlspecialchars($coverImg, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: auto; max-height: 380px; object-fit: cover; display: block;">
                </div>
            <?php endif; ?>

            <header class="title-header">
                <div class="badge-row">
                    <span class="type-tag type-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>">
                        <?= match ($type) {
                            'digital'    => 'Digital Product',
                            'service'    => 'Service',
                            'package'    => 'Package / Bundle',
                            'membership' => 'Membership Plan',
                            default      => htmlspecialchars($type, ENT_QUOTES, 'UTF-8'),
                        } ?>
                    </span>

                    <?php if ($state['badge'] !== null): ?>
                        <span class="state-tag state-<?= htmlspecialchars($state['state'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($state['badge'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="product-title"><?= htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8') ?></h1>
            </header>

            <div class="description-box">
                <?= nl2br(htmlspecialchars($product->description ?? 'No description provided.', ENT_QUOTES, 'UTF-8')) ?>
            </div>

            <!-- Digital Product Details -->
            <?php if ($type === 'digital' && !empty($type_details)): ?>
                <section class="specs-section" aria-label="Digital Product Specifications">
                    <h2>Digital File Specifications</h2>
                    <div class="specs-grid">
                        <?php if (!empty($type_details['resource_type']) && $type_details['resource_type'] !== 'file'): ?>
                            <div class="spec-item">
                                <div class="spec-label">Resource Type</div>
                                <div class="spec-value"><?= match ($type_details['resource_type']) {
                                    'url'   => 'Online Resource Access',
                                    'both'  => 'Downloadable File + Online Resource',
                                    default => 'Downloadable File',
                                } ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="spec-item">
                            <div class="spec-label">File Format</div>
                            <div class="spec-value"><?= htmlspecialchars($type_details['mime_type'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">File Size</div>
                            <div class="spec-value"><?= htmlspecialchars($type_details['formatted_file_size'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Version</div>
                            <div class="spec-value"><?= htmlspecialchars($type_details['version'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Download Policy</div>
                            <div class="spec-value">
                                <?= $type_details['download_expiry_days'] > 0
                                    ? $type_details['download_expiry_days'] . ' days access'
                                    : 'Lifetime access' ?>
                            </div>
                        </div>
                        <?php if ($type_details['is_membership_eligible']): ?>
                            <div class="spec-item" style="grid-column: 1 / -1;">
                                <div class="spec-label">Membership Privilege</div>
                                <div class="spec-value" style="color: #15803d;">Included with active membership</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Service Details -->
            <?php if ($type === 'service' && !empty($type_details)): ?>
                <section class="specs-section" aria-label="Service Details">
                    <h2>Service Scope & Delivery</h2>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <div class="spec-label">Estimated Delivery</div>
                            <div class="spec-value"><?= (int)$type_details['delivery_time_days'] ?> <?= (int)$type_details['delivery_time_days'] === 1 ? 'Day' : 'Days' ?></div>
                        </div>
                    </div>
                    <?php if (!empty($type_details['service_scope'])): ?>
                        <div style="margin-top: 16px;">
                            <div class="spec-label">Service Scope</div>
                            <p style="font-size: 14px; margin: 4px 0 0; color: #334155;"><?= nl2br(htmlspecialchars($type_details['service_scope'], ENT_QUOTES, 'UTF-8')) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($type_details['requirements_prompt'])): ?>
                        <div style="margin-top: 16px;">
                            <div class="spec-label">Client Requirements Instructions</div>
                            <p style="font-size: 14px; margin: 4px 0 0; color: #334155;"><?= nl2br(htmlspecialchars($type_details['requirements_prompt'], ENT_QUOTES, 'UTF-8')) ?></p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Package Included Items -->
            <?php if ($type === 'package' && !empty($package_items)): ?>
                <section class="specs-section" aria-label="Package Contents">
                    <h2>Included in this Package (<?= count($package_items) ?> items)</h2>
                    <ul class="package-items-list">
                        <?php foreach ($package_items as $pItem): ?>
                            <li class="package-item-row">
                                <div>
                                    <span class="package-item-title"><?= htmlspecialchars($pItem['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="package-item-type">(<?= htmlspecialchars($pItem['product_type'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <?php if (!empty($pItem['description'])): ?>
                                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                            <?= htmlspecialchars(substr($pItem['description'], 0, 100), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="package-item-price">
                                    <?= htmlspecialchars($pItem['formatted_price'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <!-- Membership Plan Details -->
            <?php if ($type === 'membership' && !empty($type_details)): ?>
                <section class="specs-section" aria-label="Membership Plan Terms">
                    <h2>Membership Terms & Privileges</h2>
                    <div class="specs-grid">
                        <div class="spec-item">
                            <div class="spec-label">Billing Cycle</div>
                            <div class="spec-value"><?= ucfirst(htmlspecialchars($type_details['plan_type'], ENT_QUOTES, 'UTF-8')) ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Duration</div>
                            <div class="spec-value"><?= (int)$type_details['duration_count'] ?> <?= htmlspecialchars($type_details['duration_unit'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="spec-item">
                            <div class="spec-label">Auto-Renewal</div>
                            <div class="spec-value">Disabled by default (Manual renewal)</div>
                        </div>
                        <?php if ($type_details['grace_period_days'] > 0): ?>
                            <div class="spec-item">
                                <div class="spec-label">Grace Period</div>
                                <div class="spec-value"><?= (int)$type_details['grace_period_days'] ?> Days</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>

        <!-- Sidebar / Action Card -->
        <aside class="sidebar-card" aria-label="Purchase Action Box">
            <div class="pricing-block">
                <div class="price-label">Price</div>
                <div class="price-display">
                    <span class="final-price"><?= htmlspecialchars($pricing['formatted_final_price'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($pricing['has_discount']): ?>
                        <span class="original-price"><?= htmlspecialchars($pricing['formatted_original_price'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="discount-badge"><?= (float)$pricing['discount_percent'] ?>% OFF</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contextual Customer State Banners -->
            <?php if ($state['state'] === 'owned'): ?>
                <div class="ownership-banner ownership-banner-owned">
                    <strong>✓ Already Owned</strong><br>
                    You already have active access to this item. Duplicate purchases are not permitted.
                </div>
                <a href="<?= htmlspecialchars($state['action_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-success">
                    <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                </a>

            <?php elseif ($state['state'] === 'membership_access'): ?>
                <div class="ownership-banner ownership-banner-member">
                    <strong>✓ Included in your Membership</strong><br>
                    As an active member, this product is immediately available to you.
                </div>
                <a href="<?= htmlspecialchars($state['action_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-success">
                    <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                </a>

            <?php elseif ($state['state'] === 'membership_required'): ?>
                <div class="ownership-banner ownership-banner-required">
                    <strong>⚠️ Active Membership Required</strong><br>
                    This product requires an active membership to purchase or access.
                </div>
                <a href="<?= htmlspecialchars($state['action_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-warning">
                    View Membership Plans
                </a>

            <?php elseif ($state['state'] === 'guest'): ?>
                <a href="<?= htmlspecialchars($state['action_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn-action btn-primary">
                    <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                </a>

            <?php else: ?>
                <!-- Purchasable (Free, Standard Paid, or Membership Extension) -->
                <?php if ($state['state'] === 'active_member'): ?>
                    <div class="ownership-banner ownership-banner-member">
                        <strong>Active Member</strong><br>
                        Purchasing will extend your current subscription period.
                    </div>
                <?php endif; ?>

                <form method="POST" action="/store/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>/buy">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-action btn-primary">
                        <?= htmlspecialchars($state['button_text'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            <?php endif; ?>

            <div class="guarantee-note">
                🔒 Safe & Authoritative Server Settlement<br>
                Currency: <?= htmlspecialchars($site_currency, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </aside>
    </div>
</div>

</body>
</html>
