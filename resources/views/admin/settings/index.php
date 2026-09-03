<div class="page-header">
    <h1 class="page-title">Settings</h1>
</div>

<div class="form-card" style="max-width: 700px;">
    <form method="POST" action="/admin/settings/update">
        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <h2 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            General Settings
        </h2>

        <div class="form-group">
            <label for="site_name">Site Title</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="<?php echo htmlspecialchars($settings['site_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="site_description">Tagline</label>
            <input type="text" id="site_description" name="site_description" class="form-control" value="<?php echo htmlspecialchars($settings['site_description'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">In a few words, explain what this site is about.</span>
        </div>

        <div class="form-group">
            <label for="site_url">Site Address (URL)</label>
            <input type="url" id="site_url" name="site_url" class="form-control" value="<?php echo htmlspecialchars($settings['site_url'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-group">
            <label for="admin_email">Administration Email Address</label>
            <input type="email" id="admin_email" name="admin_email" class="form-control" value="<?php echo htmlspecialchars($settings['admin_email'], ENT_QUOTES, 'UTF-8'); ?>" required>
            <span class="description">This address is used for admin purposes.</span>
        </div>

        <div class="form-group">
            <label for="timezone">Timezone</label>
            <input type="text" id="timezone" name="timezone" class="form-control" value="<?php echo htmlspecialchars($settings['timezone'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="description">Choose a city in the same timezone as you (e.g. UTC, America/New_York).</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Reading Settings
        </h2>

        <div class="form-group">
            <label>Your homepage displays</label>
            <div style="margin-top: 6px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 8px; cursor: pointer;">
                    <input type="radio" name="front_page_type" value="posts" <?php echo ($settings['front_page_type'] === 'posts') ? 'checked' : ''; ?>>
                    Your latest posts
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-bottom: 8px; cursor: pointer;">
                    <input type="radio" name="front_page_type" value="page" <?php echo ($settings['front_page_type'] === 'page') ? 'checked' : ''; ?>>
                    A static page:
                    <select name="front_page_id" class="form-control" style="width: auto; margin-left: 8px;">
                        <option value="0">&mdash; Select Page &mdash;</option>
                        <?php foreach ($pages as $p): ?>
                            <option value="<?php echo (int)$p->id; ?>" <?php echo ($settings['front_page_id'] == $p->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>

        <div class="form-group">
            <label for="posts_per_page">Blog pages show at most</label>
            <input type="number" id="posts_per_page" name="posts_per_page" class="form-control" style="width: 100px;" value="<?php echo (int)$settings['posts_per_page']; ?>" min="1" max="100">
            <span class="description">Number of posts to display per page.</span>
        </div>

        <h2 style="font-size: 16px; font-weight: 600; margin: 24px 0 16px; border-bottom: 1px solid var(--wp-border); padding-bottom: 8px;">
            Writing Settings
        </h2>

        <div class="form-group">
            <label for="default_category">Default Post Category</label>
            <select id="default_category" name="default_category" class="form-control" style="max-width: 250px;">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int)$cat->id; ?>" <?php echo ($settings['default_category'] == $cat->id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="margin-top: 24px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

