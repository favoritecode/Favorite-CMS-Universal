<div class="page-header">
    <h1 class="page-title">Dashboard</h1>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="form-card" style="padding: 16px;">
        <h3 style="color: var(--wp-text-muted); font-size: 13px; text-transform: uppercase;">Posts</h3>
        <div style="font-size: 26px; font-weight: 700; color: #1d2327; margin: 4px 0;"><?php echo $postsCount['all'] ?? 0; ?></div>
        <div style="font-size: 12px; color: var(--wp-text-muted);">
            <?php echo $postsCount['published'] ?? 0; ?> published &bull; <?php echo $postsCount['draft'] ?? 0; ?> drafts
        </div>
        <a href="/admin/posts" style="display: inline-block; margin-top: 8px; font-size: 12px;">Manage Posts &rarr;</a>
    </div>

    <div class="form-card" style="padding: 16px;">
        <h3 style="color: var(--wp-text-muted); font-size: 13px; text-transform: uppercase;">Pages</h3>
        <div style="font-size: 26px; font-weight: 700; color: #1d2327; margin: 4px 0;"><?php echo $pagesCount['all'] ?? 0; ?></div>
        <div style="font-size: 12px; color: var(--wp-text-muted);">
            <?php echo $pagesCount['published'] ?? 0; ?> published
        </div>
        <a href="/admin/pages" style="display: inline-block; margin-top: 8px; font-size: 12px;">Manage Pages &rarr;</a>
    </div>

    <div class="form-card" style="padding: 16px;">
        <h3 style="color: var(--wp-text-muted); font-size: 13px; text-transform: uppercase;">Comments</h3>
        <div style="font-size: 26px; font-weight: 700; color: #1d2327; margin: 4px 0;"><?php echo $commentsCount['all'] ?? 0; ?></div>
        <div style="font-size: 12px; color: var(--wp-text-muted);">
            <?php echo $commentsCount['pending'] ?? 0; ?> pending moderation
        </div>
        <a href="/admin/comments" style="display: inline-block; margin-top: 8px; font-size: 12px;">Moderate Comments &rarr;</a>
    </div>

    <div class="form-card" style="padding: 16px;">
        <h3 style="color: var(--wp-text-muted); font-size: 13px; text-transform: uppercase;">Users & Media</h3>
        <div style="font-size: 26px; font-weight: 700; color: #1d2327; margin: 4px 0;"><?php echo $userCount; ?> Users</div>
        <div style="font-size: 12px; color: var(--wp-text-muted);">
            <?php echo $mediaCount; ?> media files
        </div>
        <a href="/admin/users" style="display: inline-block; margin-top: 8px; font-size: 12px;">Manage Users &rarr;</a>
    </div>
</div>

<div class="form-row">
    <!-- At a Glance & Quick Links -->
    <div class="form-card">
        <h2 style="font-size: 16px; margin-bottom: 12px; font-weight: 600;">Welcome to Favorite CMS!</h2>
        <p style="color: var(--wp-text-muted); font-size: 13px; margin-bottom: 16px;">
            We've assembled some quick links to get you started customizing and building your site:
        </p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
            <a href="/admin/posts/new" class="btn btn-primary">+ Write Your First Post</a>
            <a href="/admin/pages/new" class="btn btn-secondary">+ Add an About Page</a>
            <a href="/admin/themes" class="btn btn-secondary">Customize Theme</a>
            <a href="/" target="_blank" class="btn btn-secondary">View Your Site &rarr;</a>
        </div>

        <h3 style="font-size: 14px; margin-bottom: 10px; font-weight: 600;">Recent Published Posts</h3>
        <?php if (empty($recentPosts)): ?>
            <p style="color: var(--wp-text-muted); font-size: 12px;">No posts published yet.</p>
        <?php else: ?>
            <ul style="list-style: none; font-size: 13px;">
                <?php foreach ($recentPosts as $p): ?>
                    <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; display: flex; justify-content: space-between;">
                        <a href="/admin/posts/edit?id=<?php echo (int)$p->id; ?>"><?php echo htmlspecialchars($p->title, ENT_QUOTES, 'UTF-8'); ?></a>
                        <span style="color: var(--wp-text-muted); font-size: 12px;"><?php echo date('M j, Y', strtotime($p->published_at ?? $p->created_at)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Quick Draft -->
    <div class="form-card">
        <h2 style="font-size: 16px; margin-bottom: 12px; font-weight: 600;">Quick Draft</h2>
        <form method="POST" action="/admin/posts/quick-draft">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="draft_title">Title</label>
                <input type="text" id="draft_title" name="title" class="form-control" placeholder="What's on your mind?" required>
            </div>
            <div class="form-group">
                <label for="draft_content">Content</label>
                <textarea id="draft_content" name="content" class="form-control" rows="4" placeholder="Draft your ideas here..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Draft</button>
        </form>
    </div>
</div>

