<?php
$h = static fn ($val): string => htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header" style="margin-bottom: 20px;">
    <h1 class="page-title" style="display: flex; align-items: center; gap: 10px;">
        <span>📥</span> Universal Content Import &amp; Migration
    </h1>
    <p style="color: #64748b; font-size: 13px; margin: 4px 0 0;">
        Migrate your articles, pages, comments, taxonomies, and media assets seamlessly from other platforms into Favorite CMS.
    </p>
</div>

<!-- Sub-navigation Tabs -->
<div style="display: flex; gap: 8px; border-bottom: 2px solid #e2e8f0; margin-bottom: 24px;">
    <a href="/admin/tools" style="padding: 10px 18px; text-decoration: none; font-size: 13px; font-weight: 500; color: #64748b; border-bottom: 2px solid transparent; margin-bottom: -2px;">
        🛠️ Backups &amp; System Health
    </a>
    <a href="/admin/tools/import" style="padding: 10px 18px; text-decoration: none; font-size: 13px; font-weight: 600; color: #2563eb; border-bottom: 2px solid #2563eb; margin-bottom: -2px;">
        📥 Import / Migration Center
    </a>
</div>

<?php if (!empty($notice)): ?>
    <div style="background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-weight: 500;">
        <?php echo $h($notice); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-weight: 500;">
        <?php echo $h($error); ?>
    </div>
<?php endif; ?>

<!-- Post-Import Detailed Report -->
<?php if (!empty($report)): ?>
    <div class="form-card" style="background: #fff; border: 1px solid #10b981; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(16,185,129,0.06);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 17px; font-weight: 700; margin: 0; color: #065f46; display: flex; align-items: center; gap: 8px;">
                <span>✅</span> Migration Execution Report &mdash; <?php echo $h($report['source'] ?? 'Universal'); ?>
            </h2>
            <a href="/admin/tools/import" class="btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 5px 12px; text-decoration: none; border-radius: 4px; font-size: 12px;">Dismiss Report</a>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 16px;">
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 20px; font-weight: 700; color: #15803d;"><?php echo (int)$report['posts']['imported']; ?> / <?php echo (int)$report['posts']['updated']; ?></div>
                <div style="font-size: 11px; color: #166534; font-weight: 600;">Posts Imported / Updated</div>
                <div style="font-size: 10px; color: #64748b;"><?php echo (int)$report['posts']['skipped']; ?> duplicate(s) skipped</div>
            </div>

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 20px; font-weight: 700; color: #15803d;"><?php echo (int)$report['pages']['imported']; ?></div>
                <div style="font-size: 11px; color: #166534; font-weight: 600;">Pages Imported</div>
                <div style="font-size: 10px; color: #64748b;"><?php echo (int)$report['pages']['skipped']; ?> duplicate(s) skipped</div>
            </div>

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 20px; font-weight: 700; color: #15803d;"><?php echo (int)$report['comments']['imported']; ?></div>
                <div style="font-size: 11px; color: #166534; font-weight: 600;">Comments Linked</div>
                <div style="font-size: 10px; color: #64748b;"><?php echo (int)$report['comments']['skipped']; ?> skipped</div>
            </div>

            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 20px; font-weight: 700; color: #1d4ed8;"><?php echo (int)$report['media']['downloaded']; ?></div>
                <div style="font-size: 11px; color: #1e40af; font-weight: 600;">Media Downloaded</div>
                <div style="font-size: 10px; color: #64748b;"><?php echo (int)$report['media']['preserved_externally']; ?> external preserved</div>
            </div>

            <div style="background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 20px; font-weight: 700; color: #7e22ce;"><?php echo (int)$report['taxonomy']['categories_created']; ?> / <?php echo (int)$report['taxonomy']['tags_created']; ?></div>
                <div style="font-size: 11px; color: #6b21a8; font-weight: 600;">Categories / Tags</div>
            </div>
        </div>

        <?php if (!empty($report['errors'])): ?>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; padding: 10px 14px; font-size: 12px; color: #92400e;">
                <strong>Migration Notices / Non-Fatal Warnings:</strong>
                <ul style="margin: 4px 0 0; padding-left: 18px;">
                    <?php foreach (array_slice($report['errors'], 0, 5) as $err): ?>
                        <li><?php echo $h($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Analysis Preview State (When file is analyzed and ready for confirmation) -->
<?php if (!empty($preview)): ?>
    <div class="form-card" style="background: #fff; border: 2px solid #2563eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 6px rgba(37,99,235,0.08);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
            <h2 style="font-size: 18px; font-weight: 700; margin: 0; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <span>🔍</span> Export Analysis: <?php echo $h($preview['source_name'] ?? 'Detected Source'); ?>
            </h2>
            <span style="font-size: 11px; background: #eff6ff; color: #1e40af; padding: 4px 10px; border-radius: 4px; font-weight: 600;">
                Format Verified &amp; Ready
            </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 18px;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 22px; font-weight: 700; color: #2563eb;"><?php echo (int)($preview['counts']['posts'] ?? 0); ?></div>
                <div style="font-size: 12px; color: #64748b; font-weight: 500;">Posts Detected</div>
                <div style="font-size: 10px; color: #94a3b8;"><?php echo (int)($preview['counts']['posts_published'] ?? 0); ?> pub / <?php echo (int)($preview['counts']['posts_draft'] ?? 0); ?> draft</div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 22px; font-weight: 700; color: #059669;"><?php echo (int)($preview['counts']['pages'] ?? 0); ?></div>
                <div style="font-size: 12px; color: #64748b; font-weight: 500;">Pages Detected</div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 22px; font-weight: 700; color: #d97706;"><?php echo (int)($preview['counts']['comments'] ?? 0); ?></div>
                <div style="font-size: 12px; color: #64748b; font-weight: 500;">Comments</div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 22px; font-weight: 700; color: #7c3aed;"><?php echo (int)(($preview['counts']['categories'] ?? 0) + ($preview['counts']['tags'] ?? 0)); ?></div>
                <div style="font-size: 12px; color: #64748b; font-weight: 500;">Taxonomies</div>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">
                <div style="font-size: 22px; font-weight: 700; color: #0284c7;"><?php echo (int)($preview['counts']['media'] ?? 0); ?></div>
                <div style="font-size: 12px; color: #64748b; font-weight: 500;">Media References</div>
            </div>
        </div>

        <?php if (!empty($preview['sample_posts'])): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 18px;">
                <div style="font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 8px;">Sample Content Detected:</div>
                <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #334155; line-height: 1.6;">
                    <?php foreach ($preview['sample_posts'] as $sp): ?>
                        <li>
                            <strong><?php echo $h($sp['title']); ?></strong>
                            <span style="color: #64748b;">(<?php echo $h($sp['date']); ?>, <?php echo $h($sp['status']); ?> by <?php echo $h($sp['author']); ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Import Execution Configuration Form -->
        <form method="POST" action="/admin/tools/import/process">
            <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">
            <input type="hidden" name="import_token" value="<?php echo $h($token); ?>">
            <input type="hidden" name="adapter_id" value="<?php echo $h($preview['adapter_id'] ?? ''); ?>">

            <h3 style="font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                Migration Preferences &amp; Deduplication Rules
            </h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 5px;">
                        Deduplication Mode
                    </label>
                    <select name="deduplication_mode" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; background: #fff;">
                        <option value="skip" selected>Skip Existing (Do not duplicate previously imported posts/pages)</option>
                        <option value="update">Update Matching Content (Refresh existing posts with export version)</option>
                        <option value="create_new">Import All as New (Generate incremented unique slugs)</option>
                    </select>
                    <span style="font-size: 11px; color: #64748b;">Prevents duplicate records if you re-import the same archive.</span>
                </div>

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 5px;">
                        Author Assignment
                    </label>
                    <select name="author_handling" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; background: #fff;">
                        <option value="admin" selected>Assign All to Current Administrator (Safe Default)</option>
                        <option value="map_existing">Map to Existing Users (By matching email or username)</option>
                        <option value="create_author">Create Safe Author Accounts (Role: Author, never Admin)</option>
                    </select>
                    <span style="font-size: 11px; color: #64748b;">Imported content will never be granted administrative credentials.</span>
                </div>

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 5px;">
                        Publication Status
                    </label>
                    <select name="default_status" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; background: #fff;">
                        <option value="preserve" selected>Preserve Source Status (Published stays Published, Draft stays Draft)</option>
                        <option value="draft">Import All as Draft (Review manually before publishing)</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 8px;">
                        Content &amp; Media Scope
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_media" value="1" checked> Download Remote Media &amp; Rewrite Local URLs
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_posts" value="1" checked> Import Blog Posts
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_pages" value="1" checked> Import Standalone Pages
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                        <input type="checkbox" name="import_comments" value="1" checked> Import Approved Comments
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn" style="background: #059669; color: #fff; border: none; padding: 10px 22px; font-weight: 600; border-radius: 4px; cursor: pointer; font-size: 14px;">
                    🚀 Confirm &amp; Execute Import
                </button>
                <a href="/admin/tools/import" class="btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 9px 16px; text-decoration: none; border-radius: 4px; font-size: 13px;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <!-- Upload Export Archive Card -->
    <div class="form-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 8px; color: #0f172a;">
            📁 Upload Content Backup / Export File
        </h2>
        <p style="color: #64748b; font-size: 13px; margin: 0 0 18px; line-height: 1.5;">
            Upload your official export file (<code>.xml</code>, <code>.atom</code>, or <code>.json</code>). Our system automatically identifies the origin platform, analyzes contents, and presents an interactive preview before executing changes.
        </p>

        <form method="POST" action="/admin/tools/import/preview" enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 5px;">
                    Select Export File
                </label>
                <input type="file" name="import_file" accept=".xml,.atom,.json,.rss,text/xml,application/json" required style="width: 100%; font-size: 13px; padding: 6px 0;">
                <span style="font-size: 11px; color: #64748b;">Supports Blogger Atom, WordPress WXR, Generic RSS/Atom, and Universal JSON exports.</span>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 5px;">
                    Origin Source Platform
                </label>
                <select name="source_adapter" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; background: #fff;">
                    <option value="" selected>✨ Auto-Detect Source Platform (Recommended)</option>
                    <?php foreach ($adapters as $ad): ?>
                        <option value="<?php echo $h($ad->getId()); ?>"><?php echo $h($ad->getName()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 9px 18px; font-weight: 600; font-size: 13px;">
                🔍 Analyze &amp; Preview Import
            </button>
        </form>
    </div>

    <!-- Security & Architecture Information Card -->
    <div class="form-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 10px; color: #0f172a;">
            🛡️ Production Security &amp; Safety Guards
        </h2>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #334155; line-height: 1.7;">
            <li><strong>No Web Scraping:</strong> Only official structured export files are parsed; zero reliance on fragile external scraping.</li>
            <li><strong>XXE &amp; Entity Expansion Defense:</strong> XML parsing enforces <code>LIBXML_NONET</code> and rejects external entity payloads.</li>
            <li><strong>SSRF-Safe Media Downloads:</strong> Remote image requests block loopback, private RFC-1918 IPs, and AWS/Cloud metadata endpoints (<code>169.254.169.254</code>).</li>
            <li><strong>Deduplication Engine:</strong> Protects existing posts/pages with deterministic identity matching and custom overwrite controls.</li>
            <li><strong>Safe Author Mapping:</strong> Never imports source passwords, tokens, or elevated administrative privileges.</li>
        </ul>
    </div>
</div>

<!-- Platform Readiness & Audit Matrix -->
<div class="form-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 6px; color: #0f172a;">
        📊 Platform Migration Readiness Matrix
    </h2>
    <p style="color: #64748b; font-size: 13px; margin: 0 0 16px;">
        Core audit status for official export formats across major CMS systems.
    </p>

    <table class="wp-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
        <thead>
            <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; color: #475569;">
                <th style="padding: 10px;">Source Platform</th>
                <th style="padding: 10px;">Official Export Format</th>
                <th style="padding: 10px;">Core Status</th>
                <th style="padding: 10px;">Supported Content</th>
                <th style="padding: 10px;">Technical Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($platformRegistry as $id => $p): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 10px; font-weight: 600; color: #0f172a;">
                        <?php echo $h($p['name']); ?>
                    </td>
                    <td style="padding: 10px; font-family: monospace; font-size: 12px; color: #334155;">
                        <?php echo $h($p['format']); ?>
                    </td>
                    <td style="padding: 10px;">
                        <?php if ($p['status'] === 'READY'): ?>
                            <span style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 700;">
                                READY
                            </span>
                        <?php else: ?>
                            <span style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                NOT_READY
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 10px; font-size: 12px; color: #475569;">
                        <?php echo !empty($p['features']) ? $h(implode(', ', $p['features'])) : '<span style="color: #94a3b8;">&mdash;</span>'; ?>
                    </td>
                    <td style="padding: 10px; font-size: 12px; color: #64748b;">
                        <?php echo $h($p['description']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
