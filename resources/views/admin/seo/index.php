<div class="settings-page">
    <div class="page-header settings-page-header">
        <div>
            <h1 class="page-title">SEO Settings</h1>
            <p class="settings-page-subtitle">Manage search visibility, metadata and analytics integrations.</p>
        </div>
        <span class="settings-page-badge">Search &amp; Analytics</span>
    </div>
    <style>
        .settings-page { width:100%; max-width:none; }
        .settings-page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:22px; }
        .settings-page-subtitle { margin:6px 0 0; color:var(--wp-text-muted, #94a3b8); font-size:13px; }
        .settings-page-badge { display:inline-flex; align-items:center; border:1px solid var(--admin-border); background:var(--admin-surface-subtle); color:var(--admin-text-muted); border-radius:999px; padding:7px 12px; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
        .settings-page .form-card { max-width:none !important; width:100%; box-sizing:border-box; border-radius:12px; padding:clamp(18px,2.2vw,30px); }
        .settings-page .form-group { margin-bottom:22px; }
        .settings-page .form-control { max-width:100%; }
        .settings-page .description { line-height:1.6; }
        .seo-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 22px;padding:0;border:0}
        .seo-tab{position:relative;display:flex;align-items:center;justify-content:flex-start;gap:12px;min-height:84px;padding:18px 20px;text-align:left;border:1px solid var(--admin-border);border-radius:14px;background:linear-gradient(145deg,var(--admin-surface-subtle),var(--admin-surface));color:var(--admin-text);font-weight:750;cursor:pointer;box-shadow:0 5px 16px rgba(0,0,0,.08);transition:border-color .18s ease,transform .18s ease,box-shadow .18s ease}
        .seo-tab:before{content:"";display:block;flex:0 0 42px;width:42px;height:42px;border-radius:12px;background:rgba(59,130,246,.14);border:1px solid rgba(96,165,250,.2)}
        .seo-tab:after{content:"";position:absolute;left:18px;right:18px;bottom:0;height:3px;border-radius:3px 3px 0 0;background:transparent}
        .seo-tab:hover{transform:translateY(-1px);border-color:var(--admin-primary,#3b82f6);box-shadow:0 9px 22px rgba(0,0,0,.12)}
        .seo-tab[aria-selected="true"]{background:linear-gradient(135deg,rgba(37,99,235,.2),var(--admin-surface));border-color:var(--admin-primary,#3b82f6);color:var(--admin-text);box-shadow:0 8px 22px rgba(37,99,235,.12)}
        .seo-tab[aria-selected="true"]:after{background:var(--admin-primary,#3b82f6)}
        .seo-tab[data-seo-tab="automatic"]:before{content:"✦";display:flex;align-items:center;justify-content:center;color:#60a5fa;font-size:22px}
        .seo-tab[data-seo-tab="verification"]:before{content:"✓";display:flex;align-items:center;justify-content:center;color:#60a5fa;font-size:22px}
        .seo-panel[hidden]{display:none!important}
        .seo-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}.seo-grid>.form-group{min-width:0}.seo-grid .form-control{width:100%!important;max-width:none!important;box-sizing:border-box}.seo-grid .span-2{grid-column:1/-1}
        .seo-settings-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:stretch}
        .seo-settings-form>#seo-panel-automatic-settings{grid-column:1;grid-row:1;min-width:0}
        .seo-settings-form>#seo-panel-robots{grid-column:2;grid-row:1;min-width:0}
        .seo-settings-form>#seo-panel-verification{grid-column:1/-1;grid-row:auto;min-width:0}
        .seo-settings-form .seo-default-card{height:100%;box-sizing:border-box}
        .seo-settings-form #seo-panel-robots .form-card{height:100%;display:flex;flex-direction:column;box-sizing:border-box}
        .seo-settings-form #seo-panel-robots textarea{min-height:180px;resize:vertical}
        .seo-verification-card{border:1px solid var(--admin-border);border-radius:10px;padding:16px;margin:12px 0;background:var(--admin-surface-subtle)}.seo-verification-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
        .seo-verification-title-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:18px 0 16px;padding:0 0 12px;border-bottom:1px solid var(--wp-border)}
        .seo-verification-title-row h2{font-size:18px;font-weight:750;margin:0!important;border:0!important;padding:0!important;line-height:1.35}
        .seo-add-verification{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 15px;border-radius:9px;font-weight:700;white-space:nowrap;box-shadow:0 4px 12px rgba(37,99,235,.12)}
        .seo-tracking-note{grid-column:1/-1;background:var(--admin-surface-subtle);border:1px solid var(--wp-border);border-radius:10px;padding:13px 15px;margin-bottom:4px;font-size:13px;color:var(--admin-text-muted);line-height:1.6}
        .seo-pixel-title{grid-column:1/-1!important;width:100%;box-sizing:border-box;margin:8px 0 0!important;padding:0 0 12px!important;border-bottom:1px solid var(--wp-border)}
        .seo-pixel-note{grid-column:1/-1;background:var(--admin-surface-subtle);border:1px solid var(--wp-border);border-radius:10px;padding:13px 15px;margin-bottom:4px;font-size:13px;color:var(--admin-text-muted);line-height:1.6}
        .seo-grid > .seo-pixel-title,.seo-grid > .seo-pixel-note,.seo-grid > .seo-pixel-toggle,.seo-grid > .seo-pixel-id{grid-column:1/-1!important;width:100%;min-width:0;box-sizing:border-box}.seo-pixel-id .form-control{width:100%!important;max-width:none!important;box-sizing:border-box}.seo-pixel-toggle{min-width:0}.seo-pixel-id{min-width:0}
        @media(max-width:600px){.seo-verification-title-row{align-items:flex-start;flex-direction:column}.seo-add-verification{width:100%}}
        @media(max-width:900px){.seo-settings-form{grid-template-columns:minmax(0,1fr)}.seo-settings-form>#seo-panel-automatic-settings,.seo-settings-form>#seo-panel-robots,.seo-settings-form>#seo-panel-verification{grid-column:1;grid-row:auto}}
        @media(max-width:760px){.seo-grid{grid-template-columns:minmax(0,1fr)}.seo-grid .span-2{grid-column:auto}.seo-tabs{grid-template-columns:1fr}.seo-tab{min-height:72px;padding:14px 16px}}
        @media (max-width:700px) { .settings-page-header { align-items:flex-start; flex-direction:column; } .settings-page-badge { display:none; } }
    </style>

    <div class="seo-tabs" role="tablist" aria-label="SEO settings sections">
      <button type="button" class="seo-tab" role="tab" id="seo-tab-automatic" aria-controls="seo-panel-automatic" aria-selected="true" data-seo-tab="automatic">Automatic SEO &amp; Metadata</button>
      <button type="button" class="seo-tab" role="tab" id="seo-tab-verification" aria-controls="seo-panel-verification" aria-selected="false" data-seo-tab="verification">Search Engine &amp; Verification</button>
    </div>
    <section class="seo-panel" id="seo-panel-automatic" role="tabpanel" aria-labelledby="seo-tab-automatic">
    <?php include __DIR__ . '/automation.php'; ?>
    </section>

    <form class="seo-settings-form" method="POST" action="<?php echo htmlspecialchars(site_path('/admin/settings/seo/update'), ENT_QUOTES, 'UTF-8'); ?>">
    <section class="seo-panel" id="seo-panel-automatic-settings" role="tabpanel" aria-labelledby="seo-tab-automatic">
    <div class="form-card seo-default-card">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">General SEO Defaults</h2>
        <div class="seo-grid">
        <div class="form-group">
            <label for="separator">Title Separator</label>
            <input type="text" id="separator" name="separator" class="form-control" style="width: 80px;" value="<?php echo htmlspecialchars($seo['separator'], ENT_QUOTES, 'UTF-8'); ?>" required>
            <span class="description">Symbol separating the page title and site name in browser tabs (e.g. <code>—</code>, <code>|</code>, <code>&bull;</code>).</span>
        </div>

        <div class="form-group span-2">
            <label for="meta_description">Global Meta Description</label>
            <textarea id="meta_description" name="meta_description" class="form-control" rows="3"><?php echo htmlspecialchars($seo['meta_description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <span class="description">Fallback meta description used when a page/post does not define its own.</span>
        </div>

        <div class="form-group span-2">
            <label for="og_image">Default Social / Open Graph Image URL</label>
            <input type="url" id="og_image" name="og_image" class="form-control" value="<?php echo htmlspecialchars($seo['og_image'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://example.com/logo.jpg">
            <span class="description">Image displayed when links from your site are shared on social media.</span>
        </div>

        </div>
    </div>
    </section>
    <section class="seo-panel" id="seo-panel-verification" role="tabpanel" aria-labelledby="seo-tab-verification" hidden>
        <div class="seo-verification-title-row">
            <h2>Search Engine &amp; Verification</h2>
            <button type="button" class="btn btn-secondary seo-add-verification" id="seo-add-verification"><span aria-hidden="true">＋</span> Add verification provider</button>
        </div>

        <input type="hidden" name="verification_entries_present" value="1">
        <div id="seo-verification-entries">
        <?php $verificationEntries = $seo['verification_entries'] ?? []; if (!$verificationEntries) $verificationEntries = [['title'=>'Google Search Console','meta_name'=>'google-site-verification','key'=>$seo['google_site_verification'] ?? ''], ['title'=>'Bing Webmaster Tools','meta_name'=>'msvalidate.01','key'=>$seo['bing_site_verification'] ?? '']]; foreach ($verificationEntries as $entryIndex => $entry): ?>
          <div class="seo-verification-card" data-verification-entry>
            <div class="seo-verification-heading"><strong>Verification provider</strong><button type="button" class="btn btn-secondary" data-remove-verification <?php echo $entryIndex===0?'disabled':''; ?>>Remove</button></div>
            <div class="seo-grid">
              <div class="form-group"><label>Admin title</label><input type="text" name="verification_entries[<?php echo (int)$entryIndex; ?>][title]" class="form-control" value="<?php echo htmlspecialchars((string)($entry['title']??''),ENT_QUOTES,'UTF-8'); ?>" placeholder="Google Search Console" maxlength="100"></div>
              <div class="form-group"><label>Meta name</label><input type="text" name="verification_entries[<?php echo (int)$entryIndex; ?>][meta_name]" class="form-control" value="<?php echo htmlspecialchars((string)($entry['meta_name']??''),ENT_QUOTES,'UTF-8'); ?>" placeholder="google-site-verification" maxlength="100"></div>
              <div class="form-group span-2"><label>Verification key / content</label><input type="text" name="verification_entries[<?php echo (int)$entryIndex; ?>][key]" class="form-control" value="<?php echo htmlspecialchars((string)($entry['key']??''),ENT_QUOTES,'UTF-8'); ?>" placeholder="Paste the verification content from provider" maxlength="1000"></div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
        <h2 class="seo-pixel-title" style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Analytics &amp; Tag Management
        </h2>
        <div class="seo-grid">

        <div class="seo-tracking-note">
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

        <h2 class="seo-pixel-title" style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Meta Pixel (Facebook / Meta)
        </h2>
        <div class="seo-pixel-note">
            Meta Pixel is added only to public visitor pages. If the same Pixel is already installed through Google Tag Manager or your active theme, avoid enabling it twice to prevent duplicate events.
        </div>
        <div class="form-group seo-pixel-toggle">
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;">
                <input type="checkbox" name="meta_pixel_enabled" value="1" <?php echo !empty($seo['meta_pixel_enabled']) ? 'checked' : ''; ?>>
                Enable Meta Pixel
            </label>
        </div>
        <div class="form-group seo-pixel-id">
            <label for="meta_pixel_id">Meta Pixel ID</label>
            <input type="text" id="meta_pixel_id" name="meta_pixel_id" class="form-control" style="max-width:100%;" value="<?php echo htmlspecialchars($seo['meta_pixel_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 123456789012345">
            <span class="description">Enter the numeric Pixel ID from Meta Events Manager. The CMS validates the ID before saving.</span>
        </div>

        </div>
        <div style="margin-top:18px;"><button type="submit" class="btn btn-primary">Save Verification &amp; Tracking</button></div>
    </section>
    <section class="seo-panel" id="seo-panel-robots" role="tabpanel" aria-labelledby="seo-tab-automatic" hidden>
      <div class="form-card seo-default-card">
        <h2 style="font-size:16px;font-weight:600;margin:0 0 16px;border-bottom:1px solid var(--wp-border);padding-bottom:10px;">Robots.txt &amp; XML Sitemap</h2>

        <div style="background: var(--admin-surface-subtle); border: 1px solid var(--wp-border); border-radius: 4px; padding: 12px; margin-bottom: 16px;">
            <strong>XML Sitemap:</strong> Automatically generated and accessible at:
            <a href="/sitemap.xml" target="_blank" style="font-weight: 600;">/sitemap.xml &rarr;</a>
        </div>

        <div class="form-group">
            <label for="robots_txt">Robots.txt Content</label>
            <textarea id="robots_txt" name="robots_txt" class="form-control" rows="6" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($seo['robots_txt'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            <span class="description">Instructions for search engine crawlers. View current output at <a href="/robots.txt" target="_blank">/robots.txt</a>.</span>
        </div>

        <div style="margin-top:auto;padding-top:18px;"><button type="submit" class="btn btn-primary">Save SEO Settings</button></div>
      </div>
    </section>
    </form>
</div>
</section>
<script>
(function(){const root=document.querySelector('.settings-page');if(!root)return;const tabs=root.querySelectorAll('[data-seo-tab]');function activate(name){tabs.forEach(function(t){const on=t.dataset.seoTab===name;t.setAttribute('aria-selected',on?'true':'false');const p=root.querySelector('#seo-panel-'+t.dataset.seoTab);if(p)p.hidden=!on;});const autoSettings=root.querySelector('#seo-panel-automatic-settings'),robots=root.querySelector('#seo-panel-robots');if(autoSettings)autoSettings.hidden=name!=='automatic';if(robots)robots.hidden=name!=='automatic';}tabs.forEach(function(t){t.addEventListener('click',function(){activate(t.dataset.seoTab);});});activate(location.hash==='#verification'?'verification':'automatic');const list=root.querySelector('#seo-verification-entries'),add=root.querySelector('#seo-add-verification');if(list&&add){let idx=list.querySelectorAll('[data-verification-entry]').length;add.addEventListener('click',function(){const c=document.createElement('div');c.className='seo-verification-card';c.setAttribute('data-verification-entry','');c.innerHTML='<div class="seo-verification-heading"><strong>Verification provider</strong><button type="button" class="btn btn-secondary" data-remove-verification>Remove</button></div><div class="seo-grid"><div class="form-group"><label>Admin title</label><input class="form-control" maxlength="100" placeholder="e.g. Pinterest Verification"></div><div class="form-group"><label>Meta name</label><input class="form-control" maxlength="100" placeholder="provider meta name"></div><div class="form-group span-2"><label>Verification key / content</label><input class="form-control" maxlength="1000" placeholder="Paste verification content"></div></div>';c.querySelectorAll('input').forEach(function(i,n){i.name='verification_entries['+idx+']['+['title','meta_name','key'][n]+']';});idx++;list.appendChild(c);});list.addEventListener('click',function(e){const b=e.target.closest('[data-remove-verification]');if(!b||b.disabled)return;if(list.querySelectorAll('[data-verification-entry]').length>1)b.closest('[data-verification-entry]').remove();});}})();
</script>
