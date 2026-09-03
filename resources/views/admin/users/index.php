<div class="page-header">
    <h1 class="page-title">Users</h1>
    <a href="/admin/users/new" class="btn btn-primary">Add New User</a>
</div>

<div class="wp-table-wrap">
    <table class="wp-table">
        <thead>
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <strong>
                            <a href="/admin/users/edit?id=<?php echo (int)$u->id; ?>">
                                <?php echo htmlspecialchars($u->username ?? $u->name, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </strong>
                        <div class="row-actions">
                            <a href="/admin/users/edit?id=<?php echo (int)$u->id; ?>">Edit</a>
                            <?php if ($u->id != ($_SESSION['auth_user_id'] ?? 0)): ?>
                                | <a href="/admin/users/delete?id=<?php echo (int)$u->id; ?>" onclick="return confirm('Delete this user?');" style="color: var(--wp-danger);">Delete</a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($u->name, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="mailto:<?php echo htmlspecialchars($u->email, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u->email, ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td>
                        <?php
                        $userRoles = $u->getRoles();
                        echo !empty($userRoles) ? htmlspecialchars(implode(', ', array_map(fn($r) => $r->name, $userRoles)), ENT_QUOTES, 'UTF-8') : 'Subscriber';
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

