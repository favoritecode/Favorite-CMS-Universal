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
        $googleConnected = !empty($settings['google_connected']) || !empty($diag['google_connected']);
        $smtpConfigured = !empty($diag['smtp_configured']);
        $mailAvailable = !empty($diag['mail_function_exists']) && empty($diag['mail_disabled']);
        $resolved = $diag['resolved_transport'] ?? ($googleConnected ? 'google' : ($smtpConfigured ? 'smtp' : 'mail'));
        ?>

        <!-- Transport Status Badge -->
        <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; display: flex; flex-direction: column; gap: 8px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                <span style="font-weight: 600; font-size: 13px;">Mail Delivery Status:</span>
                <?php if ($resolved === 'google' && $googleConnected): ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                        &#10003; Google Mail Active (<?php echo htmlspecialchars((string)($settings['google_account_email'] ?? $diag['google_account_email'] ?? 'connected'), ENT_QUOTES, 'UTF-8'); ?>)
                    </span>
                <?php elseif ($resolved === 'smtp' && $smtpConfigured): ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                        &#10003; SMTP Active (<?php echo htmlspecialchars((string)($diag['smtp_host'] ?? 'configured'), ENT_QUOTES, 'UTF-8'); ?>:<?php echo (int)($diag['smtp_port_configured'] ?? 587); ?>)
                    </span>
                <?php elseif ($mailAvailable): ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3);">
                        &#10003; PHP Mail Active (Native MTA)
                    </span>
                <?php else: ?>
                    <span style="font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 9999px; background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">
                        &#9888; Outgoing mail transport unavailable
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$googleConnected && !$smtpConfigured && $activeTransport !== 'mail'): ?>
                <div style="font-size: 12px; color: #f59e0b; display: flex; align-items: center; gap: 6px;">
                    <span>&#9888;</span> <span>Connect with Google or configure SMTP for authenticated delivery on production hosting.</span>
                </div>
            <?php endif; ?>

            <?php if (!empty($diag['google_send_as_advisory'])): ?>
                <div style="font-size: 12px; color: #3b82f6; background: rgba(59, 130, 246, 0.08); border-left: 3px solid #3b82f6; padding: 8px 10px; border-radius: 4px; margin-top: 4px;">
                    <strong>Google Send-As Notice:</strong> <?php echo htmlspecialchars((string)$diag['google_send_as_advisory'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div style="font-size: 12px; color: var(--wp-text-muted, #94a3b8); line-height: 1.5; margin-top: 4px;">
                &bull; <strong>Administration Email:</strong> <code><?php echo htmlspecialchars((string)$settings['admin_email'], ENT_QUOTES, 'UTF-8'); ?></code> (Configured in General Settings; used strictly as the recovery destination).<br>
                &bull; <strong>Sender Email:</strong> Outgoing <code>FROM</code> address for verification, password resets, and notifications.
            </div>
        </div>

        <!-- Built-in Mail Setup Guide (Collapsible) -->
        <details class="admin-help-accordion" style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; background: var(--wp-surface-subtle, rgba(255, 255, 255, 0.03));">
            <summary style="font-size: 13px; font-weight: 600; cursor: pointer; color: var(--wp-link, #3b82f6); display: flex; align-items: center; justify-content: space-between; user-select: none;">
                <span style="display: flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <strong>Mail Setup Guide &mdash; How to configure email delivery</strong>
                </span>
                <span style="font-size: 11px; font-weight: normal; color: var(--wp-text-muted, #94a3b8);">Expand / Collapse Guide &darr;</span>
            </summary>

            <div style="margin-top: 16px; font-size: 13px; line-height: 1.6; color: var(--wp-text, #334155); display: flex; flex-direction: column; gap: 16px; overflow-wrap: break-word;">

                <!-- Method Overview & Recommendations -->
                <div style="background: var(--wp-surface, rgba(0,0,0,0.02)); border: 1px solid var(--wp-border); border-radius: 6px; padding: 12px 14px;">
                    <div style="font-weight: 600; margin-bottom: 6px; color: var(--wp-text-heading, #0f172a);">Supported Email Delivery Methods</div>
                    <p style="margin: 0 0 8px 0;">Favorite CMS Universal provides four distinct, provider-independent delivery modes to accommodate any hosting environment or email service:</p>
                    <ol style="margin: 0; padding-left: 20px;">
                        <li><strong>Auto Detect:</strong> Automatically uses the best active transport in strict hierarchical priority.</li>
                        <li><strong>Continue with Google:</strong> Authenticated Gmail OAuth 2.0/XOAUTH2 delivery without passwords or insecure app passwords.</li>
                        <li><strong>Manual SMTP:</strong> Standard authenticated outgoing mail through any provider (cPanel webmail, dedicated VPS, transactional relays).</li>
                        <li><strong>PHP Mail:</strong> Native server MTA via PHP <code>mail()</code> function for local development or basic hosting.</li>
                    </ol>
                    <div style="margin-top: 8px; font-size: 12px; color: #10b981; font-weight: 500;">
                        &#10003; <em>Recommended:</em> <strong>Auto Detect</strong> is recommended for most installations when Google Mail or SMTP has been configured.
                    </div>
                </div>

                <!-- 1. Auto Detect Instructions -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">1. Auto Detect Transport Mode</h4>
                    <p style="margin: 0 0 6px;">Auto Detect automatically chooses an available configured mail transport according to this strict priority order:</p>
                    <div style="background: rgba(0,0,0,0.05); padding: 8px 12px; border-radius: 4px; font-family: monospace; font-size: 12px; margin-bottom: 8px;">
                        Google Mail connected &rarr; Google / XOAUTH2<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;&darr; (otherwise)<br>
                        Manual SMTP configured &rarr; SMTP<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;&darr; (otherwise)<br>
                        PHP Mail &rarr; native server mail transport
                    </div>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li>No duplicate email is ever sent; only the highest-priority functional transport is dispatched.</li>
                        <li>Auto Detect does not magically discover private SMTP passwords &mdash; you must configure your credentials.</li>
                        <li>If Google is not connected and SMTP is not configured, CMS may use PHP Mail if the hosting server supports it.</li>
                        <li>If no transport is available or configured, CMS reports a clear configuration error.</li>
                    </ul>
                </div>

                <!-- 2. Continue with Google -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">2. Continue with Google (OAuth 2.0 / XOAUTH2)</h4>
                    <p style="margin: 0 0 6px;">Continue with Google allows Favorite CMS to send email through an authorized Google account using OAuth 2.0/XOAUTH2:</p>
                    <ol style="margin: 0 0 8px; padding-left: 20px; font-size: 12px;">
                        <li>Click the <strong>[ Continue with Google ]</strong> button.</li>
                        <li>Sign in to your Google account in the secure authorization window.</li>
                        <li>Approve the requested Gmail send permissions.</li>
                        <li>Return to Favorite CMS automatically &mdash; Google Mail becomes connected.</li>
                        <li>CMS stores only the protected credential material required for future sending, encrypted with AES-256-GCM.</li>
                        <li>Short-lived access tokens are refreshed over backchannel and kept in memory only.</li>
                    </ol>
                    <div style="background: rgba(59, 130, 246, 0.08); border-left: 3px solid #3b82f6; padding: 8px 10px; border-radius: 4px; font-size: 12px;">
                        <strong>Gateway Note:</strong> Normal users do NOT need to enter a Google Client ID or Google Client Secret when a Google OAuth Gateway is configured. Universal Google OAuth requires a configured Google OAuth Gateway (e.g. <code style="word-break: break-all;">https://oauth.example.com</code>, configured under Advanced settings) to be reachable. If a Gateway is not configured or offline, administrators can configure Manual SMTP or utilize Custom GCP credentials.
                    </div>
                </div>

                <!-- 3. Custom GCP Mode -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">3. Custom GCP / Advanced Google (Optional Alternative)</h4>
                    <p style="margin: 0 0 6px; font-size: 12px;">For organizations and developers wishing to operate their own Google Cloud OAuth application instead of the central gateway:</p>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li>Requires an active project in Google Cloud Console with the Gmail API enabled.</li>
                        <li>Configure an OAuth consent screen and create an OAuth 2.0 Web Application credential.</li>
                        <li>Set the <strong>Authorized redirect URI</strong> to match the CMS-generated callback URL shown under Advanced settings.</li>
                        <li>Enter your Google Client ID and Google Client Secret into the protected CMS settings fields.</li>
                        <li>Credentials must never be shared publicly; they are safely stored encrypted in your local CMS database.</li>
                    </ul>
                </div>

                <!-- 4. Manual SMTP Setup -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">4. Manual SMTP Setup</h4>
                    <p style="margin: 0 0 6px;">Use Manual SMTP when your email provider gives you dedicated outgoing SMTP server credentials:</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; margin: 8px 0;">
                        <div style="background: var(--wp-surface, rgba(0,0,0,0.03)); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--wp-border);">
                            <strong>SMTP Host</strong><br>
                            <span style="font-size: 11px; color: var(--wp-text-muted, #94a3b8);">The hostname of your email provider's outgoing SMTP server (e.g. <code>smtp.example.com</code> or <code>smtp-relay.example.com</code>).</span>
                        </div>
                        <div style="background: var(--wp-surface, rgba(0,0,0,0.03)); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--wp-border);">
                            <strong>SMTP Port</strong><br>
                            <span style="font-size: 11px; color: var(--wp-text-muted, #94a3b8);">Use the port specified by your email provider:<br>
                            &bull; <code>587</code> = STARTTLS / recommended submission port.<br>
                            &bull; <code>465</code> = Implicit SSL/TLS.<br>
                            &bull; <code>25</code> = Traditional SMTP (often restricted by hosting).<br>
                            &bull; <code>1025</code> = Alternative submission port when supported.</span>
                        </div>
                        <div style="background: var(--wp-surface, rgba(0,0,0,0.03)); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--wp-border);">
                            <strong>Encryption</strong><br>
                            <span style="font-size: 11px; color: var(--wp-text-muted, #94a3b8);">
                            &bull; <strong>STARTTLS / TLS:</strong> Connection starts normally and upgrades to TLS.<br>
                            &bull; <strong>SSL:</strong> Encrypted connection starts immediately.<br>
                            &bull; <strong>None:</strong> Unencrypted. Use only if provider explicitly requires it and network is trusted.</span>
                        </div>
                        <div style="background: var(--wp-surface, rgba(0,0,0,0.03)); padding: 8px 10px; border-radius: 4px; border: 1px solid var(--wp-border);">
                            <strong>Username &amp; Password</strong><br>
                            <span style="font-size: 11px; color: var(--wp-text-muted, #94a3b8);">Username is typically your full email address. Password is your SMTP password or dedicated API key (some providers require an API key instead of your account password).</span>
                        </div>
                    </div>

                    <!-- Generic Examples -->
                    <div style="margin-top: 10px;">
                        <strong>Generic SMTP Examples:</strong>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-top: 6px; font-size: 11px;">
                            <div style="padding: 6px 8px; background: rgba(0,0,0,0.04); border-radius: 4px;">
                                <strong>Example A &mdash; STARTTLS:</strong><br>
                                Host: <code>smtp.example.com</code><br>
                                Port: <code>587</code><br>
                                Encryption: <code>STARTTLS / TLS</code><br>
                                Username: <code>support@example.com</code>
                            </div>
                            <div style="padding: 6px 8px; background: rgba(0,0,0,0.04); border-radius: 4px;">
                                <strong>Example B &mdash; SSL:</strong><br>
                                Host: <code>smtp.example.com</code><br>
                                Port: <code>465</code><br>
                                Encryption: <code>SSL</code><br>
                                Username: <code>support@example.com</code>
                            </div>
                            <div style="padding: 6px 8px; background: rgba(0,0,0,0.04); border-radius: 4px;">
                                <strong>Example C &mdash; Port 25:</strong><br>
                                Host: <code>smtp.example.com</code><br>
                                Port: <code>25</code><br>
                                Encryption: <code>provider-defined</code><br>
                                <em>Note: Port 25 may be blocked by shared hosting firewalls.</em>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Sender vs Administration Email -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">5. Sender Email vs. Administration Email</h4>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li><strong>Administration Email Address:</strong> The authoritative administrator/recovery destination (e.g. <code>admin@example.com</code>). Used strictly for receiving system alerts, database recovery, and password reset requests.</li>
                        <li><strong>Sender Email Address:</strong> The address recipients will normally see in the <code>From</code> header (e.g. <code>support@example.com</code>).</li>
                        <li>These are NOT necessarily the same address. The sender address should normally be an address or domain authorized by your mail provider (merely typing an address does not make it authorized).</li>
                    </ul>
                </div>

                <!-- 6. PHP Mail -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">6. PHP Mail (Native MTA)</h4>
                    <p style="margin: 0 0 6px; font-size: 12px;">PHP Mail uses the hosting server's native PHP <code>mail()</code> function and local MTA (e.g. Sendmail / Postfix):</p>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li>Requires server-side mail support; many shared hosting providers disable or severely restrict native <code>mail()</code>.</li>
                        <li>Successful PHP <code>mail()</code> acceptance does not guarantee inbox delivery.</li>
                        <li>SPF, DKIM, and DMARC domain alignment significantly affect delivery rates.</li>
                        <li>If PHP Mail fails or messages land in spam, <strong>Manual SMTP</strong> or <strong>Google Mail</strong> is the appropriate solution.</li>
                    </ul>
                </div>

                <!-- 7. Testing Tools -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">7. Diagnostic Testing Tools</h4>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li><strong>Test SMTP Connection:</strong> Checks whether Favorite CMS can connect to the configured SMTP server and complete the required TLS/authentication steps without sending an email. <em>A successful connection test does not guarantee inbox delivery.</em></li>
                        <li><strong>Send Test Email:</strong> Sends an actual test message through the currently selected mail transport. Enter a recipient email, click Send Test Email, and check your inbox and Spam/Junk folder. <em>Distinguish server transport acceptance from inbox delivery.</em></li>
                    </ul>
                </div>

                <!-- 8. Compact Troubleshooting Guide -->
                <div style="background: rgba(0,0,0,0.03); border: 1px solid var(--wp-border); border-radius: 6px; padding: 12px 14px;">
                    <div style="font-weight: 700; margin-bottom: 8px; color: var(--wp-text-heading, #0f172a); font-size: 13px;">Troubleshooting Guide</div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 12px;">
                        <div>
                            <strong>Case 1 &mdash; "SMTP connection failed":</strong><br>
                            <span style="color: var(--wp-text-muted, #94a3b8);">Possible causes: incorrect host, wrong port, mismatched encryption (TLS vs SSL), invalid username/password or API key, host firewall blocking port 25/587, or TLS certificate validation failure.<br>
                            <em>Action:</em> Verify SMTP host, port, and credentials with your provider and click "Test SMTP Connection" again.</span>
                        </div>
                        <div>
                            <strong>Case 2 &mdash; "SMTP connection succeeds but email does not arrive":</strong><br>
                            <span style="color: var(--wp-text-muted, #94a3b8);">Possible causes: recipient spam/junk filtering, missing SPF/DKIM/DMARC DNS records, sender email unauthorized on SMTP account, or recipient mail server rejection.<br>
                            <em>Action:</em> Check recipient Spam/Junk folder, review provider delivery logs, and ensure sender domain SPF records authorize your sending server.</span>
                        </div>
                        <div>
                            <strong>Case 3 &mdash; "PHP Mail failed":</strong><br>
                            <span style="color: var(--wp-text-muted, #94a3b8);">Possible causes: hosting server lacks local sendmail MTA or has PHP <code>mail()</code> disabled.<br>
                            <em>Action:</em> Switch to Manual SMTP or Google Mail.</span>
                        </div>
                        <div>
                            <strong>Case 4 &mdash; "Continue with Google failed":</strong><br>
                            <span style="color: var(--wp-text-muted, #94a3b8);">Possible causes: OAuth Gateway offline or unreachable, gateway not configured, user denied consent, or callback URL mismatch.<br>
                            <em>Action:</em> Configure a valid Gateway URL under Advanced settings, or utilize Custom GCP / Manual SMTP.</span>
                        </div>
                        <div>
                            <strong>Case 5 &mdash; "Auto Detect failed":</strong><br>
                            <span style="color: var(--wp-text-muted, #94a3b8);">The hierarchy (Google &rarr; SMTP &rarr; PHP Mail) found no functional transport.<br>
                            <em>Action:</em> Connect Google Mail or enter valid SMTP server credentials.</span>
                        </div>
                    </div>
                </div>

                <!-- 9. DNS & Domain Independence -->
                <div>
                    <h4 style="font-size: 13px; font-weight: 700; margin: 0 0 6px; color: var(--wp-text-heading, #0f172a);">8. DNS &amp; Domain Independence</h4>
                    <p style="margin: 0 0 6px; font-size: 12px;"><strong>Email delivery is completely independent from your website's A records:</strong></p>
                    <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: var(--wp-text-muted, #94a3b8);">
                        <li><strong>Website A/AAAA/CNAME records:</strong> Determine web server traffic routing. They do NOT dictate outgoing mail servers.</li>
                        <li><strong>SMTP:</strong> Directly determines outgoing email delivery through whatever host you configure.</li>
                        <li><strong>MX records:</strong> Primarily handle incoming email destined for your domain.</li>
                        <li><strong>SPF/DKIM/DMARC:</strong> Authenticate that the sending server is authorized by the domain owner.</li>
                        <li>Favorite CMS does NOT require Blogger IP addresses, old website A records, Cloudflare Email Routing, or any specific hosting provider. For exact requirements, consult your email provider's official SMTP documentation.</li>
                    </ul>
                </div>

                <!-- 10. Security Advisory -->
                <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 4px; padding: 8px 12px; font-size: 12px;">
                    <strong style="color: #ef4444;">Security Advisory:</strong> Never share your SMTP password, SMTP API key, Google OAuth secret, installation secret, or refresh token. Use SMTP API keys with restricted permissions where providers recommend them, enable TLS/SSL encryption, and never paste sensitive credentials in public posts, pages, URLs, or support forums.
                </div>

            </div>
        </details>

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
            <label style="font-weight: 600; font-size: 14px; margin-bottom: 8px; display: block;">Mail Transport Mode</label>
            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="auto" <?php echo ($activeTransport === 'auto') ? 'checked' : ''; ?> style="margin-top: 3px;">
                    <div>
                        <strong>Auto Detect (Recommended)</strong> &mdash; Automatically selects Google Mail if connected, otherwise Manual SMTP, falling back to PHP mail().
                    </div>
                </label>
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="google" <?php echo ($activeTransport === 'google') ? 'checked' : ''; ?> style="margin-top: 3px;">
                    <div>
                        <strong>Continue with Google</strong> &mdash; Authenticated Gmail delivery via OAuth 2.0 (no passwords or insecure app passwords needed).
                    </div>
                </label>
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="smtp" <?php echo ($activeTransport === 'smtp') ? 'checked' : ''; ?> style="margin-top: 3px;">
                    <div>
                        <strong>Manual SMTP</strong> &mdash; Exclusively route outgoing mail through your SMTP server (Hostinger, cPanel, VPS, SendGrid, etc.).
                    </div>
                </label>
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                    <input type="radio" name="email_transport" value="mail" <?php echo ($activeTransport === 'mail') ? 'checked' : ''; ?> style="margin-top: 3px;">
                    <div>
                        <strong>PHP Mail</strong> &mdash; Exclusively use native server PHP <code>mail()</code> and local sendmail MTA.
                    </div>
                </label>
            </div>
        </div>

        <!-- Google Mail (OAuth 2.0) Panel -->
        <div style="border: 1px solid var(--wp-border); border-radius: 6px; padding: 16px; margin: 16px 0; background: var(--wp-surface, transparent);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"/>
                        <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 9.98 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/>
                        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                    </svg>
                    <span style="font-size: 14px; font-weight: 600;">Google Mail</span>
                </div>
                <?php if ($googleConnected): ?>
                    <span style="font-size: 11px; font-weight: 600; color: #10b981; background: rgba(16, 185, 129, 0.12); padding: 3px 10px; border-radius: 4px; border: 1px solid rgba(16, 185, 129, 0.25);">
                        &#10003; Connected: <?php echo htmlspecialchars((string)($settings['google_account_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php else: ?>
                    <span style="font-size: 11px; font-weight: 500; color: var(--wp-text-muted, #94a3b8); background: var(--wp-surface-subtle, rgba(255, 255, 255, 0.05)); padding: 3px 8px; border-radius: 4px;">
                        Not Connected
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$googleConnected): ?>
                <p style="font-size: 13px; color: var(--wp-text, #334155); margin-bottom: 14px; line-height: 1.5;">
                    Connect your Google account to send CMS emails. No Google Cloud Console, Client ID, or Client Secret required.
                </p>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="submit" formmethod="POST" formaction="<?php echo htmlspecialchars(app_url('/admin/settings/google-auth'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 8px 18px;">
                        <svg width="18" height="18" viewBox="0 0 24 24">
                            <path fill="#fff" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                            <path fill="#fff" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"/>
                            <path fill="#fff" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 9.98 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/>
                            <path fill="#fff" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                        </svg>
                        Continue with Google
                    </button>
                </div>
            <?php else: ?>
                <p style="font-size: 12px; color: var(--wp-text-muted, #94a3b8); margin-bottom: 12px; line-height: 1.5;">
                    Your Google account is securely connected. Outgoing CMS emails will be dispatched through Gmail's authenticated infrastructure via XOAUTH2.
                </p>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
                    <button type="submit" formmethod="POST" formaction="<?php echo htmlspecialchars(app_url('/admin/settings/test-google'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary">Test Google Connection</button>
                    <button type="submit" formmethod="POST" formaction="<?php echo htmlspecialchars(app_url('/admin/settings/google-disconnect'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3);" onclick="return confirm('Disconnect Google Mail integration?');">Disconnect Google</button>
                </div>

                <?php 
                $resolvedSender = \FavoriteCMS\Services\MailService::resolveFromEmail();
                $connectedGoogleEmail = (string)($settings['google_account_email'] ?? '');
                if ($resolvedSender !== '' && $connectedGoogleEmail !== '' && strcasecmp($resolvedSender, $connectedGoogleEmail) !== 0): 
                ?>
                    <div style="font-size: 12px; color: #f59e0b; background: rgba(245, 158, 11, 0.08); border-left: 3px solid #f59e0b; padding: 8px 10px; border-radius: 4px; margin-top: 8px;">
                        <strong>Sender Advisory:</strong> The configured sender address (<code><?php echo htmlspecialchars($resolvedSender, ENT_QUOTES, 'UTF-8'); ?></code>) differs from the connected Google account (<code><?php echo htmlspecialchars($connectedGoogleEmail, ENT_QUOTES, 'UTF-8'); ?></code>). Gmail may require this address to be configured as a verified Send As identity.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Advanced / Custom GCP Accordion -->
            <details style="margin-top: 16px; border-top: 1px dashed var(--wp-border); padding-top: 12px;">
                <summary style="font-size: 12px; font-weight: 600; color: var(--wp-text-muted, #94a3b8); cursor: pointer;">
                    Advanced: Custom Google Cloud Project Credentials (Optional)
                </summary>
                <p style="font-size: 11px; color: var(--wp-text-muted, #94a3b8); margin: 8px 0 12px 0;">
                    Leave blank to use the Universal 1-Click Gateway. Self-hosters and developers may optionally provide their own Google Cloud Console OAuth credentials below.
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label for="google_client_id" style="font-size: 12px; font-weight: 600;">Google Client ID</label>
                        <input type="text" id="google_client_id" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['google_client_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 123456789-abc.apps.googleusercontent.com" autocomplete="off">
                    </div>
                    <div>
                        <label for="google_client_secret" style="font-size: 12px; font-weight: 600;">Google Client Secret</label>
                        <input type="password" id="google_client_secret" name="google_client_secret" class="form-control" value="<?php echo !empty($settings['google_client_secret_set']) ? '********' : ''; ?>" placeholder="<?php echo !empty($settings['google_client_secret_set']) ? '********' : 'Enter Client Secret'; ?>" autocomplete="new-password">
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label for="google_gateway_url" style="font-size: 12px; font-weight: 600;">Gateway URL (Optional)</label>
                    <span class="description" style="display: block; font-size: 11px; margin-bottom: 4px;">
                        Enter the Google OAuth Gateway URL used by this installation. Leave blank if using Custom Google Cloud credentials below.
                    </span>
                    <input type="text" id="google_gateway_url" name="google_gateway_url" class="form-control" value="<?php echo htmlspecialchars($settings['google_gateway_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. https://oauth.example.com" autocomplete="off">
                    <span class="description" style="margin-top: 4px; display: block; font-size: 11px;">
                        <?php if (!empty($settings['google_active_gateway_url'])): ?>
                            Active Gateway: <code><?php echo htmlspecialchars((string)$settings['google_active_gateway_url'], ENT_QUOTES, 'UTF-8'); ?></code> <span style="color: #10b981; font-weight: 600;">(Custom Gateway)</span>
                        <?php else: ?>
                            <span style="color: var(--wp-text-muted, #94a3b8);">Google OAuth Gateway is not configured.</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Authorized Redirect URI -->
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Authorized Redirect URI</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="google_redirect_uri_display" readonly class="form-control" style="font-family: monospace; font-size: 12px; background: rgba(0,0,0,0.05);" value="<?php echo htmlspecialchars((string)($settings['google_redirect_uri'] ?? \FavoriteCMS\Services\Mail\GoogleOAuthService::getRedirectUri()), ENT_QUOTES, 'UTF-8'); ?>" onclick="this.select();">
                        <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('google_redirect_uri_display').value); alert('Redirect URI copied to clipboard!');">Copy</button>
                    </div>
                    <span class="description" style="margin-top: 4px; display: block; font-size: 11px;">
                        Copy this exact URI into your Google Cloud Console project under <em>APIs & Services &gt; Credentials &gt; OAuth 2.0 Client IDs &gt; Authorized redirect URIs</em> if using Custom GCP credentials.
                    </span>
                </div>

                <?php if (!empty($settings['google_client_secret_set'])): ?>
                    <button type="submit" name="clear_google_secret" value="1" class="btn btn-secondary" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3); font-size: 11px;" onclick="return confirm('Clear the saved Google Client Secret?');">Clear Saved Client Secret</button>
                <?php endif; ?>
            </details>
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
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. smtp.example.com or smtp-relay.example.com">
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
