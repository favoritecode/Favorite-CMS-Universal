<?php if ($builderBackup): $be=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<div class="form-card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
<div><strong>Saved Builder design available</strong><small style="display:block;color:var(--wp-text-muted);margin-top:4px">Simple Edit controls this content. The previous design is kept as a backup.</small></div>
<button type="button" class="btn btn-secondary" id="cms-builder-review-backup">Preview &amp; restore Builder design</button>
</div>
<style>#cms-builder-backup-dialog::backdrop{background:#0008;backdrop-filter:blur(2px)}</style>
<dialog id="cms-builder-backup-dialog" style="position:fixed;top:50%;left:50%;right:auto;bottom:auto;transform:translate(-50%,-50%);margin:0;height:fit-content;overflow:auto;box-shadow:0 20px 70px #0006;width:min(1000px,94vw);max-height:90vh;padding:0;border:1px solid var(--wp-border);border-radius:10px;background:var(--admin-surface);color:var(--admin-text-heading)">
<header style="display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 18px;border-bottom:1px solid var(--wp-border)"><strong>Saved Builder design preview</strong><button type="button" class="btn btn-secondary" id="cms-builder-backup-close" aria-label="Close design preview">×</button></header>
<iframe id="cms-builder-backup-preview" title="Saved Builder design preview" sandbox="allow-scripts" style="width:100%;height:50vh;border:0;display:block"></iframe>
<div style="padding:16px 18px"><p style="margin-top:0">Restore this design as the editor for this page/post? Your saved Simple Edit content will stay in the database. Publication status and permalink stay unchanged.</p>
<p id="cms-builder-restore-status" role="alert"></p><button type="button" class="btn btn-primary" id="cms-builder-restore-confirm">Restore Builder design</button></div>
</dialog>
<script>
(()=>{'use strict';const config=<?php echo json_encode($builderBackup,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?>,dialog=document.getElementById('cms-builder-backup-dialog'),frame=document.getElementById('cms-builder-backup-preview'),button=document.getElementById('cms-builder-restore-confirm'),status=document.getElementById('cms-builder-restore-status');
document.getElementById('cms-builder-review-backup').addEventListener('click',()=>{frame.src=config.preview;dialog.showModal();});document.getElementById('cms-builder-backup-close').addEventListener('click',()=>dialog.close());
button.addEventListener('click',async()=>{if(!confirm('Restore the previewed Builder design? Any unsaved Simple Edit changes will not be saved. Your saved Simple Edit content stays as a backup. Continue?'))return;button.disabled=true;status.textContent='Restoring…';try{const body=new URLSearchParams({_token:config.csrf,id:config.id,native_baseline:config.native_baseline,design_baseline:config.design_baseline,confirm_restore:'1'});const response=await fetch(config.action,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Could not restore the design.');location.reload();}catch(error){status.textContent=error.message;button.disabled=false;}});
})();
</script>
<?php endif; ?>
