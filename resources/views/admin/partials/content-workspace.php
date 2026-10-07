<?php
$workspaceEndpoint = site_path('/admin/' . $workspaceType . 's');
$workspaceBaseline = \FavoriteCMS\Services\ContentWorkspace::fingerprint($workspaceRecord);
?>
<div id="content-workspace" class="form-card" style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span id="workspace-status" role="status" aria-live="polite">Server drafts are loading…</span>
    <label for="workspace-snapshots">Drafts &amp; revisions</label>
    <select id="workspace-snapshots" class="form-control" style="width:auto;max-width:100%"><option value="">Choose a saved version</option></select>
    <button type="button" id="workspace-restore" class="btn btn-secondary" disabled>Load into editor</button>
    <small style="color:var(--admin-text-muted)">Restores title, content and excerpt. Save normally to apply; publishing, images, categories and SEO use the existing controls.</small>
</div>
<script>
window.favoriteContentWorkspaceConfig = <?php echo json_encode(['type'=>$workspaceType,'endpoint'=>$workspaceEndpoint,'baseline'=>$workspaceBaseline,'id'=>(int)($workspaceRecord->id ?? 0),'user'=>(int)($_SESSION['auth_user_id'] ?? 0)], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
<?php include __DIR__ . '/content-workspace.js'; ?>
</script>