<?php
$h = static fn ($val): string => htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
?>
<div class="page-header">
    <h1 class="page-title">Tools, Backups &amp; System Health</h1>
</div>

<?php if (!empty($notice)): ?>
    <div style="background: var(--admin-success-bg); border-left: 4px solid var(--admin-success-border); color: var(--admin-success-text); padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-weight: 500;">
        <?php echo $h($notice); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: var(--admin-danger-bg); border-left: 4px solid var(--admin-danger-border); color: var(--admin-danger-text); padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-weight: 500;">
        <?php echo $h($error); ?>
    </div>
<?php endif; ?>

<div class="form-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <!-- Create Backup Card -->
    <div class="form-card" style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 10px; color: var(--admin-text-heading);">&#128230; Create Portable Site Backup</h2>
        <p style="color: var(--admin-text-muted); font-size: 13px; margin: 0 0 16px; line-height: 1.5;">
            Creates a self-contained, portable <code>.zip</code> package including your full database SQL dump, media uploads, active themes, and plugins.
        </p>

        <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/tools/backup/create'), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">

            <div style="margin-bottom: 14px; font-size: 13px; color: var(--admin-text);">
                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;">
                    <input type="checkbox" name="include_media" value="1" checked> Include Media Uploads (<code>public/uploads</code>)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;">
                    <input type="checkbox" name="include_themes" value="1" checked> Include Themes (<code>themes/</code>)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="include_plugins" value="1" checked> Include Plugins (<code>plugins/</code>)
                </label>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary" style="padding: 9px 18px; font-weight: 600;">
                    &#128190; Generate Full Backup (.zip)
                </button>
                <a href="<?php echo htmlspecialchars(site_path('/admin/tools/export'), ENT_QUOTES, 'UTF-8'); ?>" class="btn" style="background: var(--admin-surface-subtle); color: var(--admin-text); border: 1px solid var(--admin-border); padding: 9px 14px; text-decoration: none; border-radius: 4px; font-weight: 500; font-size: 13px;">
                    Download DB JSON
                </a>
            </div>
        </form>
    </div>

    <!-- Restore Backup Card -->
    <div class="form-card" style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
        <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 10px; color: var(--admin-text-heading);">&#128229; Restore Site from Backup</h2>
        <p style="color: var(--admin-text-muted); font-size: 13px; margin: 0 0 16px; line-height: 1.5;">
            Restore your database and files from a <code>favorite_cms_backup_*.zip</code> package. Domain references are automatically migrated.
        </p>

        <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/tools/restore'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" onsubmit="return confirm('WARNING: Restoring will overwrite existing database tables and files. Are you sure you want to proceed?');">
            <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: var(--admin-text); margin-bottom: 5px;">Upload Backup Archive (.zip)</label>
                <input type="file" name="restore_file" accept=".zip" required style="width: 100%; font-size: 13px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: var(--admin-text); margin-bottom: 5px;">Target Site URL</label>
                <input type="text" name="new_site_url" value="<?php echo $h(env('APP_URL', 'http://localhost')); ?>" style="width: 100%; padding: 7px 10px; border: 1px solid var(--admin-border); border-radius: 4px; font-size: 13px;">
                <span style="font-size: 11px; color: var(--admin-text-muted);">All database URLs and image paths will be updated to this URL.</span>
            </div>

            <button type="submit" class="btn" style="background: var(--admin-success-solid); color: #fff; border: none; padding: 9px 18px; font-weight: 600; border-radius: 4px; cursor: pointer;">
                &#9888; Restore &amp; Migrate
            </button>
        </form>
    </div>
</div>

<!-- Blogger Content Importer Card -->
<div id="blogger-import" class="form-card" style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
        <h2 style="font-size: 17px; font-weight: 700; margin: 0; color: var(--admin-text-heading);">
            &#128221; Google Blogger Content Importer
        </h2>
        <span style="font-size: 11px; background: var(--admin-purple-bg); color: var(--admin-purple-text); padding: 3px 8px; border-radius: 4px; font-weight: 600;">Atom XML v1.0</span>
    </div>

    <p style="color: var(--admin-text-muted); font-size: 13px; margin: 0 0 18px; line-height: 1.5;">
        Seamlessly migrate your blog from Google Blogger (Blogspot) into Favorite CMS. Supports Google Takeout backup ZIP archives (containing <code>feed.atom</code>), direct <code>feed.atom</code> uploads, or classic export XML (<code>blog-*.xml</code>).
    </p>

    <?php if (!empty($bloggerPreview)): ?>
        <!-- Blogger Preview State -->
        <div style="background: var(--admin-surface-subtle); border: 1px solid var(--admin-border); border-radius: 6px; padding: 16px; margin-bottom: 20px;">
            <div style="font-weight: 600; font-size: 14px; color: var(--admin-text-heading); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <span>&#9989; Blogger Backup Analysis Ready</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 16px;">
                <div style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 22px; font-weight: 700; color: var(--admin-info-text);"><?php echo (int)($bloggerPreview['counts']['posts'] ?? 0); ?></div>
                    <div style="font-size: 12px; color: var(--admin-text-muted); font-weight: 500;">Posts</div>
                </div>
                <div style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 22px; font-weight: 700; color: var(--admin-success-text);"><?php echo (int)($bloggerPreview['counts']['pages'] ?? 0); ?></div>
                    <div style="font-size: 12px; color: var(--admin-text-muted); font-weight: 500;">Pages</div>
                </div>
                <div style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 22px; font-weight: 700; color: var(--admin-warning-text);"><?php echo (int)($bloggerPreview['counts']['comments'] ?? 0); ?></div>
                    <div style="font-size: 12px; color: var(--admin-text-muted); font-weight: 500;">Comments</div>
                </div>
                <div style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 6px; padding: 12px; text-align: center;">
                    <div style="font-size: 22px; font-weight: 700; color: var(--admin-purple-text);"><?php echo (int)($bloggerPreview['counts']['tags'] ?? 0); ?></div>
                    <div style="font-size: 12px; color: var(--admin-text-muted); font-weight: 500;">Tags / Labels</div>
                </div>
            </div>

            <?php if (!empty($bloggerPreview['sample_posts'])): ?>
                <div style="margin-bottom: 16px;">
                    <div style="font-size: 12px; font-weight: 600; color: var(--admin-text-muted); margin-bottom: 6px;">Sample Posts Detected:</div>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--admin-text); line-height: 1.6;">
                        <?php foreach ($bloggerPreview['sample_posts'] as $sp): ?>
                            <li>
                                <strong><?php echo $h($sp['title'] ?: '(Untitled)'); ?></strong>
                                <span style="color: var(--admin-text-muted);">(<?php echo $h($sp['date']); ?>, <?php echo $h($sp['status']); ?>)</span>
                                <?php if (!empty($sp['tags'])): ?>
                                    <span style="color: var(--admin-info-text); font-size: 11px;">[<?php echo $h(implode(', ', $sp['tags'])); ?>]</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/tools/import/blogger'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">
                <input type="hidden" name="import_token" value="<?php echo $h($bloggerToken); ?>">

                <div style="margin-bottom: 14px; font-size: 13px; color: var(--admin-text);">
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_posts" value="1" checked> Import Posts
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_pages" value="1" checked> Import Standalone Pages
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px; cursor: pointer;">
                        <input type="checkbox" name="import_comments" value="1" checked> Import Approved Comments
                    </label>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: var(--admin-text); margin-bottom: 4px;">Status Handling</label>
                    <select name="default_status" style="padding: 6px 10px; border: 1px solid var(--admin-border); border-radius: 4px; font-size: 13px; background: var(--admin-surface);">
                        <option value="preserve">Preserve Blogger Status (Published stays Published, Draft stays Draft)</option>
                        <option value="draft">Import All as Draft (Review manually before publishing)</option>
                    </select>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn" style="background: var(--admin-success-solid); color: #fff; border: none; padding: 9px 20px; font-weight: 600; border-radius: 4px; cursor: pointer;">
                        &#128640; Run Full Import
                    </button>
                    <a href="<?php echo htmlspecialchars(site_path('/admin/tools'), ENT_QUOTES, 'UTF-8'); ?>" class="btn" style="background: var(--admin-surface-subtle); color: var(--admin-text-muted); border: 1px solid var(--admin-border); padding: 8px 14px; text-decoration: none; border-radius: 4px; font-size: 13px;">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- XML Upload & Preview Form -->
    <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/tools/import/blogger/preview'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">

        <div style="margin-bottom: 14px;">
            <label style="display: block; font-size: 12px; font-weight: 600; color: var(--admin-text); margin-bottom: 6px;">
                Select Blogger Export (Google Takeout <code>.zip</code>, <code>feed.atom</code>, or <code>blog-*.xml</code>)
            </label>
            <input type="file" name="blogger_file" accept=".zip,.xml,.atom,text/xml,application/zip" required style="font-size: 13px; padding: 6px 0;">
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 8px 16px; font-weight: 600; font-size: 13px;">
            &#128269; Analyze &amp; Preview Blogger XML
        </button>
    </form>
</div>
<div class="form-card" style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 14px; color: var(--admin-text-heading);">Existing Site Backups (<code>storage/backups/</code>)</h2>

    <?php if (empty($backups)): ?>
        <p style="color: var(--admin-text-muted); font-size: 13px; margin: 0;">No backups generated yet. Click "Generate Full Backup" above to create your first snapshot.</p>
    <?php else: ?>
        <table class="wp-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="border-bottom: 2px solid var(--admin-border); text-align: left; color: var(--admin-text-muted);">
                    <th style="padding: 10px;">Archive File</th>
                    <th style="padding: 10px;">Date Created</th>
                    <th style="padding: 10px;">Size</th>
                    <th style="padding: 10px;">CMS Version</th>
                    <th style="padding: 10px;">Tables</th>
                    <th style="padding: 10px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr style="border-bottom: 1px solid var(--admin-border);">
                        <td style="padding: 10px; font-weight: 600; font-family: monospace;">
                            <?php echo $h($b['filename']); ?>
                        </td>
                        <td style="padding: 10px; color: var(--admin-text-muted);">
                            <?php echo date('Y-m-d H:i:s', $b['date']); ?>
                        </td>
                        <td style="padding: 10px; color: var(--admin-text-muted);">
                            <?php echo round($b['size'] / 1024 / 1024, 2); ?> MB
                        </td>
                        <td style="padding: 10px;">
                            <span style="background: var(--admin-info-bg); color: var(--admin-info-text); padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                <?php echo $h($b['manifest']['cms_version'] ?? 'Universal'); ?>
                            </span>
                        </td>
                        <td style="padding: 10px; color: var(--admin-text-muted);">
                            <?php echo count($b['manifest']['tables'] ?? []); ?> tables
                        </td>
                        <td style="padding: 10px; text-align: right;">
                            <a href="<?php echo htmlspecialchars(site_path('/admin/tools/backup/download?file='), ENT_QUOTES, 'UTF-8'); ?><?php echo urlencode($b['filename']); ?>" class="btn" style="background: var(--admin-info-solid); color: #fff; padding: 4px 10px; font-size: 12px; text-decoration: none; border-radius: 3px;">
                                &#128229; Download
                            </a>
                            <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/tools/backup/delete'), ENT_QUOTES, 'UTF-8'); ?>" style="display: inline;" onsubmit="return confirm('Delete this backup archive permanently?');">
                                <input type="hidden" name="_token" value="<?php echo $h($csrfToken); ?>">
                                <input type="hidden" name="file" value="<?php echo $h($b['filename']); ?>">
                                <button type="submit" style="background: var(--admin-danger-solid); color: #fff; border: none; padding: 4px 8px; font-size: 12px; border-radius: 3px; cursor: pointer; margin-left: 4px;">
                                    &#128465;
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- System Environment Health Card -->
<div class="form-card" style="background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
    <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 10px; color: var(--admin-text-heading);">&#129302; System Environment Health</h2>
    <p style="color: var(--admin-text-muted); font-size: 13px; margin: 0 0 16px;">
        Server and PHP runtime parameters evaluated for shared hosting compatibility.
    </p>
    <table class="wp-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
        <tbody>
            <?php foreach ($diagnostics as $key => $val): ?>
                <tr style="border-bottom: 1px solid var(--admin-border);">
                    <td style="font-weight: 600; width: 220px; padding: 8px 10px; color: var(--admin-text);"><?php echo $h($key); ?></td>
                    <td style="padding: 8px 10px; color: var(--admin-text-heading);"><?php echo $h((string)$val); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
