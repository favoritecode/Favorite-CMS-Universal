<?php
$seoEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$seoPreview=$_SESSION['seo_import_preview']??null;
if($seoPreview && ($seoPreview['expires']??0)<time()){unset($_SESSION['seo_import_preview']);$seoPreview=null;}
?>
<div class="form-card">
 <h2 style="font-size:18px;margin-bottom:12px;">Automatic SEO &amp; metadata</h2>
 <p class="description">Empty search metadata uses the content title and a readable excerpt/content summary automatically. Custom metadata takes priority. Social previews and article schema follow the same defaults. Existing noindex directives are respected.</p>
 <div class="editor-page-actions" style="margin-block:16px;">
  <a class="btn btn-secondary" href="<?php echo $seoEscape(site_path('/admin/seo?kind=post')); ?>">Posts</a>
  <a class="btn btn-secondary" href="<?php echo $seoEscape(site_path('/admin/seo?kind=page')); ?>">Pages</a>
  <a class="btn btn-secondary" href="<?php echo $seoEscape(site_path('/admin/seo/export?kind='.$seoDashboard['kind'].'&seo_page='.$seoDashboard['page'])); ?>">Export this page CSV</a>
  <a class="btn btn-secondary" target="_blank" href="<?php echo $seoEscape(site_path('/sitemap.xml')); ?>">View sitemap ↗</a>
 </div>
 <div class="wp-table-wrap"><table class="wp-table"><thead><tr><th>Content</th><th>Search preview / source</th><th>Review</th></tr></thead><tbody>
 <?php foreach($seoDashboard['items'] as $seoItem): $seoResolved=\FavoriteCMS\Services\SeoMetadata::resolve([$seoDashboard['kind']=>$seoItem]); $seoIssues=\FavoriteCMS\Services\SeoAudit::issues($seoItem); ?>
  <tr><td><a href="<?php echo $seoEscape(site_path('/admin/'.($seoDashboard['kind']==='post'?'posts':'pages').'/edit?id='.$seoItem->id)); ?>"><?php echo $seoEscape($seoItem->title); ?></a><br><span class="description"><?php echo $seoEscape($seoItem->status); ?></span></td>
   <td><strong style="color:var(--admin-link);"><?php echo $seoEscape($seoResolved['title']); ?></strong><p><?php echo $seoEscape($seoResolved['description']); ?></p><span class="description">Description: <?php echo $seoEscape($seoResolved['source']); ?></span></td>
   <td><?php if(!$seoIssues): ?>Automatic defaults ready<?php else: foreach($seoIssues as $seoIssue): ?><p><?php echo $seoEscape($seoIssue); ?></p><?php endforeach; endif; ?></td></tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <div class="editor-page-actions" style="margin-top:14px;">
  <span>Page <?php echo $seoDashboard['page']; ?> of <?php echo $seoDashboard['pages']; ?> · <?php echo $seoDashboard['total']; ?> items</span>
  <?php foreach(['Previous'=>-1,'Next'=>1]as$label=>$delta):$next=$seoDashboard['page']+$delta;if($next<1||$next>$seoDashboard['pages'])continue; ?>
   <a class="btn btn-secondary" href="<?php echo $seoEscape(site_path('/admin/seo?kind='.$seoDashboard['kind'].'&seo_page='.$next)); ?>"><?php echo $label; ?></a>
  <?php endforeach; ?>
 </div>
</div>
<div class="form-card seo-import-card">
 <style>
  .seo-import-card{overflow:hidden}
  .seo-import-heading{align-items:center!important;margin-bottom:20px!important}
  .seo-import-heading h2{font-size:20px!important;letter-spacing:-.02em}
  .seo-import-badge{min-height:38px;padding:0 14px!important;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,.03)}
  .seo-import-controls{grid-template-columns:minmax(0,1fr) 220px!important;align-items:center!important;gap:22px!important}
  .seo-import-file-wrap{padding:14px 15px 12px;border:1px solid var(--admin-border);border-radius:12px;background:var(--admin-surface-subtle);transition:border-color .18s ease,box-shadow .18s ease}
  .seo-import-file-wrap:focus-within{border-color:var(--admin-primary,#3b82f6);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
  .seo-import-file-wrap .form-group{margin:0!important}
  .seo-import-file-wrap label[for="seo_file"]{display:block;margin-bottom:9px;font-weight:700}
  .seo-import-file{display:block;width:100%;max-width:100%;min-height:46px;padding:6px 8px;border:1px dashed var(--admin-border);border-radius:9px;background:var(--admin-surface);color:var(--admin-text);font-size:13px;box-sizing:border-box}
  .seo-import-file::file-selector-button{margin-right:12px;padding:9px 13px;border:1px solid var(--admin-border);border-radius:7px;background:var(--admin-surface-subtle);color:var(--admin-text);font-weight:700;cursor:pointer;transition:background .18s ease,border-color .18s ease}
  .seo-import-file::file-selector-button:hover{border-color:var(--admin-primary,#3b82f6);background:rgba(59,130,246,.12)}
  .seo-import-overwrite{display:flex;align-items:flex-start;gap:9px;margin-top:12px;line-height:1.5;color:var(--admin-text-muted);font-size:13px}
  .seo-import-overwrite input{margin-top:3px;flex:0 0 auto}
  .seo-import-submit{width:100%;min-height:52px!important;align-self:center;border-radius:11px!important}
  @media(max-width:760px){.seo-import-controls{grid-template-columns:minmax(0,1fr)!important;gap:14px!important}.seo-import-submit{width:100%}.seo-import-heading{align-items:flex-start!important}.seo-import-badge{align-self:flex-start}}
  @media(max-width:480px){.seo-import-file-wrap{padding:12px}.seo-import-heading h2{font-size:18px!important}}
 </style>
 <div class="seo-import-heading" style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:18px;">
  <div style="min-width:0;flex:1;">
   <h2 style="font-size:18px;margin:0 0 10px;">Import SEO only</h2>
   <p class="description" style="margin:0;max-width:100%;">CSV/JSON · UTF-8 · up to 500 items / 5 MB. Required: object_type (post/page) and id, slug or URL. Optional: meta_title, meta_description, og_title, og_description, og_image_url, canonical_url, robots. Blank cells keep existing values and automatic fallback. Export a page above for a CSV template.</p>
  </div>
  <span class="seo-import-badge" style="flex:0 0 auto;display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid var(--admin-border);border-radius:999px;background:var(--admin-surface-subtle);color:var(--admin-text-muted);font-size:11px;font-weight:700;white-space:nowrap;">CSV / JSON · 500 max</span>
 </div>
 <form method="POST" enctype="multipart/form-data" action="<?php echo $seoEscape(site_path('/admin/seo/preview')); ?>">
  <input type="hidden" name="_token" value="<?php echo $seoEscape($_SESSION['_token']??''); ?>">
  <div class="seo-import-controls" style="display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:16px;">
   <div class="seo-import-file-wrap" style="min-width:0;">
    <div class="form-group"><label for="seo_file">SEO metadata file</label><input id="seo_file" class="seo-import-file" type="file" name="seo_file" accept=".csv,.json" required></div>
    <label class="seo-import-overwrite"><input type="checkbox" name="overwrite" value="1"> <span>Replace existing non-empty metadata (preview required)</span></label>
   </div>
   <button class="btn btn-primary seo-import-submit" type="submit" style="display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:0 20px;font-weight:750;white-space:nowrap;box-shadow:0 6px 18px rgba(37,99,235,.24);">Preview changes <span aria-hidden="true">→</span></button>
  </div>
 </form>
 <?php if($seoPreview): ?>
  <hr style="margin-block:18px;border-color:var(--admin-border);"><h3>Import preview</h3>
  <?php foreach($seoPreview['errors'] as $seoError): ?><p role="alert" style="color:var(--admin-danger-text);"><?php echo $seoEscape($seoError); ?></p><?php endforeach; ?>
  <p><?php echo count($seoPreview['plan']); ?> matched items. <?php echo $seoPreview['overwrite']?'Existing values may be replaced.':'Only empty fields will be filled.'; ?></p>
  <div style="max-height:400px;overflow:auto;">
  <?php foreach($seoPreview['plan'] as $seoRow): ?>
   <details style="margin-block:10px;"><summary><?php echo $seoEscape($seoRow['title']); ?> · <?php echo count($seoRow['changes']); ?> field changes</summary>
    <?php foreach($seoRow['changes']as$field=>$change): ?><p><strong><?php echo $seoEscape($field); ?></strong><br>Current: <?php echo $seoEscape($change['before']); ?><br>New: <?php echo $seoEscape($change['after']); ?></p><?php endforeach; ?>
   </details>
  <?php endforeach; ?>
  </div>
  <?php if(!$seoPreview['errors'] && $seoPreview['plan']): ?><form method="POST" action="<?php echo $seoEscape(site_path('/admin/seo/apply')); ?>">
   <input type="hidden" name="_token" value="<?php echo $seoEscape($_SESSION['_token']??''); ?>"><input type="hidden" name="preview_nonce" value="<?php echo $seoEscape($seoPreview['nonce']); ?>"><button type="submit" class="btn btn-primary">Apply reviewed metadata</button>
  </form><?php endif; ?>
 <?php endif; ?>
</div>