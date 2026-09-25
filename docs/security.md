# Security Architecture and Best Practices

This document provides a detailed technical overview of the security architecture and defensive mechanisms implemented in **Favorite CMS Universal v1.0.0**.

---

## 1. Authentication & Session Security

### Password Hashing
- User passwords are processed using PHP's native `password_hash()` API utilizing `PASSWORD_DEFAULT` (bcrypt with configurable cost or Argon2 depending on PHP compilation).
- Passwords are automatically excluded from model serialization (`User::toArray()`) to prevent accidental leaks in logs or API responses.

### Session Versioning (`auth_version`)
- Standard PHP session identifiers can remain valid on other devices even after a password is changed unless explicitly invalidated.
- Favorite CMS Universal incorporates an `auth_version` column in the `users` table:
  - When an account is suspended or banned, `auth_version` is incremented.
  - When an administrator changes a user's role or resets their password, `auth_version` is incremented.
  - The core `Kernel` validates `$_SESSION['auth_version']` against the database on every request. If a mismatch is detected, the session is instantly destroyed and the user is redirected to `/admin/login`.

### Session Hardening Directives
- **`session.cookie_httponly = 1`**: Prevents client-side JavaScript from reading the session cookie, eliminating session theft via XSS.
- **`session.cookie_samesite = Lax`**: Mitigates Cross-Site Request Forgery risks across third-party site requests.
- **`session.use_strict_mode = 1`**: Prevents session fixation attacks by rejecting uninitialized session IDs.
- **Session ID Regeneration:** Calls `session_regenerate_id(true)` upon successful user authentication.

---

## 2. Authorization & Access Control

- Every administrative route is guarded by capability checks (`current_user_can($capability)`).
- Role inheritance is verified against the database role matrix.
- Even if an author knows direct URL endpoints (e.g. `/admin/settings` or `/admin/users`), the controller verifies the user's role and capabilities before rendering or processing actions, returning a `403 Forbidden` error if unauthorized.

---

## 3. Cross-Site Request Forgery (CSRF) Protection

- All state-altering requests (`POST`, `PUT`, `DELETE`) require a valid cryptographic token.
- Tokens are generated per session and verified using timing-attack resistant `hash_equals()`:
  ```php
  if (!hash_equals($_SESSION['_csrf_token'], $submittedToken)) {
      throw new SecurityException("CSRF token validation failed.", 403);
  }
  ```
- Forms provide tokens via `csrf_field()`:
  ```html
  <input type="hidden" name="_csrf_token" value="...">
  ```
- AJAX requests can pass the token via the `X-CSRF-Token` HTTP header.

---

## 4. SQL Injection Protection

- All core models, controllers, and services interact with MySQL through the `Database` class using **PDO Prepared Statements**.
- Example of standard parameterized query execution:
  ```php
  $posts = $db->select(
      "SELECT * FROM `posts` WHERE `status` = ? AND `author_id` = ?",
      ['published', $userId]
  );
  ```
- Dynamic parameters are never directly concatenated into SQL strings.

---

## 5. Cross-Site Scripting (XSS) & HTMLPurifier

### Output Escaping
Templates must escape dynamic data using UTF-8 context-aware escaping helpers:
- `esc_html($text)`: Escapes content rendered inside HTML body elements (`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE`).
- `esc_attr($text)`: Escapes content rendered inside HTML tag attributes.
- `esc_url($url)`: Validates URLs and disallows dangerous pseudoprotocols like `javascript:`.

### Bundled HTMLPurifier 4.19.0
- Content submitted in Visual Mode or Code Mode by users without the `unfiltered_html` capability is sanitized through HTMLPurifier.
- Strips unauthorized `<iframe>`, `<object>`, `<embed>`, `<script>`, and inline `onload`/`onerror` handlers.
- Preserves authorized custom code blocks inserted by administrators.

---

## 6. Media Upload Hardening

- **Executable Blocking:** Denies upload of files containing `.php`, `.phtml`, `.exe`, `.cgi`, or hidden double extensions (`.php.jpg`).
- **MIME Inspection:** Validates binary headers using PHP's native `fileinfo` rather than trusting the user's file extension.
- **Upload Directory Hardening:** `.htaccess` file inside `public/uploads` disables the PHP engine, preventing script execution even if an unauthorized script were uploaded.

---

## 7. Responsible Vulnerability Disclosure

If you identify a security issue, please do not disclose it publicly. Contact the security team at **security@favoriteweb.net** with reproduction steps.
