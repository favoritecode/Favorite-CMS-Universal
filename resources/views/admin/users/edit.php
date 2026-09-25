<?php
$isEdit = !empty($user);
$action = $isEdit ? '/admin/users/update' : '/admin/users/store';
$activeSuperAdminCount = \FavoriteCMS\Models\User::getActiveSuperAdminCount();
$isTargetSuperAdmin = $isEdit && $user->hasRole('super-admin') && $user->isActive();
$isSoleActiveSuperAdmin = $isTargetSuperAdmin && ($activeSuperAdminCount <= 1);
$currentLoggedInId = (int)($_SESSION['auth_user_id'] ?? 0);
$isSelf = $isEdit && ((int)$user->id === $currentLoggedInId);
?>
<div class="page-header">
    <h1 class="page-title"><?php echo $isEdit ? 'Edit User' : 'Add New User'; ?></h1>
</div>

<div class="form-card" style="max-width: 650px;">
    <form method="POST" action="<?php echo $action; ?>">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?php echo (int)$user->id; ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="username">Username <?php echo $isEdit ? '(cannot be changed)' : '*'; ?></label>
            <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isEdit ? 'readonly style="background: #f0f0f1;"' : 'required'; ?>>
        </div>

        <div class="form-group">
            <label for="name">Display / Full Name</label>
            <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($user->name ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="role_id">Role</label>
            <?php if ($isSoleActiveSuperAdmin): ?>
                <div class="alert alert-warning" style="padding: 8px 12px; margin-bottom: 8px; font-size: 13px; background: #fef3c7; border-left: 4px solid #f59e0b; color: #92400e; border-radius: 4px;">
                    <strong>Protected Role:</strong> This account is the sole active Super Admin. Create or promote another Super Admin before relinquishing this role.
                </div>
                <?php
                $currentRoleId = !empty($userRoles) ? reset($userRoles) : 1;
                ?>
                <input type="hidden" name="role_id" value="<?php echo (int)$currentRoleId; ?>">
                <select id="role_id" class="form-control" disabled style="background: #f8fafc; cursor: not-allowed;">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo (int)$role->id; ?>" <?php echo in_array((int)$role->id, $userRoles ?? [], true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role->name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($isSelf && $isTargetSuperAdmin): ?>
                <div class="alert alert-info" style="padding: 8px 12px; margin-bottom: 8px; font-size: 13px; background: #e0f2fe; border-left: 4px solid #0284c7; color: #075985; border-radius: 4px;">
                    <strong>Self-Demotion Guard:</strong> You cannot demote your own Super Admin account.
                </div>
                <?php
                $currentRoleId = !empty($userRoles) ? reset($userRoles) : 1;
                ?>
                <input type="hidden" name="role_id" value="<?php echo (int)$currentRoleId; ?>">
                <select id="role_id" class="form-control" disabled style="background: #f8fafc; cursor: not-allowed;">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo (int)$role->id; ?>" <?php echo in_array((int)$role->id, $userRoles ?? [], true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role->name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <select id="role_id" name="role_id" class="form-control">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo (int)$role->id; ?>" <?php echo in_array((int)$role->id, $userRoles ?? [], true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role->name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="password"><?php echo $isEdit ? 'New Password (leave blank to keep current)' : 'Password *'; ?></label>
            <input type="password" id="password" name="password" class="form-control" <?php echo $isEdit ? '' : 'required'; ?> autocomplete="new-password">
        </div>

        <?php if ($isEdit): ?>
            <div class="form-group">
                <label for="status">Account Status</label>
                <?php if ($isSoleActiveSuperAdmin): ?>
                    <input type="hidden" name="status" value="active">
                    <select id="status" class="form-control" disabled style="background: #f8fafc; cursor: not-allowed;">
                        <option value="active" selected>Active</option>
                    </select>
                    <span class="description" style="color: #92400e;">The sole active Super Admin account cannot be suspended or banned.</span>
                <?php elseif ($isSelf): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($user->status ?? 'active', ENT_QUOTES, 'UTF-8'); ?>">
                    <select id="status" class="form-control" disabled style="background: #f8fafc; cursor: not-allowed;">
                        <option value="<?php echo htmlspecialchars($user->status ?? 'active', ENT_QUOTES, 'UTF-8'); ?>" selected>
                            <?php echo htmlspecialchars(ucfirst($user->status ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    </select>
                    <span class="description" style="color: #92400e;">You cannot modify your own account status.</span>
                <?php else: ?>
                    <select id="status" name="status" class="form-control">
                        <option value="active" <?php echo ($user->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="suspended" <?php echo ($user->status ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="banned" <?php echo ($user->status ?? '') === 'banned' ? 'selected' : ''; ?>>Banned</option>
                    </select>
                    <span class="description">Suspended users cannot create posts or upload media. Banned users cannot log in.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update User' : 'Add New User'; ?></button>
    </form>
</div>

