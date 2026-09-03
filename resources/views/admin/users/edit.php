<?php
$isEdit = !empty($user);
$action = $isEdit ? '/admin/users/update' : '/admin/users/store';
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
            <select id="role_id" name="role_id" class="form-control">
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo (int)$role->id; ?>" <?php echo in_array((int)$role->id, $userRoles ?? [], true) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($role->name, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="password"><?php echo $isEdit ? 'New Password (leave blank to keep current)' : 'Password *'; ?></label>
            <input type="password" id="password" name="password" class="form-control" <?php echo $isEdit ? '' : 'required'; ?> autocomplete="new-password">
        </div>

        <?php if ($isEdit): ?>
            <div class="form-group">
                <label for="status">Account Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="active" <?php echo ($user->status ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo ($user->status ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="banned" <?php echo ($user->status ?? '') === 'banned' ? 'selected' : ''; ?>>Banned</option>
                </select>
                <span class="description">Suspended users cannot create posts or upload media. Banned users cannot log in.</span>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update User' : 'Add New User'; ?></button>
    </form>
</div>

