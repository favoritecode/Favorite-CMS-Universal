<?php
/**
 * User Profile & Account Settings View
 *
 * Variables passed from UserController:
 * - $user: \FavoriteCMS\Models\User
 * - $primaryRole: string
 * - $avatarUrl: ?string
 * - $postCount: int
 */

$status = $user->status ?? 'active';
$initial = strtoupper(substr($user->name ?: $user->username ?: 'U', 0, 1));
$token = $_SESSION['_token'] ?? '';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profile &amp; Account Settings</h1>
        <p style="color: var(--wp-text-muted); font-size: 13px; margin-top: 4px;">
            Manage your personal profile information, avatar, and security credentials.
        </p>
    </div>
</div>

<?php if ($user->isSuspended()): ?>
    <div class="alert alert-warning" style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px;">
        <span style="font-size: 20px; line-height: 1;">⚠️</span>
        <div>
            <strong style="display: block; font-size: 14px; margin-bottom: 2px;">Account Suspended</strong>
            <span>Your account is currently suspended. You can view and update your profile details, but you cannot create or edit posts, upload media, or submit comments. Please contact a site administrator for assistance.</span>
        </div>
    </div>
<?php elseif ($user->isBanned()): ?>
    <div class="alert alert-danger" style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px;">
        <span style="font-size: 20px; line-height: 1;">🚫</span>
        <div>
            <strong style="display: block; font-size: 14px; margin-bottom: 2px;">Account Banned</strong>
            <span>Your account has been permanently banned. Access to publishing and interactive features is revoked.</span>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: minmax(280px, 340px) 1fr; gap: 24px; align-items: start;">
    <!-- Profile & Avatar Summary Card -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h5>Profile Picture</h5>
        </div>
        <div class="card-body" style="text-align: center;">
            <div style="margin-bottom: 16px; display: inline-flex; justify-content: center;">
                <?php if (!empty($avatarUrl)): ?>
                    <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="<?php echo htmlspecialchars($user->name ?? $user->username, ENT_QUOTES, 'UTF-8'); ?>"
                         style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; box-shadow: var(--shadow-md);">
                <?php else: ?>
                    <div style="width: 110px; height: 110px; border-radius: 50%; background: #2563eb; color: #fff; font-size: 42px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 3px solid #e2e8f0; box-shadow: var(--shadow-md);">
                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
            </div>

            <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
                <?php echo htmlspecialchars($user->name ?: $user->username, ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <p style="color: var(--wp-text-muted); font-size: 12.5px; margin-bottom: 12px;">
                @<?php echo htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <div style="display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;">
                <span class="badge badge-primary">
                    <?php echo htmlspecialchars(ucfirst($primaryRole), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($status === 'active'): ?>
                    <span class="badge badge-success">Active</span>
                <?php elseif ($status === 'suspended'): ?>
                    <span class="badge badge-warning">Suspended</span>
                <?php elseif ($status === 'banned'): ?>
                    <span class="badge badge-danger">Banned</span>
                <?php else: ?>
                    <span class="badge badge-secondary"><?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!empty($isEmailVerified)): ?>
                    <span class="badge badge-success" title="Email Address Verified">Verified Email</span>
                <?php else: ?>
                    <span class="badge badge-warning" title="Email Verification Pending">Unverified Email</span>
                <?php endif; ?>
                <span class="badge badge-secondary">
                    <?php echo (int)$postCount; ?> <?php echo (int)$postCount === 1 ? 'Post' : 'Posts'; ?>
                </span>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--wp-border); margin: 16px 0;">

            <!-- Avatar Upload Form -->
            <form method="POST" action="/admin/users/profile/update" enctype="multipart/form-data" style="margin-bottom: 16px; text-align: left;">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="avatar_action" value="upload_avatar">
                <label for="avatar" class="form-label" style="font-size: 12.5px;">Upload New Picture</label>
                <input type="file" id="avatar" name="avatar" class="form-control" accept="image/jpeg,image/png,image/webp" style="font-size: 12px; padding: 6px;">
                <span style="font-size: 11.5px; color: var(--wp-text-muted); display: block; margin-top: 4px;">
                    Formats: JPG, PNG, or WebP. Max 2MB.
                </span>
                <button type="submit" class="btn btn-secondary btn-sm" style="margin-top: 8px; width: 100%;">
                    Upload Image
                </button>
            </form>

            <!-- External Avatar URL Form -->
            <form method="POST" action="/admin/users/profile/update" style="margin-bottom: 16px; text-align: left;">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="avatar_action" value="set_url">
                <label for="avatar_url" class="form-label" style="font-size: 12.5px;">Or Use Image URL</label>
                <input type="url" id="avatar_url" name="avatar_url" class="form-control"
                       placeholder="https://example.com/avatar.jpg"
                       value="<?php echo !empty($user->avatar) && preg_match('~^https?://~i', $user->avatar) ? htmlspecialchars($user->avatar, ENT_QUOTES, 'UTF-8') : ''; ?>"
                       style="font-size: 12px;">
                <span style="font-size: 11.5px; color: var(--wp-text-muted); display: block; margin-top: 4px;">
                    Direct link starting with http:// or https://.
                </span>
                <button type="submit" name="avatar_url_submit" value="1" class="btn btn-secondary btn-sm" style="margin-top: 8px; width: 100%;">
                    Save Image URL
                </button>
            </form>

            <?php if (!empty($user->avatar)): ?>
                <form method="POST" action="/admin/users/profile/update">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="avatar_action" value="remove">
                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;" onclick="return confirm('Are you sure you want to remove your profile picture?');">
                        Remove Profile Picture
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Personal & Account Details Form Card -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header">
            <h5>Account Information</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($pendingEmail)): ?>
                <div class="alert alert-info" style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px;">
                    <span style="font-size: 18px; line-height: 1;">✉️</span>
                    <div style="font-size: 13px;">
                        <strong>Pending Email Change:</strong> A confirmation link was sent to <code><?php echo htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8'); ?></code>. Your current email address remains active until the new address is verified.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="/admin/users/profile/update" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" class="form-control" value="<?php echo htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly style="background: #f8fafc; cursor: not-allowed;">
                        <span style="font-size: 11.5px; color: var(--wp-text-muted); display: block; margin-top: 4px;">
                            Usernames are permanent and cannot be changed.
                        </span>
                    </div>

                    <div class="form-group">
                        <label for="name">Display Name *</label>
                        <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($user->name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <span style="font-size: 11.5px; color: var(--wp-text-muted); display: block; margin-top: 4px;">
                        <?php if (!empty($pendingEmail)): ?>
                            Pending change to <strong><?php echo htmlspecialchars($pendingEmail, ENT_QUOTES, 'UTF-8'); ?></strong>. Changing this value again will issue a new confirmation token.
                        <?php else: ?>
                            Used for notifications, account security, and password recovery. Changing your email address requires confirmation.
                        <?php endif; ?>
                    </span>
                </div>

                <div class="form-group">
                    <label for="bio">Biographical Info</label>
                    <textarea id="bio" name="bio" class="form-control" rows="4" placeholder="Tell visitors and readers a little about yourself..."><?php echo htmlspecialchars($user->bio ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--wp-border); margin: 24px 0 20px 0;">

                <h4 style="font-size: 14px; font-weight: 600; color: #0f172a; margin-bottom: 14px;">
                    Security &amp; Password
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current">
                    </div>

                    <div class="form-group">
                        <label for="password_confirmation">Confirm New Password</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password" placeholder="Re-enter new password">
                    </div>
                </div>
                <span style="font-size: 11.5px; color: var(--wp-text-muted); display: block; margin-top: -8px; margin-bottom: 20px;">
                    Passwords must be at least 8 characters long when changing.
                </span>

                <hr style="border: 0; border-top: 1px solid var(--wp-border); margin: 20px 0;">

                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <button type="submit" class="btn btn-primary" style="padding: 8px 20px;">
                        Save Profile Changes
                    </button>
                    <span style="font-size: 12px; color: var(--wp-text-muted);">
                        Role and account status are managed by site administrators.
                    </span>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Danger Zone: Account Deletion -->
<div class="card" style="margin-top: 24px; border: 1px solid #fca5a5; background: #fff;">
    <div class="card-header" style="background: #fef2f2; border-bottom: 1px solid #fee2e2;">
        <h5 style="color: #991b1b; margin: 0; font-size: 14px;">
            ⚠️ Danger Zone — Delete Account
        </h5>
    </div>
    <div class="card-body">
        <?php if ($user->isSuspended()): ?>
            <p style="color: var(--wp-text-muted); font-size: 13px; margin: 0;">
                Account self-deletion is disabled while your account is suspended. Please contact a site administrator.
            </p>
        <?php elseif ($user->isBanned()): ?>
            <p style="color: var(--wp-text-muted); font-size: 13px; margin: 0;">
                Banned accounts cannot perform account self-deletion.
            </p>
        <?php elseif (!$canSelfDelete): ?>
            <p style="color: var(--wp-text-muted); font-size: 13px; margin: 0;">
                This administrator account cannot be deleted because it is the last remaining active site administrator.
            </p>
        <?php else: ?>
            <p style="font-size: 13px; color: #475569; margin-bottom: 16px;">
                Permanently delete your user account, login credentials, and active sessions. Any authored posts, pages, and media files will be preserved and safely reassigned to a site administrator so your contributions and website integrity remain intact.
            </p>

            <form method="POST" action="/admin/users/profile/delete-account" onsubmit="return confirm('Are you sure you want to permanently delete your account? This action cannot be undone.');" style="max-width: 480px;">
                <input type="hidden" name="_token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="delete_password" style="font-weight: 600; font-size: 12.5px;">Current Password *</label>
                    <input type="password" id="delete_password" name="password" class="form-control" required placeholder="Enter current password to confirm" autocomplete="current-password">
                </div>

                <div class="form-check" style="margin-bottom: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 13px; cursor: pointer; color: #991b1b;">
                        <input type="checkbox" name="confirm_delete" value="1" required style="margin-top: 2px; cursor: pointer;">
                        <span>I understand that this action is permanent and my account cannot be recovered.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-danger" style="background: #dc2626; border-color: #dc2626; padding: 7px 16px;">
                    Permanently Delete My Account
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
