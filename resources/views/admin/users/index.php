<div class="page-header">
    <h1 class="page-title">Users</h1>
    <a href="/admin/users/new" class="btn btn-primary">Add New User</a>
</div>

<form method="POST" action="/admin/users/bulk" id="users-bulk-form">
    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px;">
        <select name="bulk_action" class="form-control" style="width: auto; max-width: 200px; display: inline-block;">
            <option value="">Bulk Actions</option>
            <option value="activate">Activate / Restore</option>
            <option value="suspend">Suspend</option>
            <option value="ban">Ban</option>
        </select>
        <button type="submit" class="btn btn-secondary" onclick="return confirmBulkAction('users-bulk-form')">Apply</button>
    </div>

    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">
                        <input type="checkbox" id="select-all-users" onclick="toggleSelectAll(this, 'ids[]')">
                    </th>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th style="text-align: center;">Posts</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $userRoles = $u->getRoles();
                    $primaryRole = !empty($userRoles) ? $userRoles[0] : null;
                    $roleName = $primaryRole ? $primaryRole->name : 'Subscriber';
                    $isSelf = ((int)$u->id === (int)($_SESSION['auth_user_id'] ?? 0));
                    $postCount = $u->getPostCount();
                    $userStatus = $u->status ?? 'active';

                    $statusBadgeStyle = match ($userStatus) {
                        'active'    => 'background: #dcfce7; color: #15803d;',
                        'suspended' => 'background: #fef3c7; color: #b45309; font-weight: 700;',
                        'banned'    => 'background: #fee2e2; color: #b91c1c; font-weight: 700;',
                        default     => 'background: #f1f5f9; color: #475569;',
                    };
                    ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if (!$isSelf): ?>
                                <input type="checkbox" name="ids[]" value="<?php echo (int)$u->id; ?>" class="bulk-cb">
                            <?php else: ?>
                                <input type="checkbox" disabled title="You cannot bulk-edit your own account">
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong>
                                <a href="/admin/users/edit?id=<?php echo (int)$u->id; ?>" style="font-size: 13.5px; color: #1d2327;">
                                    <?php echo htmlspecialchars($u->username ?? $u->name, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </strong>
                            <?php if ($isSelf): ?>
                                <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 3px; margin-left: 4px;">You</span>
                            <?php endif; ?>
                            <div class="row-actions">
                                <a href="/admin/users/edit?id=<?php echo (int)$u->id; ?>">Edit Profile</a>
                                <?php if (!$isSelf): ?>
                                    | <a href="/admin/users/delete?id=<?php echo (int)$u->id; ?>" onclick="return confirm('Permanently delete user &quot;<?php echo htmlspecialchars($u->username); ?>&quot;?');" style="color: var(--wp-danger);">Delete</a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($u->name ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><a href="mailto:<?php echo htmlspecialchars($u->email, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u->email, ENT_QUOTES, 'UTF-8'); ?></a></td>
                        <td>
                            <?php if (!$isSelf && !empty($roles)): ?>
                                <form method="POST" action="/admin/users/role" style="display: inline-block;">
                                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$u->id; ?>">
                                    <select name="role_id" onchange="this.form.submit()" style="font-size: 12px; padding: 3px 6px; border: 1px solid #cbd5e1; border-radius: 3px; background: #fff;">
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?php echo (int)$r->id; ?>" <?php echo ($primaryRole && (int)$primaryRole->id === (int)$r->id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($r->name, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                <span style="font-weight: 500;"><?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase; <?php echo $statusBadgeStyle; ?>">
                                <?php echo htmlspecialchars(ucfirst($userStatus), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <a href="/admin/posts?s=<?php echo urlencode($u->username); ?>" style="font-weight: 600; color: #2271b1;" title="View Posts by <?php echo htmlspecialchars($u->username); ?>">
                                <?php echo $postCount; ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!$isSelf): ?>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <?php if ($userStatus === 'active'): ?>
                                        <a href="/admin/users/status?id=<?php echo (int)$u->id; ?>&status=suspended" class="btn btn-secondary" style="padding: 2px 7px; font-size: 11px; color: #b45309;" title="Suspend user">
                                            Suspend
                                        </a>
                                        <a href="/admin/users/status?id=<?php echo (int)$u->id; ?>&status=banned" class="btn btn-secondary" style="padding: 2px 7px; font-size: 11px; color: #b91c1c;" title="Ban user" onclick="return confirm('Ban user &quot;<?php echo htmlspecialchars($u->username); ?>&quot;? They will not be able to log in.');">
                                            Ban
                                        </a>
                                    <?php elseif ($userStatus === 'suspended'): ?>
                                        <a href="/admin/users/status?id=<?php echo (int)$u->id; ?>&status=active" class="btn btn-secondary" style="padding: 2px 7px; font-size: 11px; color: #15803d; font-weight: 600;" title="Reactivate user">
                                            Activate
                                        </a>
                                        <a href="/admin/users/status?id=<?php echo (int)$u->id; ?>&status=banned" class="btn btn-secondary" style="padding: 2px 7px; font-size: 11px; color: #b91c1c;" title="Ban user">
                                            Ban
                                        </a>
                                    <?php elseif ($userStatus === 'banned'): ?>
                                        <a href="/admin/users/status?id=<?php echo (int)$u->id; ?>&status=active" class="btn btn-secondary" style="padding: 2px 7px; font-size: 11px; color: #15803d; font-weight: 600;" title="Restore user">
                                            Restore
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--wp-text-muted); font-size: 12px;">Active Account</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
function toggleSelectAll(master, groupName) {
    var cbs = document.getElementsByName(groupName);
    for (var i = 0; i < cbs.length; i++) {
        if (!cbs[i].disabled) {
            cbs[i].checked = master.checked;
        }
    }
}
function confirmBulkAction(formId) {
    var form = document.getElementById(formId);
    var action = form.querySelector('select[name="bulk_action"]').value;
    if (!action) {
        alert('Please select a bulk action.');
        return false;
    }
    var checked = form.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) {
        alert('Please select at least one user.');
        return false;
    }
    if (action === 'ban') {
        return confirm('Are you sure you want to ban ' + checked.length + ' user(s)? They will immediately lose access.');
    }
    if (action === 'suspend') {
        return confirm('Are you sure you want to suspend ' + checked.length + ' user(s)?');
    }
    return true;
}
</script>

