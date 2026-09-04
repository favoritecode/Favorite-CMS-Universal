<div class="page-header">
    <h1 class="page-title">Settings</h1>
</div>

<div class="form-card" style="max-width: 760px;">
    <form method="POST" action="/admin/settings/update">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

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
            <select id="primary_currency" name="primary_currency" class="form-control" style="max-width: 340px;">
                <?php foreach (($supportedCurrencies ?? []) as $code => $info): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($settings['primary_currency'] ?? 'BDT') === $code) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars("{$code} — {$info['name']} ({$info['symbol']})", ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">The site's authoritative accounting and base currency. Default is BDT (৳).</span>
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
