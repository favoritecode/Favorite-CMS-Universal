<?php
/**
 * Customer Membership Dashboard View
 *
 * @var object|null $activeMembership
 * @var bool        $hasActive
 * @var array       $allMemberships
 * @var array       $coveredPerks
 * @var array       $wallet
 * @var string      $siteCurrency
 * @var int         $userId
 * @var string      $activeTab
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Membership — Favorite Digital</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.5; }
        .membership-wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px 48px; }

        .dashboard-header { margin-bottom: 24px; }
        .dashboard-header h1 { margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #0f172a; }
        .dashboard-header p { margin: 0; font-size: 14px; color: #64748b; }

        /* Membership Tier Card */
        .tier-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .tier-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; }
        .tier-title { margin: 0; font-size: 22px; font-weight: 800; color: #0f172a; }
        .badge-status { font-size: 12px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 4px; }
        .status-active { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-grace { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .status-expired { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        .tier-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .info-block { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; }
        .info-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 4px; letter-spacing: 0.03em; }
        .info-value { font-size: 15px; font-weight: 700; color: #0f172a; }

        .tier-actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .btn-tier { padding: 10px 18px; border-radius: 6px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-tier-primary { background: #2563eb; color: #fff; }
        .btn-tier-primary:hover { background: #1d4ed8; }
        .btn-tier-outline { border: 1px solid #cbd5e1; background: #fff; color: #334155; }
        .btn-tier-outline:hover { background: #f1f5f9; }

        /* Section Heading */
        .section-title { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 16px; }

        /* Perks Grid */
        .perks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 36px; }
        .perk-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; }
        .perk-title { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
        .perk-desc { font-size: 12px; color: #64748b; margin-bottom: 12px; }
        .btn-perk { display: block; text-align: center; padding: 8px 12px; background: #059669; color: #ffffff; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 700; }
        .btn-perk:hover { background: #047857; }

        /* History Table */
        .history-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        .history-table { width: 100%; border-collapse: collapse; text-align: left; }
        .history-table th { background: #f8fafc; padding: 12px 16px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #e2e8f0; }
        .history-table td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .history-table tr:last-child td { border-bottom: none; }

        /* Inactive Alert */
        .no-mem-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 32px 24px; text-align: center; margin-bottom: 32px; }
        .no-mem-title { font-size: 20px; font-weight: 800; color: #1e3a8a; margin-bottom: 8px; }
        .no-mem-desc { font-size: 14px; color: #3b82f6; max-width: 480px; margin: 0 auto 20px; }
    </style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="membership-wrap">
    <header class="dashboard-header">
        <h1>Membership Dashboard</h1>
        <p>Manage your subscription tier, billing period, grace period, and membership-covered perks.</p>
    </header>

    <?php if ($hasActive && $activeMembership): ?>
        <section class="tier-card" aria-label="Active Membership Tier">
            <div class="tier-header">
                <div>
                    <h2 class="tier-title"><?= htmlspecialchars((string)($activeMembership->plan_title ?? 'Active Membership Plan'), ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div>
                    <span class="badge-status status-<?= htmlspecialchars($activeMembership->status, ENT_QUOTES, 'UTF-8') ?>">
                        <?= strtoupper(htmlspecialchars($activeMembership->status, ENT_QUOTES, 'UTF-8')) ?>
                    </span>
                </div>
            </div>

            <div class="tier-grid">
                <div class="info-block">
                    <div class="info-label">Plan Type</div>
                    <div class="info-value"><?= ucfirst(htmlspecialchars((string)($activeMembership->plan_type ?? 'Subscription'), ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <div class="info-block">
                    <div class="info-label">Current Expiry</div>
                    <div class="info-value"><?= htmlspecialchars(substr((string)$activeMembership->expires_at, 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="info-block">
                    <div class="info-label">Grace Period</div>
                    <div class="info-value"><?= (int)($activeMembership->grace_period_days ?? 0) ?> Days</div>
                </div>
                <div class="info-block">
                    <div class="info-label">Auto-Renewal</div>
                    <div class="info-value"><?= !empty($activeMembership->auto_renew) ? '✅ Enabled' : '⏸️ Disabled (Manual)' ?></div>
                </div>
            </div>

            <?php if ($activeMembership->status === 'grace'): ?>
                <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px;">
                    ⚠️ Your membership is currently in the grace period. Please renew before <?= htmlspecialchars((string)$activeMembership->grace_expires_at, ENT_QUOTES, 'UTF-8') ?> to prevent loss of access.
                </div>
            <?php endif; ?>

            <div class="tier-actions">
                <a href="/store?product_type=membership" class="btn-tier btn-tier-primary">
                    🔄 Extend / Renew Plan
                </a>
                <a href="/account/digital?product_type=membership" class="btn-tier btn-tier-outline">
                    View Member Perks in Library
                </a>
            </div>
        </section>

        <!-- Covered Perks -->
        <?php if (!empty($coveredPerks)): ?>
            <h2 class="section-title">Unlocked Member Resources</h2>
            <div class="perks-grid">
                <?php foreach ($coveredPerks as $perk): ?>
                    <div class="perk-card">
                        <div>
                            <h3 class="perk-title"><?= htmlspecialchars($perk->title, ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="perk-desc"><?= htmlspecialchars(substr((string)($perk->description ?? ''), 0, 90), ENT_QUOTES, 'UTF-8') ?>...</div>
                        </div>
                        <a href="/store/<?= htmlspecialchars($perk->slug, ENT_QUOTES, 'UTF-8') ?>" class="btn-perk">
                            Access via Store
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-mem-box">
            <div class="no-mem-title">No Active Membership</div>
            <div class="no-mem-desc">
                Subscribe to a VIP membership tier to unlock unlimited downloads of premium resources, priority support, and exclusive discounts.
            </div>
            <a href="/store?product_type=membership" class="btn-tier btn-tier-primary">
                Explore Membership Plans
            </a>
        </div>
    <?php endif; ?>

    <!-- History -->
    <?php if (!empty($allMemberships)): ?>
        <h2 class="section-title">Subscription Records</h2>
        <div class="history-card">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th>Auto-Renew</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allMemberships as $m): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($m->plan_title ?? 'Membership', ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td>
                                <span class="badge-status status-<?= htmlspecialchars($m->status, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= strtoupper(htmlspecialchars($m->status, ENT_QUOTES, 'UTF-8')) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string)$m->expires_at, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($m->auto_renew) ? 'Enabled' : 'Disabled' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
