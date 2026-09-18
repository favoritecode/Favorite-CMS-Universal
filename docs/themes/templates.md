# Theme Templates

Templates are PHP files that combine HTML structure with variables supplied by controllers.

---

## 1. Homepage & Archive (`index.php`)

Supplied Variables:
- `$posts`: Array of published `Post` models.
- `$page`: Current page number.
- `$totalPages`: Total calculated pages.
- `$siteName`: Configured site name.
- `$siteDescription`: Site tagline.

Example loop:
```php
<?php require __DIR__ . '/header.php'; ?>

<main class="main-content">
    <?php if (empty($posts)): ?>
        <p>No articles published yet.</p>
    <?php else: ?>
        <div class="posts-grid">
            <?php foreach ($posts as $post): ?>
                <article class="post-card">
                    <h2><a href="/post/<?php echo htmlspecialchars($post->slug); ?>"><?php echo htmlspecialchars($post->title); ?></a></h2>
                    <p><?php echo htmlspecialchars($post->excerpt ?? ''); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/footer.php'; ?>
```

---

## 2. Single Post (`single.php`)

Supplied Variables:
- `$post`: The active `Post` model.
- `$comments`: Array of approved comments.
- `$categories`: Attached category objects.
- `$tags`: Attached tag objects.
- `$prevPost` / `$nextPost`: Chronological navigation models.

Use `clean_post_content($post->content)` to safely render rich HTML body content.
