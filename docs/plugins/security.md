# Plugin Security Guidelines

Plugins extend the runtime environment and must adhere to strict security best practices.

---

## 1. Input Validation & Escaping

- **Input Validation**: Never trust raw `$_POST` or `$_GET`. Type-cast or validate format:
  ```php
  $id = (int)$request->query('id', 0);
  $email = filter_var($request->post('email'), FILTER_VALIDATE_EMAIL);
  ```
- **Output Escaping**: Always escape HTML in views:
  ```php
  echo htmlspecialchars($userSuppliedText, ENT_QUOTES, 'UTF-8');
  ```

---

## 2. CSRF Verification

Every administrative or frontend form submission must verify the session token:
```php
$submittedToken = $request->post('_token');
if (!hash_equals($_SESSION['_token'] ?? '', (string)$submittedToken)) {
    return \FavoriteCMS\Core\Response::make('Invalid CSRF token', 403);
}
```

---

## 3. Capability Enforcement

Never assume a visitor on `/admin/page/{slug}` is authorized:
```php
if (!current_user_can('manage_options')) {
    return \FavoriteCMS\Core\Response::make('Forbidden', 403);
}
```

---

## 4. SQL Parameterization

Never concatenate strings into queries. Always use PDO parameter binding via `Database::select()`, `insert()`, `update()`, or `delete()`.
