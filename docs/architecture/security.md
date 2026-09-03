# Security Architecture

Security in Favorite CMS Universal is built into the foundation at every layer.

---

## 1. Cross-Site Request Forgery (CSRF) Protection

Every state-changing HTTP request (`POST`, `PUT`, `DELETE`) requires a valid CSRF token:

### Generating Tokens in Views:
```html
<input type="hidden" name="_token" value="<?php echo htmlspecialchars($_SESSION['_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
```

### Verification in Controllers:
```php
$token = $request->post('_token');
if (!hash_equals($_SESSION['_token'] ?? '', (string)$token)) {
    return Response::make('Invalid CSRF Token', 403);
}
```

---

## 2. SQL Injection Prevention

The database abstraction layer (`FavoriteCMS\Core\Database`) strictly enforces prepared statements with bound parameters:
```php
// SAFE: Parameterized
$db->select("SELECT * FROM users WHERE email = ?", [$email]);

// NEVER do raw string interpolation:
// $db->select("SELECT * FROM users WHERE email = '{$email}'"); // DISALLOWED
```

---

## 3. Cross-Site Scripting (XSS) Prevention

- Dynamic user-supplied text in templates must be passed through `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
- Rich HTML post content is processed through `clean_post_content()`, which strips malicious inline script attributes (`onclick`, `onload`), dangerous `javascript:` URI schemes, and disallows unauthorized tags.

---

## 4. Role-Based Access Control (RBAC) & Super-Admin

The CMS enforces user permissions via `User::hasPermission($slug)` and the helper `current_user_can($capability)`:
- Users with the `super-admin` role automatically possess all capabilities.
- Other roles (Editor, Author, Subscriber) are verified against granular role-permission mappings.

---

## 5. File Upload & Archive Security

- **MIME Verification**: Uploaded media checks `finfo_file()` to verify real binary MIME types, preventing renamed executable scripts (`.php.jpg`).
- **Zip-Slip Defense**: During plugin/theme zip extraction, every path entry is inspected. Files containing `..`, absolute paths `/`, or `\` are rejected immediately.
