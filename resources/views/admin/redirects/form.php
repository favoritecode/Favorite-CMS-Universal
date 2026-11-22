<?php $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$editing=$entry['id']!==''; ?>
<style>
.cms-redirect-manager{width:100%;max-width:none;min-width:0}
.cms-redirect-manager .redirect-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 24px;margin-bottom:24px}
.cms-redirect-manager .redirect-form-grid .form-group{margin:0;min-width:0}
.cms-redirect-manager .redirect-form-grid .form-control{width:100%;max-width:none}
.cms-redirect-manager .redirect-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:4px 0 20px}
.cms-redirect-manager .redirect-option{display:flex;align-items:flex-start;gap:10px;margin:0;padding:15px;border:1px solid var(--wp-border);border-radius:8px;background:var(--admin-surface-subtle);font-weight:normal}
.cms-redirect-manager .redirect-option input{flex:0 0 auto;margin-top:3px}
@media(max-width:680px){.cms-redirect-manager .redirect-form-grid,.cms-redirect-manager .redirect-options{grid-template-columns:1fr}.cms-redirect-manager .redirect-edit-form{padding:18px!important}}
</style>
<div class="cms-redirect-manager">
<a href="<?php echo $e(site_path('/admin/settings/redirects')); ?>">&larr; All Redirects</a>
<h2 style="margin:16px 0 6px;"><?php echo $editing?'Edit Redirect':'Add Redirect'; ?></h2>
<p class="description" style="margin-bottom:20px;">Connect one source path to one destination. Existing pages remain unchanged.</p>
<?php if($error): ?><div role="alert" style="padding:14px;border-left:4px solid #d63638;background:var(--admin-surface);margin-bottom:18px;"><?php echo $e($error); ?></div><?php endif; ?>
<form method="POST" action="<?php echo $e(site_path('/admin/settings/redirects/'.($editing?'update':'store'))); ?>" class="form-card redirect-edit-form" style="padding:24px;">
    <input type="hidden" name="_token" value="<?php echo $e($_SESSION['_token']??''); ?>"><input type="hidden" name="id" value="<?php echo $e($entry['id']); ?>"><input type="hidden" name="revision" value="<?php echo $e($entry['revision']); ?>">
    <div class="redirect-form-grid">
        <div class="form-group"><label for="redirect-source">Source URL path</label><input type="text" class="form-control" id="redirect-source" name="source" value="<?php echo $e($entry['source']); ?>" placeholder="/old-page" required maxlength="2048"><span class="description">The page visitors open. Exact path; no query string, #fragment or wildcard. Trailing slash is ignored.</span></div>
        <div class="form-group"><label for="redirect-destination">Destination URL path</label><input type="text" class="form-control" id="redirect-destination" name="destination" value="<?php echo $e($entry['destination']); ?>" placeholder="/new-page" required maxlength="2048"><span class="description">Where visitors should go. Query parameters and #anchors are supported.</span></div>
    </div>
    <div class="redirect-form-grid">
    <div class="form-group"><label for="redirect-page-picker">Or choose a published page</label><select id="redirect-page-picker" class="form-control"><option value="">Choose a page to fill the destination...</option><?php foreach($pages as$page): ?><option value="<?php echo $e($page->url()); ?>"><?php echo $e($page->title); ?></option><?php endforeach; ?></select><span class="description">You can also paste a full URL belonging to this site. External websites and protected system paths are not supported.</span></div>
    <div class="form-group"><label for="redirect-status">Redirect type</label><select name="status" id="redirect-status" class="form-control"><option value="301" <?php echo (int)$entry['status']===301?'selected':''; ?>>301 &mdash; Permanent replacement</option><option value="302" <?php echo (int)$entry['status']===302?'selected':''; ?>>302 &mdash; Temporary move</option></select></div>
    </div>
    <div class="redirect-options">
    <label class="redirect-option"><input type="checkbox" name="enabled" value="1" <?php echo $entry['enabled']?'checked':''; ?>>Enable this redirect</label>
    <label class="redirect-option"><input type="checkbox" name="preserve_query" value="1" <?php echo $entry['preserve_query']?'checked':''; ?>><span>Keep the incoming query string if the final destination has none</span></label>
    </div>
    <p class="description">Example: /old-guide?ref=email &rarr; /new-guide?ref=email. Specific rules also work for existing public pages, not only 404s. Redirect loops and duplicate sources are rejected. Changes apply on the next request; a browser may retain an older cached redirect.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;"><button type="submit" class="btn btn-primary"><?php echo $editing?'Save Changes':'Add Redirect'; ?></button><a class="btn btn-secondary" href="<?php echo $e(site_path('/admin/settings/redirects')); ?>">Cancel</a></div>
</form>
</div>
<script>document.getElementById('redirect-page-picker').addEventListener('change',function(){if(this.value)document.getElementById('redirect-destination').value=this.value;});</script>
