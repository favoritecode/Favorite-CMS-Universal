<div class="page-header">
    <h1 class="page-title">Search Engine Optimization (SEO)</h1>
</div>

<div class="form-card" style="max-width: 700px;">
    <form method="POST" action="/admin/seo/update">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            General SEO Defaults
        </h2>

        <div class="form-group">
            <label for="separator">Title Separator</label>
            <input type="text" id="separator" name="separator" class="form-control" style="width: 80px;" value="<?php echo htmlspecialchars($seo['separator'], ENT_QUOTES, 'UTF-8'); ?>" required>
            <span class="description">Symbol separating the page title and site name in browser tabs (e.g. <code>—</code>, <code>|</code>, <code>&bull;</code>).</span>
        </div>

        <div class="form-group">
            <label for="meta_description">Global Meta Description</label>
            <textarea id="meta_description" name="meta_description" class="form-control" rows="3"><?php echo htmlspecialchars($seo['meta_description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <span class="description">Fallback meta description used when a page/post does not define its own.</span>
        </div>

        <div class="form-group">
            <label for="og_image">Default Social / Open Graph Image URL</label>
            <input type="url" id="og_image" name="og_image" class="form-control" value="<?php echo htmlspecialchars($seo['og_image'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/logo.jpg">
            <span class="description">Image displayed when links from your site are shared on social media.</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Search Engine Verification
        </h2>

        <div class="form-group">
            <label for="google_site_verification">Google Search Console Verification Code</label>
            <input type="text" id="google_site_verification" name="google_site_verification" class="form-control" value="<?php echo htmlspecialchars($seo['google_site_verification'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. dB4xyz123abc456...">
            <span class="description">Provides ownership verification via <code>&lt;meta name="google-site-verification" content="..."&gt;</code> on the frontend.</span>
        </div>

        <div class="form-group">
            <label for="bing_site_verification">Bing Webmaster Tools Verification Code</label>
            <input type="text" id="bing_site_verification" name="bing_site_verification" class="form-control" value="<?php echo htmlspecialchars($seo['bing_site_verification'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 7D8ABC1234567890...">
            <span class="description">Provides ownership verification via <code>&lt;meta name="msvalidate.01" content="..."&gt;</code> on the frontend.</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Analytics & Tag Management
        </h2>

        <div style="background: #f8fafc; border: 1px solid var(--wp-border); border-radius: 4px; padding: 12px; margin-bottom: 16px; font-size: 13px; color: #475569;">
            Tracking scripts are only injected into public visitor pages and are strictly excluded from the admin dashboard (<code>/admin/*</code>). If Google Tag Manager is enabled, standalone GA4 tracking is automatically deduplicated to prevent double counting.
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                <input type="checkbox" name="ga4_enabled" value="1" <?php echo !empty($seo['ga4_enabled']) ? 'checked' : ''; ?>>
                Enable Google Analytics 4 (GA4)
            </label>
            <div style="margin-top: 8px;">
                <label for="ga4_measurement_id" style="font-size: 12px;">GA4 Measurement ID</label>
                <input type="text" id="ga4_measurement_id" name="ga4_measurement_id" class="form-control" style="max-width: 250px;" value="<?php echo htmlspecialchars($seo['ga4_measurement_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="G-XXXXXXXXXX">
                <span class="description">Format: <code>G-XXXXXXXXXX</code> (alphanumeric).</span>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
            <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                <input type="checkbox" name="gtm_enabled" value="1" <?php echo !empty($seo['gtm_enabled']) ? 'checked' : ''; ?>>
                Enable Google Tag Manager (GTM)
            </label>
            <div style="margin-top: 8px;">
                <label for="gtm_container_id" style="font-size: 12px;">GTM Container ID</label>
                <input type="text" id="gtm_container_id" name="gtm_container_id" class="form-control" style="max-width: 250px;" value="<?php echo htmlspecialchars($seo['gtm_container_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="GTM-XXXXXXX">
                <span class="description">Format: <code>GTM-XXXXXXX</code>. Injects script into <code>&lt;head&gt;</code> and noscript iframe into <code>&lt;body&gt;</code>.</span>
            </div>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Robots.txt & XML Sitemap
        </h2>

        <div style="background: #f8fafc; border: 1px solid var(--wp-border); border-radius: 4px; padding: 12px; margin-bottom: 16px;">
            <strong>XML Sitemap:</strong> Automatically generated and accessible at:
            <a href="/sitemap.xml" target="_blank" style="font-weight: 600;">/sitemap.xml &rarr;</a>
        </div>

        <div class="form-group">
            <label for="robots_txt">Robots.txt Content</label>
            <textarea id="robots_txt" name="robots_txt" class="form-control" rows="6" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($seo['robots_txt'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <span class="description">Instructions for search engine crawlers. View current output at <a href="/robots.txt" target="_blank">/robots.txt</a>.</span>
        </div>

        <div style="margin-top: 24px;">
            <button type="submit" class="btn btn-primary">Save SEO Settings</button>
        </div>
    </form>
</div>

