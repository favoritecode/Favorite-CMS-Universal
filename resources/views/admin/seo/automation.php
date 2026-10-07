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
<div class="form-card">
 <h2 style="font-size:18px;margin-bottom:12px;">Import SEO only</h2>
 <p class="description">CSV/JSON · UTF-8 · up to 500 items / 5 MB. Required: object_type (post/page) and id, slug or URL. Optional: meta_title, meta_description, og_title, og_description, og_image_url, canonical_url, robots. Blank cells keep existing values and automatic fallback. Export a page above for a CSV template.</p>
 <form method="POST" enctype="multipart/form-data" action="<?php echo $seoEscape(site_path('/admin/seo/preview')); ?>">
  <input type="hidden" name="_token" value="<?php echo $seoEscape($_SESSION['_token']??''); ?>">
  <div class="form-group"><label for="seo_file">SEO metadata file</label><input id="seo_file" type="file" name="seo_file" accept=".csv,.json" required></div>
  <label><input type="checkbox" name="overwrite" value="1"> Replace existing non-empty metadata (preview required)</label>
  <p style="margin-top:12px;"><button class="btn btn-primary" type="submit">Preview changes</button></p>
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