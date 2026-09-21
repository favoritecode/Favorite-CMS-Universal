<div class="page-header">
    <h1 class="page-title">Settings</h1>
</div>

<div class="form-card" style="max-width: 760px;">
    <form method="POST" action="<?php echo htmlspecialchars(app_url('/admin/settings/update'), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
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
            <span class="description">This address is used for admin purposes and is the authoritative recovery email for the administrator.</span>
        </div>

        <div class="form-group">
            <label for="timezone">Site Time Zone</label>
            <select id="timezone" name="timezone" class="form-control" style="max-width: 420px;">
                <?php 
                $currentTimezone = $settings['timezone'] ?? 'UTC';
                if (!empty($timezones) && is_array($timezones)): 
                    foreach ($timezones as $region => $regionZones): ?>
                        <optgroup label="<?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php foreach ($regionZones as $tzIdentifier => $tzLabel): ?>
                                <option value="<?php echo htmlspecialchars($tzIdentifier, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($currentTimezone === $tzIdentifier) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tzLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; 
                else: ?>
                    <option value="UTC" <?php echo ($currentTimezone === 'UTC') ? 'selected' : ''; ?>>UTC</option>
                <?php endif; ?>
            </select>
            <span class="description">Select the standard IANA timezone for this site (e.g. UTC, Asia/Dhaka, America/New_York). Content timestamps and admin activities will display in this timezone.</span>
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
            <span class="description">The site's primary currency denomination. Changing this updates the active currency used across the site for pricing and wallet balances without altering existing numerical amounts.</span>
        </div>

        <div class="form-group">
            <label>Membership</label>
            <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer; margin-top: 4px;">
                <input type="checkbox" name="allow_registration" value="1" <?php echo !empty($settings['allow_registration']) ? 'checked' : ''; ?>>
                Anyone can register for a normal user account
            </label>
            <span class="description">Allow visitors to register accounts from /register or /signup.</span>
        </div>

        <h2 id="email-settings" style="font-size: 16px; font-weight: 600; margin: 28px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Email & Notification Settings
        </h2>

        <?php
        $diag = $settings['mail_diagnostics'] ?? [];
        $activeTransport = $settings['email_transport'] ?? 'auto';
        $smtpConfigured = !empty($diag['smtp_configured']);
        $mailAvailable = !empty($diag['mail_function_exists']) && empty($diag['mail_disabled']);
        $resolved = $diag['resolved_transport'] ?? ($smtpConfigured ? 'smtp' : 'mail');
        ?>

        <!-- Transport Status Badge -->
        <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; display: flex; flex-direction: column; gap: 8px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <span style="font-weight: 600; font-size: 13px;">Mail Delivery Status:</span>
                <?php if ($smtpConfigured): ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                        &#10003; Mail system configured (SMTP active: <?php echo htmlspecialchars((string)($diag['smtp_host'] ?? 'configured'), ENT_QUOTES, 'UTF-8'); ?>:<?php echo (int)($diag['smtp_port_configured'] ?? 587); ?>)
                    </span>
                <?php elseif ($mailAvailable): ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);">
                        &#10003; Mail system configured (PHP Mail active)
                    </span>
                <?php else: ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">
                        &#9888; PHP mail transport unavailable
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$smtpConfigured && $activeTransport !== 'mail'): ?>
                <div style="font-size: 12px; color: #f59e0b; display: flex; align-items: center; gap: 6px;">
                    <span>&#9888;</span> <span>SMTP credentials required for authenticated delivery. On shared/cloud hosting (such as Hostinger), SMTP is recommended.</span>
                </div>
            <?php endif; ?>

            <div style="font-size: 12px; color: var(--wp-text-muted, #94a3b8); line-height: 1.5; margin-top: 4px;">
                &bull; <strong>Administration Email:</strong> <code><?php echo htmlspecialchars((string)$settings['admin_email'], ENT_QUOTES, 'UTF-8'); ?></code> (Configured in General Settings; used strictly as the recovery destination).<br>
                &bull; <strong>Sender Email:</strong> Outgoing <code>FROM</code> address for verification, password resets, and notifications.
            </div>
        </div>

        <!-- Sender Fields -->
        <div class="form-group">
            <label for="sender_name">Sender Name</label>
            <input type="text" id="sender_name" name="sender_name" class="form-control" value="<?php echo htmlspecialchars($settings['sender_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($settings['site_name'] ?? 'Favorite CMS', ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">The display name appearing in the "From" header of outgoing emails. Leave blank to use the Site Title.</span>
        </div>

        <div class="form-group">
            <label for="sender_email">Sender Email Address</label>
            <input type="email" id="sender_email" name="sender_email" class="form-control" value="<?php echo htmlspecialchars($settings['sender_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars($settings['admin_email'] ?? 'admin@example.com', ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">The email address appearing in the "From" header of outgoing emails. Leave blank to use the Administration Email Address.</span>
        </div>

        <!-- Mail Transport Selection -->
        <div class="form-group" style="border-top: 1px solid var(--wp-border); padding-top: 14px; margin-top: 16px;">
            <label style="font-weight: 600; font-size: 14px; margin-bottom: 8px; display: block;">Mail Transport</label>
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="auto" <?php echo ($activeTransport === 'auto') ? 'checked' : ''; ?>>
                    <strong>Auto (Recommended)</strong> &mdash; Automatically uses SMTP if configured, otherwise falls back to PHP mail().
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="smtp" <?php echo ($activeTransport === 'smtp') ? 'checked' : ''; ?>>
                    <strong>SMTP</strong> &mdash; Exclusively route all emails through the configured SMTP server.
                </label>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="mail" <?php echo ($activeTransport === 'mail') ? 'checked' : ''; ?>>
                    <strong>PHP Mail</strong> &mdash; Exclusively use native PHP mail() / local server MTA.
                </label>
            </div>
        </div>

        <!-- SMTP Configuration Panel -->
        <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 16px; margin: 16px 0; background: var(--wp-surface, transparent);">
            <div style="font-size: 14px; font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                <span>SMTP Server Settings</span>
                <?php if (!empty($settings['smtp_password_set'])): ?>
                    <span style="font-size: 11px; font-weight: 500; color: #10b981; background: rgba(16, 185, 129, 0.12); padding: 2px 8px; border-radius: 4px;">Password Stored</span>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label for="smtp_host" style="font-size: 12px; font-weight: 600;">SMTP Host</label>
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. smtp.example.com or smtp.hostinger.com">
                </div>
                <div>
                    <label for="smtp_port" style="font-size: 12px; font-weight: 600;">SMTP Port</label>
                    <input type="number" id="smtp_port" name="smtp_port" class="form-control" value="<?php echo (int)($settings['smtp_port'] ?? 587); ?>" min="1" max="65535" placeholder="587">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label for="smtp_encryption" style="font-size: 12px; font-weight: 600;">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                        <option value="tls" <?php echo (($settings['smtp_encryption'] ?? 'tls') === 'tls') ? 'selected' : ''; ?>>STARTTLS / TLS (Port 587)</option>
                        <option value="ssl" <?php echo (($settings['smtp_encryption'] ?? 'tls') === 'ssl') ? 'selected' : ''; ?>>SSL / TLS (Port 465)</option>
                        <option value="none" <?php echo (($settings['smtp_encryption'] ?? 'tls') === 'none') ? 'selected' : ''; ?>>None (Plain TCP / Local port 25 or 1025)</option>
                    </select>
                </div>
                <div>
                    <label for="smtp_timeout" style="font-size: 12px; font-weight: 600;">Connection Timeout (seconds)</label>
                    <input type="number" id="smtp_timeout" name="smtp_timeout" class="form-control" value="<?php echo (int)($settings['smtp_timeout'] ?? 15); ?>" min="1" max="120" placeholder="15">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label for="smtp_username" style="font-size: 12px; font-weight: 600;">SMTP Username</label>
                    <input type="text" id="smtp_username" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. user@example.com" autocomplete="off">
                </div>
                <div>
                    <label for="smtp_password" style="font-size: 12px; font-weight: 600;">SMTP Password</label>
                    <input type="password" id="smtp_password" name="smtp_password" class="form-control" value="<?php echo !empty($settings['smtp_password_set']) ? '********' : ''; ?>" placeholder="<?php echo !empty($settings['smtp_password_set']) ? '********' : 'Enter password'; ?>" autocomplete="new-password">
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px;">
                <button type="submit" formmethod="POST" formaction="<?php echo htmlspecialchars(app_url('/admin/settings/test-smtp'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Test SMTP Connection</button>
                <?php if (!empty($settings['smtp_password_set'])): ?>
                    <button type="submit" name="clear_smtp_password" value="1" class="btn btn-secondary" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3);" onclick="return confirm('Clear the saved SMTP password?');">Clear SMTP Password</button>
                <?php endif; ?>
            </div>
            <span class="description" style="margin-top: 6px; display: block; font-size: 11px;">
                "Test SMTP Connection" verifies DNS, network socket connection, TLS certificate negotiation, and authentication credentials without dispatching an email.
            </span>
        </div>

        <!-- Send Test Email Panel -->
        <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 14px 16px; margin: 16px 0 24px; background: var(--wp-surface, transparent);">
            <div style="font-size: 13px; font-weight: 600; margin-bottom: 4px;">Send Test Email</div>
            <p style="font-size: 12px; color: var(--wp-text-muted, #94a3b8); margin-bottom: 10px;">Send a diagnostic test message through the currently selected transport (<?php echo strtoupper($resolved); ?>) to verify outgoing delivery.</p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <input type="email" id="test_email" name="test_email" class="form-control" style="max-width: 320px;" placeholder="recipient@example.com">
                <button type="submit" formmethod="POST" formaction="<?php echo htmlspecialchars(app_url('/admin/settings/test-email'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Send Test Email</button>
            </div>
            <span class="description" style="margin-top: 6px; display: block; font-size: 11px;">
                Note: Transport acceptance confirms the message was handed to the mail transport. Actual inbox delivery depends on recipient server policies, SPF/DKIM/DMARC records, and spam filtering.
            </span>
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
