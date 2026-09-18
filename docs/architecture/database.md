# Database & Migrations

Favorite CMS Universal uses a robust, clean PDO database abstraction layer designed for high reliability and SQL safety.

---

## 1. Database Connection & Abstraction (`FavoriteCMS\Core\Database`)

All database interactions use parameterized prepared statements preventing SQL injection.

### Executing Queries:

```php
$db = app(\FavoriteCMS\Core\Database::class);

// 1. Select Multiple Rows (Returns array of stdClass objects)
$posts = $db->select("SELECT * FROM posts WHERE status = ? ORDER BY id DESC", ['published']);

// 2. Select Single Row (Returns stdClass object or null)
$user = $db->selectOne("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);

// 3. Insert Row (Returns auto-increment ID)
$postId = $db->insert('posts', [
    'title'      => 'Hello World',
    'slug'       => 'hello-world',
    'content'    => 'My first post content.',
    'status'     => 'published',
    'user_id'    => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);

// 4. Update Rows (Returns affected row count)
$affected = $db->update('posts', ['status' => 'archived'], 'id = ?', [$postId]);

// 5. Delete Rows
$deleted = $db->delete('posts', 'id = ?', [$postId]);

// 6. Generic DDL / Execute
$db->execute("CREATE TABLE IF NOT EXISTS sample_table (id INT AUTO_INCREMENT PRIMARY KEY)");
```

---

## 2. Active Record Base Model (`FavoriteCMS\Models\BaseModel`)

Core entities inherit from `BaseModel`, providing dynamic attribute access, casting, and active record operations:

```php
use FavoriteCMS\Models\Post;

// Finding models
$post = Post::find(5);
$bySlug = Post::findBySlug('my-article');

// Creating models
$post = new Post([
    'title'   => 'New Post',
    'slug'    => 'new-post',
    'status'  => 'draft',
    'user_id' => 1,
]);
$post->save();

// Modifying and saving
$post->title = 'Updated Post Headline';
$post->save();

// Deleting
$post->delete();
```

---

## 3. Database Migrations (`database/migrations/`)

Favorite CMS maintains an automated, idempotent migration runner:

### Core Tables:
1. `001_create_users_table.php` — Users, password hashes, and statuses.
2. `002_create_roles_and_permissions_tables.php` — RBAC roles, capabilities, and pivot maps.
3. `003_create_posts_and_pages_tables.php` — Articles, static pages, excerpts, and revisions.
4. `004_create_taxonomies_tables.php` — Categories, tags, hierarchies, and post relationships.
5. `005_create_media_table.php` — Uploads, file paths, MIME types, and sizes.
6. `006_create_comments_table.php` — Discussion comments, approval workflows, and author info.
7. `007_create_settings_table.php` — Grouped key-value system settings.
8. `008_create_revisions_table.php` — Post content revision histories.
9. `009_create_menus_table.php` — Navigation menus and custom hierarchy links.
10. `010_create_meta_table.php` — Generic key-value meta storage for posts/users.
11. `011_create_redirects_table.php` — SEO 301/302 URL redirection table.
12. `012_create_plugin_settings_table.php` — Isolated plugin configuration table.
13. `013_create_seo_meta_table.php` — SEO titles, descriptions, and OpenGraph metadata.

### Migration Execution
During installation or update, the installer instantiates each migration class and executes its `up()` method idempotently using `CREATE TABLE IF NOT EXISTS`.
