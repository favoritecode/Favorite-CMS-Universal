<div class="page-header">
    <h1 class="page-title">Settings</h1>
</div>

<div class="form-card" style="max-width: 760px;">
    <form method="POST" action="/admin/settings/update" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            General Settings
        </h2>

        <div class="form-group">
            <label for="site_name">Site Title</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="<?php echo htmlspecialchars($settings['site_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="site_description">Tagline</label>
            <input type="text" id="site_description" name="site_description" class="form-control" value="<?php echo htmlspecialchars($settings['site_description'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">In a few words, explain what this site is about.</span>
        </div>

        <div class="form-group">
            <label for="site_url">Site Address (URL)</label>
            <input type="url" id="site_url" name="site_url" class="form-control" value="<?php echo htmlspecialchars($settings['site_url'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <!-- Site Logo (Upload or URL) -->
        <div class="form-group" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                <label style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 0;">Site Logo</label>
                <?php
                $activeLogoSource = function_exists('get_site_logo_source') ? get_site_logo_source() : ($settings['site_logo_source'] ?? 'url');
                $currentLogoUrl = function_exists('get_site_logo_url') ? get_site_logo_url() : '';
                ?>
                <span style="font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                    Active Source: <?php echo $activeLogoSource === 'upload' ? 'Uploaded File' : ($activeLogoSource === 'url' && !empty($settings['site_logo_url']) ? 'Custom URL' : 'Default / None'); ?>
                </span>
            </div>
            <span class="description" style="margin-bottom: 12px; display: block;">Displays in the header of themes and brand sections. You can upload an image file or provide an external/absolute URL.</span>

            <div style="display: flex; gap: 18px; margin-bottom: 12px; font-size: 13px;">
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="radio" name="site_logo_source" value="upload" <?php echo ($activeLogoSource === 'upload' || !empty($settings['site_logo_upload_path'])) ? 'checked' : ''; ?> onchange="document.getElementById('logo-upload-wrap').style.display='block'; document.getElementById('logo-url-wrap').style.display='none';">
                    <strong>Upload Image File</strong>
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="radio" name="site_logo_source" value="url" <?php echo ($activeLogoSource === 'url' && empty($settings['site_logo_upload_path'])) ? 'checked' : ''; ?> onchange="document.getElementById('logo-upload-wrap').style.display='none'; document.getElementById('logo-url-wrap').style.display='block';">
                    <strong>Custom Logo URL</strong>
                </label>
            </div>

            <!-- Upload Option -->
            <div id="logo-upload-wrap" style="margin-bottom: 12px; display: <?php echo ($activeLogoSource === 'upload' || !empty($settings['site_logo_upload_path'])) ? 'block' : 'none'; ?>;">
                <?php if (!empty($settings['site_logo_upload_path'])): ?>
                    <div style="margin-bottom: 8px; padding: 8px 12px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; display: flex; align-items: center; gap: 12px; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <img src="<?php echo htmlspecialchars($settings['site_logo_upload_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Uploaded Logo" style="max-height: 36px; max-width: 140px; object-fit: contain;">
                            <code style="font-size: 11px; color: #475569;"><?php echo htmlspecialchars($settings['site_logo_upload_path']); ?></code>
                        </div>
                        <label style="font-size: 11px; color: #b91c1c; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" name="remove_uploaded_logo" value="1"> Remove Upload
                        </label>
                    </div>
                <?php endif; ?>
                <label for="site_logo_file" style="font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px; display: block;">Select new logo image (PNG, JPG, SVG, WebP, ICO):</label>
                <input type="file" id="site_logo_file" name="site_logo_file" class="form-control" accept="image/*,.ico">
            </div>

            <!-- URL Option -->
            <div id="logo-url-wrap" style="margin-bottom: 12px; display: <?php echo ($activeLogoSource === 'url' && empty($settings['site_logo_upload_path'])) ? 'block' : 'none'; ?>;">
                <label for="site_logo_url" style="font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px; display: block;">Enter absolute or relative image URL:</label>
                <input type="url" id="site_logo_url" name="site_logo_url" class="form-control" value="<?php echo htmlspecialchars($settings['site_logo_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/images/logo.png or /uploads/logo.png">
            </div>

            <!-- Preview -->
            <?php if (!empty($currentLogoUrl)): ?>
                <div style="margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; display: inline-block;">
                    <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-bottom: 4px;">Active Logo Preview:</div>
                    <img src="<?php echo htmlspecialchars($currentLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Active Site Logo" style="max-height: 48px; max-width: 240px; object-fit: contain; display: block;">
                </div>
            <?php endif; ?>
        </div>

        <!-- Site Favicon (Upload or URL) -->
        <div class="form-group" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                <label style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 0;">Site Icon / Favicon</label>
                <?php
                $activeFaviconSource = function_exists('get_site_favicon_source') ? get_site_favicon_source() : ($settings['site_favicon_source'] ?? 'url');
                $currentFaviconUrl = function_exists('get_site_favicon_url') ? get_site_favicon_url() : '/favicon.ico';
                ?>
                <span style="font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                    Active Source: <?php echo $activeFaviconSource === 'upload' ? 'Uploaded File' : ($activeFaviconSource === 'url' && !empty($settings['site_favicon_url']) ? 'Custom URL' : 'Default (/favicon.ico)'); ?>
                </span>
            </div>
            <span class="description" style="margin-bottom: 12px; display: block;">Browser tab icon, bookmark icon, and mobile shortcut icon (.ico, .png, .svg).</span>

            <div style="display: flex; gap: 18px; margin-bottom: 12px; font-size: 13px;">
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="radio" name="site_favicon_source" value="upload" <?php echo ($activeFaviconSource === 'upload' || !empty($settings['site_favicon_upload_path'])) ? 'checked' : ''; ?> onchange="document.getElementById('fav-upload-wrap').style.display='block'; document.getElementById('fav-url-wrap').style.display='none';">
                    <strong>Upload Favicon File</strong>
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="radio" name="site_favicon_source" value="url" <?php echo ($activeFaviconSource === 'url' && empty($settings['site_favicon_upload_path'])) ? 'checked' : ''; ?> onchange="document.getElementById('fav-upload-wrap').style.display='none'; document.getElementById('fav-url-wrap').style.display='block';">
                    <strong>Custom Favicon URL</strong>
                </label>
            </div>

            <!-- Upload Option -->
            <div id="fav-upload-wrap" style="margin-bottom: 12px; display: <?php echo ($activeFaviconSource === 'upload' || !empty($settings['site_favicon_upload_path'])) ? 'block' : 'none'; ?>;">
                <?php if (!empty($settings['site_favicon_upload_path'])): ?>
                    <div style="margin-bottom: 8px; padding: 8px 12px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; display: flex; align-items: center; gap: 12px; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <img src="<?php echo htmlspecialchars($settings['site_favicon_upload_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Uploaded Favicon" style="width: 24px; height: 24px; object-fit: contain;">
                            <code style="font-size: 11px; color: #475569;"><?php echo htmlspecialchars($settings['site_favicon_upload_path']); ?></code>
                        </div>
                        <label style="font-size: 11px; color: #b91c1c; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                            <input type="checkbox" name="remove_uploaded_favicon" value="1"> Remove Upload
                        </label>
                    </div>
                <?php endif; ?>
                <label for="site_favicon_file" style="font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px; display: block;">Select favicon file (.ico, .png, .svg):</label>
                <input type="file" id="site_favicon_file" name="site_favicon_file" class="form-control" accept=".ico,.png,.svg,.gif,.webp,image/*">
            </div>

            <!-- URL Option -->
            <div id="fav-url-wrap" style="margin-bottom: 12px; display: <?php echo ($activeFaviconSource === 'url' && empty($settings['site_favicon_upload_path'])) ? 'block' : 'none'; ?>;">
                <label for="site_favicon_url" style="font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px; display: block;">Enter absolute or relative favicon URL:</label>
                <input type="url" id="site_favicon_url" name="site_favicon_url" class="form-control" value="<?php echo htmlspecialchars($settings['site_favicon_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/favicon.png or /favicon.ico">
            </div>

            <!-- Preview -->
            <div style="margin-top: 10px; padding: 8px 12px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; display: inline-flex; align-items: center; gap: 10px;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">Active Favicon Preview:</div>
                <img src="<?php echo htmlspecialchars($currentFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Active Favicon" style="width: 24px; height: 24px; object-fit: contain; display: block;">
            </div>
        </div>

        <div class="form-group">
            <label for="admin_email">Administration Email Address</label>
            <input type="email" id="admin_email" name="admin_email" class="form-control" value="<?php echo htmlspecialchars($settings['admin_email'], ENT_QUOTES, 'UTF-8'); ?>" required>
            <span class="description">This address is used for admin purposes.</span>
        </div>

        <div class="form-group">
            <label for="timezone">Timezone</label>
            <input type="text" id="timezone" name="timezone" class="form-control" value="<?php echo htmlspecialchars($settings['timezone'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">Choose a city in the same timezone as you (e.g. UTC, America/New_York).</span>
        </div>

        <div class="form-group">
            <label for="primary_currency">Primary Currency</label>
            <?php if (!empty($primaryCurrencyLocked)): ?>
                <input type="hidden" name="primary_currency" value="<?php echo htmlspecialchars($settings['primary_currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8'); ?>">
                <select id="primary_currency" class="form-control" style="max-width: 340px;" disabled>
                    <?php foreach (($supportedCurrencies ?? []) as $code => $info): ?>
                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($settings['primary_currency'] ?? 'BDT') === $code) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars("{$code} — {$info['name']} ({$info['symbol']})", ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description" style="color: #b32d2e; font-weight: 500; margin-top: 6px; display: block;">
                    🔒 <?php echo htmlspecialchars(!empty($primaryCurrencyLockReason) ? $primaryCurrencyLockReason : "Primary Currency cannot be changed after financial activity has started. Existing wallets, transactions, and ledger records use the current accounting currency.", ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php else: ?>
                <select id="primary_currency" name="primary_currency" class="form-control" style="max-width: 340px;">
                    <?php foreach (($supportedCurrencies ?? []) as $code => $info): ?>
                        <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($settings['primary_currency'] ?? 'BDT') === $code) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars("{$code} — {$info['name']} ({$info['symbol']})", ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">The site's authoritative accounting and base currency. Default is BDT (৳).</span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Membership</label>
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; margin-top: 4px;">
                <input type="checkbox" name="allow_registration" value="1" <?php echo !empty($settings['allow_registration']) ? 'checked' : ''; ?>>
                Anyone can register for a normal user account
            </label>
            <span class="description">Allow visitors to register accounts from /register or /signup.</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Reading Settings
        </h2>

        <div class="form-group">
            <label>Your homepage displays</label>
            <div style="margin-top: 6px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 8px; cursor: pointer;">
                    <input type="radio" name="front_page_type" value="posts" <?php echo ($settings['front_page_type'] === 'posts') ? 'checked' : ''; ?>>
                    Your latest posts
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 8px; cursor: pointer;">
                    <input type="radio" name="front_page_type" value="page" <?php echo ($settings['front_page_type'] === 'page') ? 'checked' : ''; ?>>
                    A static page:
                    <select name="front_page_id" class="form-control" style="width: auto; margin-left: 8px;">
                        <option value="0">&mdash; Select Page &mdash;</option>
                        <?php foreach ($pages as $p): ?>
                            <option value="<?php echo (int)$p->id; ?>" <?php echo ($settings['front_page_id'] == $p->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label for="posts_per_page">Blog pages show at most</label>
            <input type="number" id="posts_per_page" name="posts_per_page" class="form-control" style="width: 100px;" value="<?php echo (int)$settings['posts_per_page']; ?>" min="1" max="100">
            <span class="description">Number of posts to display per page.</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Writing Settings
        </h2>

        <div class="form-group">
            <label for="default_category">Default Post Category</label>
            <select id="default_category" name="default_category" class="form-control" style="max-width: 250px;">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int)$cat->id; ?>" <?php echo ($settings['default_category'] == $cat->id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Media & Upload Capabilities
        </h2>

        <div style="background: #f8fafc; border: 1px solid var(--wp-border); border-radius: 6px; padding: 14px; margin-bottom: 16px;">
            <div style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 8px;">
                Detected PHP / Server Limits
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; font-size: 13px;">
                <div>
                    <span style="color: var(--wp-text-muted);">upload_max_filesize:</span><br>
                    <strong><?php echo htmlspecialchars($serverLimits['upload_max_filesize_raw']); ?></strong>
                </div>
                <div>
                    <span style="color: var(--wp-text-muted);">post_max_size:</span><br>
                    <strong><?php echo htmlspecialchars($serverLimits['post_max_size_raw']); ?></strong>
                </div>
                <div>
                    <span style="color: var(--wp-text-muted);">memory_limit:</span><br>
                    <strong><?php echo htmlspecialchars($serverLimits['memory_limit_raw']); ?></strong>
                </div>
                <div>
                    <span style="color: var(--wp-text-muted);">Effective Server Cap:</span><br>
                    <strong style="color: #0284c7;"><?php echo htmlspecialchars($serverLimits['effective_server_formatted']); ?></strong>
                </div>
            </div>
            <div style="margin-top: 10px; font-size: 11px; color: var(--wp-text-muted);">
                The effective server upload limit is determined by the lower of <code>upload_max_filesize</code> and <code>post_max_size</code>. The CMS automatically respects this bottleneck.
            </div>
        </div>

        <div class="form-group">
            <label for="max_upload_size_admin_mb">Administrator Upload Allowance (MB)</label>
            <?php
            $adminBytes = (int)($settings['max_upload_size_admin'] ?? 7516192768);
            $adminMb = round($adminBytes / (1024 * 1024), 1);
            ?>
            <input type="number" id="max_upload_size_admin_mb" name="max_upload_size_admin_mb" class="form-control" style="width: 140px;" value="<?php echo $adminMb; ?>" min="1" step="any">
            <span class="description">Configured CMS allowance for administrators (default 7 GB / 7,168 MB). Effective server cap: <strong><?php echo htmlspecialchars($serverLimits['effective_server_formatted']); ?></strong>.</span>
        </div>

        <div class="form-group">
            <label for="max_upload_size_moderator_mb">Moderator / Editor Upload Allowance (MB)</label>
            <?php
            $modBytes = (int)($settings['max_upload_size_moderator'] ?? 524288000);
            $modMb = round($modBytes / (1024 * 1024), 1);
            ?>
            <input type="number" id="max_upload_size_moderator_mb" name="max_upload_size_moderator_mb" class="form-control" style="width: 140px;" value="<?php echo $modMb; ?>" min="1" step="any">
            <span class="description">Configured CMS allowance for moderators and editors (default 500 MB). Strictly capped by server limits.</span>
        </div>

        <div class="form-group">
            <label for="max_upload_size_user_mb">Standard User Upload Limit (MB)</label>
            <?php
            $userBytes = (int)($settings['max_upload_size_user'] ?? 209715200);
            $userMb = round($userBytes / (1024 * 1024), 1);
            ?>
            <input type="number" id="max_upload_size_user_mb" name="max_upload_size_user_mb" class="form-control" style="width: 140px;" value="<?php echo $userMb; ?>" min="1" step="any">
            <span class="description">Maximum file size permitted for normal non-administrator users (default 200 MB, strictly capped by server capability).</span>
        </div>

        <div style="margin-top: 24px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>
