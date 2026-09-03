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

