<?php $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<div class="cms-redirect-manager">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
    <div><h2 style="margin:0 0 6px;">URL Redirects</h2><p class="description" style="margin:0;">Send an exact site path to another page. Specific rules run before the general 404 fallback.</p></div>
    <a class="btn btn-primary" href="<?php echo $e(site_path('/admin/settings/redirects/new')); ?>">+ Add Redirect</a>
</div>
<?php if($notice): ?><div role="status" style="padding:12px;border-left:4px solid var(--wp-blue);background:var(--admin-surface);margin-bottom:16px;"><?php echo $e($notice); ?></div><?php endif; ?>
<?php if($error): ?><div role="alert" style="padding:12px;border-left:4px solid #d63638;background:var(--admin-surface);margin-bottom:16px;"><?php echo $e($error); ?></div><?php endif; ?>
<div class="form-card" style="margin-bottom:18px;padding:16px;">
    <form method="GET" action="<?php echo $e(site_path('/admin/settings/redirects')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <label for="redirect-search" class="screen-reader-text">Search redirects</label>
        <input type="search" id="redirect-search" name="s" class="form-control" placeholder="Search source or destination..." value="<?php echo $e($search); ?>" style="width:100%;max-width:420px;">
        <button type="submit" class="btn btn-secondary">Search</button><span class="description"><?php echo $total; ?> rule(s)</span>
    </form>
</div>
<div class="wp-table-wrap" style="overflow:auto;">
<table class="wp-table" style="min-width:700px;">
<thead><tr><th>Source path</th><th>Destination</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach($rows as $row): ?>
<tr>
    <td style="overflow-wrap:anywhere;max-width:260px;"><strong><a href="<?php echo $e(site_path('/admin/settings/redirects/edit?id='.$row['id'])); ?>"><?php echo $e($row['source']); ?></a></strong></td>
    <td style="overflow-wrap:anywhere;max-width:300px;"><?php echo $e($row['destination']); ?><?php if(!empty($row['preserve_query'])): ?><div class="description">Keep incoming query if destination has none</div><?php endif; ?></td>
    <td><?php echo (int)$row['status']; ?></td>
    <td><?php echo !empty($row['enabled'])?'Enabled':'Disabled'; ?></td>
    <td><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a class="btn btn-secondary" href="<?php echo $e(site_path('/admin/settings/redirects/edit?id='.$row['id'])); ?>" style="font-size:12px;">Edit</a>
        <form method="POST" action="<?php echo $e(site_path('/admin/settings/redirects/toggle')); ?>" style="margin:0;">
            <input type="hidden" name="_token" value="<?php echo $e($_SESSION['_token']??''); ?>"><input type="hidden" name="id" value="<?php echo $e($row['id']); ?>"><input type="hidden" name="revision" value="<?php echo $e($row['revision']); ?>">
            <button class="btn btn-secondary" type="submit" style="font-size:12px;"><?php echo !empty($row['enabled'])?'Disable':'Enable'; ?></button>
        </form>
        <form method="POST" action="<?php echo $e(site_path('/admin/settings/redirects/delete')); ?>" style="margin:0;" onsubmit="return confirm('Delete this redirect rule? The source and destination pages will not be deleted.');">
            <input type="hidden" name="_token" value="<?php echo $e($_SESSION['_token']??''); ?>"><input type="hidden" name="id" value="<?php echo $e($row['id']); ?>"><input type="hidden" name="revision" value="<?php echo $e($row['revision']); ?>"><button class="btn btn-secondary" type="submit" style="font-size:12px;color:var(--admin-danger-text,#d63638);">Delete</button>
        </form>
    </div></td>
</tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" style="padding:32px;text-align:center;">No redirects found. Use Add Redirect to create your first rule.</td></tr><?php endif; ?>
</tbody></table>
</div>
<?php if($totalPages>1): ?><nav aria-label="Redirect list pages" style="display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-top:16px;">
<?php if($page>1): ?><a class="btn btn-secondary" href="<?php echo $e(site_path('/admin/settings/redirects?'.http_build_query(['s'=>$search,'p'=>$page-1]))); ?>">Previous</a><?php endif; ?><span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span><?php if($page<$totalPages): ?><a class="btn btn-secondary" href="<?php echo $e(site_path('/admin/settings/redirects?'.http_build_query(['s'=>$search,'p'=>$page+1]))); ?>">Next</a><?php endif; ?>
</nav><?php endif; ?>
<p class="description" style="margin-top:18px;">Exact paths only, up to 1,000 rules. Pages and posts are not modified or deleted by these rules. <a href="<?php echo $e(site_path('/admin/settings?tab=site#not_found_page_id')); ?>">General 404 fallback settings &rarr;</a></p>
</div>
