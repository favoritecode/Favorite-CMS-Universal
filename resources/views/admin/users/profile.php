<div class="page-header">
    <h1 class="page-title">My Profile</h1>
</div>

<div class="form-card" style="max-width: 650px;">
    <form method="POST" action="/admin/users/profile/update">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" class="form-control" value="<?php echo htmlspecialchars($user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly style="background: #f0f0f1;">
            <span class="description">Usernames cannot be changed.</span>
        </div>

        <div class="form-group">
            <label for="name">Display Name *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($user->name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="email">Email Address *</label>
            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="bio">Biographical Info</label>
            <textarea id="bio" name="bio" class="form-control" rows="4"><?php echo htmlspecialchars($user->bio ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            <span class="description">Share a little biographical information to fill out your profile.</span>
        </div>

        <div class="form-group">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep your current password">
        </div>

        <button type="submit" class="btn btn-primary">Update Profile</button>
    </form>
</div>

