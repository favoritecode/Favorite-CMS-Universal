<?php
/**
 * Favorite CMS Universal — Core Updates Admin View
 *
 * @var string $currentVersion
 * @var array $discovery
 * @var array $health
 * @var array $state
 * @var array $logs
 * @var array|null $pendingPackage
 * @var bool $inProgress
 * @var string|null $notice
 * @var string|null $error
 * @var string $csrfToken
 */
$base = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
?>

<div class="wrap" style="max-width: 1040px; margin: 0 auto; padding-bottom: 40px;">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: gap: 12px;">
        <div>
            <h1 style="font-size: 24px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; display: flex; align-items: center; gap: 10px;">
                🔄 Core Updates
                <span class="badge badge-secondary" style="font-size: 12px; font-weight: 600; text-transform: none;">
                    v<?php echo htmlspecialchars($currentVersion); ?>
                </span>
            </h1>
            <p style="color: #64748b; font-size: 13.5px; margin: 0;">
                Safely upgrade Favorite CMS Universal Core without losing posts, pages, plugins, media, or settings.
            </p>
        </div>

        <div>
            <form method="POST" action="<?php echo $base; ?>/admin/updates/check" style="display: inline;">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <button type="submit" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Check for Updates
                </button>
            </form>
        </div>
    </div>

    <!-- Flash Notices -->
    <?php if (!empty($notice)): ?>
        <div class="notice notice-success">
            <strong>Success:</strong> <?php echo htmlspecialchars($notice); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="notice notice-error">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- In Progress Alert -->
    <?php if ($inProgress): ?>
        <div class="notice notice-warning" style="display: flex; align-items: center; gap: 12px;">
            <div class="spinner" style="width: 16px; height: 16px; border: 2px solid #fde68a; border-top-color: #d97706; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
            <div>
                <strong>Update Operation in Progress:</strong> A Core update is currently executing on this server. Maintenance mode is active. Please do not close or refresh this page.
            </div>
        </div>
    <?php endif; ?>

    <!-- Pending Uploaded Package Card -->
    <?php if ($pendingPackage): ?>
        <div class="card" style="border: 2px solid #3b82f6; background: #eff6ff; margin-bottom: 24px;">
            <div class="card-header" style="background: #dbeafe; border-bottom: 1px solid #bfdbfe;">
                <h5 style="color: #1e40af; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    📦 Staged Update Package Ready for Installation
                </h5>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 16px; font-size: 14px; color: #1e3a8a;">
                    A manual update package was uploaded and passed all security and structure validations:
                </p>

                <div style="background: #ffffff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px; margin-bottom: 20px;">
                    <div class="row">
                        <div class="col-md-6" style="margin-bottom: 8px;">
                            <strong>Package File:</strong> <code><?php echo htmlspecialchars((string)$pendingPackage['filename']); ?></code>
                        </div>
                        <div class="col-md-6" style="margin-bottom: 8px;">
                            <strong>Target Version:</strong> <span class="badge badge-primary">v<?php echo htmlspecialchars((string)$pendingPackage['version']); ?></span>
                        </div>
                        <div class="col-md-6" style="margin-bottom: 8px;">
                            <strong>Package Size:</strong> <?php echo round(((int)$pendingPackage['size']) / 1024 / 1024, 2); ?> MB
                        </div>
                        <div class="col-md-6" style="margin-bottom: 8px;">
                            <strong>SHA-256:</strong> <code style="font-size: 11px; word-break: break-all;"><?php echo htmlspecialchars((string)$pendingPackage['sha256']); ?></code>
                        </div>
                    </div>
                </div>

                <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 14px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; color: #92400e;">
                    <strong>🛡️ Automatic Safety Guarantee:</strong> Before files are replaced, an automatic full backup of your website (database, uploads, themes, and plugins) will be created. Your existing database tables, media, and <code>.env</code> credentials will be preserved.
                </div>

                <div style="display: flex; gap: 12px; align-items: center;">
                    <form method="POST" action="<?php echo $base; ?>/admin/updates/apply" onsubmit="return confirm('Are you sure you want to proceed with installing this Core update? A full automatic backup will be created first.');">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="source" value="manual">
                        <button type="submit" class="btn btn-primary" style="padding: 9px 20px; font-weight: 600; font-size: 14px;">
                            🚀 Install Update Now
                        </button>
                    </form>

                    <form method="POST" action="<?php echo $base; ?>/admin/updates/cancel-upload">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <button type="submit" class="btn btn-secondary">Cancel Upload</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Left Column: Update Sources & Actions -->
        <div class="col-md-7">
            <!-- Remote Update Card -->
            <div class="card">
                <div class="card-header">
                    <h5>Official GitHub Releases</h5>
                    <span class="text-muted" style="font-size: 12px;">
                        Channel: <strong>Stable Core</strong>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!empty($discovery['error'])): ?>
                        <div class="alert alert-warning" style="margin-bottom: 16px; font-size: 13px;">
                            <?php echo htmlspecialchars((string)$discovery['error']); ?>
                        </div>
                    <?php elseif ($discovery['update_available']): ?>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin-bottom: 18px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                <h4 style="margin: 0; color: #166534; font-size: 16px; font-weight: 700;">
                                    🎉 New Version Available: v<?php echo htmlspecialchars((string)$discovery['latest_version']); ?>
                                </h4>
                                <span class="badge badge-success">Update Available</span>
                            </div>

                            <p style="font-size: 13px; color: #15803d; margin-bottom: 12px;">
                                A newer version of Favorite CMS Universal is ready. Published on <?php echo htmlspecialchars(date('M j, Y', strtotime((string)$discovery['published_at']))); ?>.
                            </p>

                            <?php if (!empty($discovery['release_notes'])): ?>
                                <details style="background: #ffffff; border: 1px solid #dcfce7; border-radius: 6px; padding: 10px; margin-bottom: 16px; font-size: 12.5px;">
                                    <summary style="cursor: pointer; font-weight: 600; color: #166534;">View Release Notes</summary>
                                    <pre style="white-space: pre-wrap; font-family: monospace; font-size: 11.5px; color: #334155; margin-top: 10px;"><?php echo htmlspecialchars((string)$discovery['release_notes']); ?></pre>
                                </details>
                            <?php endif; ?>

                            <form method="POST" action="<?php echo $base; ?>/admin/updates/apply" onsubmit="return confirm('Download and install Core update v<?php echo htmlspecialchars((string)$discovery['latest_version']); ?>? An automatic full backup will be created first.');">
                                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="source" value="remote">
                                <button type="submit" class="btn btn-success" style="padding: 9px 20px; font-weight: 600;">
                                    ⚡ Download &amp; Update Automatically
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 24px 16px;">
                            <div style="font-size: 36px; margin-bottom: 10px;">✅</div>
                            <h4 style="margin: 0 0 6px 0; color: #0f172a; font-size: 16px;">Your Core is Up to Date</h4>
                            <p style="color: #64748b; font-size: 13px; margin: 0;">
                                You are running the latest version of Favorite CMS Universal (v<?php echo htmlspecialchars($currentVersion); ?>).
                            </p>
                        </div>
                    <?php endif; ?>

                    <div style="font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 12px; display: flex; justify-content: space-between;">
                        <span>Last checked: <?php echo !empty($discovery['checked_at']) ? htmlspecialchars(date('Y-m-d H:i:s', strtotime((string)$discovery['checked_at']))) : 'Just now'; ?></span>
                        <a href="<?php echo htmlspecialchars((string)$discovery['release_url']); ?>" target="_blank" rel="noopener" style="color: var(--wp-blue);">GitHub Releases &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Manual ZIP Upload Card -->
            <div class="card">
                <div class="card-header">
                    <h5>Manual Package Upload</h5>
                    <span class="text-muted" style="font-size: 12px;">Shared Hosting &amp; Offline</span>
                </div>
                <div class="card-body">
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                        If your server has outgoing network restrictions or you downloaded <code>Favorite-CMS-Universal.zip</code> manually, you can upload the release archive directly.
                    </p>

                    <form method="POST" action="<?php echo $base; ?>/admin/updates/upload" enctype="multipart/form-data">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155;">
                                Select Core Update Package (.zip):
                            </label>
                            <input type="file" name="update_package" accept=".zip" required class="form-control" style="font-size: 13px; padding: 8px;">
                        </div>

                        <button type="submit" class="btn btn-secondary" style="font-weight: 500;">
                            📤 Upload &amp; Validate Package
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: System Readiness & Pre-Update Health -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <h5>System Readiness Check</h5>
                    <?php if ($health['passed']): ?>
                        <span class="badge badge-success">Ready</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Attention Needed</span>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding: 12px 16px;">
                    <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px;">
                        <?php foreach ($health['checks'] as $key => $check): ?>
                            <li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                <div>
                                    <div style="font-weight: 600; color: #1e293b;">
                                        <?php echo htmlspecialchars((string)$check['title']); ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: #64748b;">
                                        <?php echo htmlspecialchars((string)$check['message']); ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($check['passed']): ?>
                                        <span style="color: #16a34a; font-weight: 700; font-size: 14px;">✔</span>
                                    <?php else: ?>
                                        <span style="color: #dc2626; font-weight: 700; font-size: 14px;">✖</span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Data Preservation Card -->
            <div class="card" style="background: #f8fafc;">
                <div class="card-header">
                    <h5>Data Preservation Architecture</h5>
                </div>
                <div class="card-body" style="font-size: 12.5px; color: #475569; line-height: 1.6;">
                    <p style="margin-bottom: 10px;">
                        Favorite CMS Universal uses a strict allowlist/denylist boundary:
                    </p>
                    <ul style="padding-left: 18px; margin-bottom: 12px;">
                        <li><strong>Database:</strong> Preserved completely; only non-destructive additive migrations are run.</li>
                        <li><strong>Media:</strong> <code>public/uploads/</code> is strictly protected and never modified.</li>
                        <li><strong>Plugins:</strong> Installed plugins in <code>plugins/</code> are never touched.</li>
                        <li><strong>Custom Themes:</strong> Preserved completely.</li>
                        <li><strong>Configuration:</strong> <code>.env</code> is never overwritten or deleted.</li>
                    </ul>
                    <p style="margin-bottom: 0; font-size: 12px; color: #64748b;">
                        See <a href="<?php echo $base; ?>/admin/tools" style="color: var(--wp-blue);">Tools &amp; Backup Manager</a> to manage manual backups.
                    </p>
                </div>
            </div>

            <!-- Recent Update Logs -->
            <?php if (!empty($logs)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5>Recent Update Activity</h5>
                    </div>
                    <div class="card-body" style="padding: 10px; max-height: 220px; overflow-y: auto; background: #0f172a; color: #cbd5e1; border-radius: 0 0 6px 6px; font-family: monospace; font-size: 11px;">
                        <?php foreach ($logs as $logLine): ?>
                            <div style="line-height: 1.5;"><?php echo htmlspecialchars($logLine); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

