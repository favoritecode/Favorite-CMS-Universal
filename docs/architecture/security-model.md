# Security Architecture & Threat Model

This document outlines the security architecture, defenses, and vulnerability prevention strategies implemented across **Favorite CMS Universal**.

---

## 1. Core Security Principles

Favorite CMS Universal adopts a defense-in-depth approach:
- **Never Trust Client Input**: All incoming parameters from GET, POST, cookies, and file uploads are validated, typed, and sanitized.
- **Fail Closed**: If permissions or credentials cannot be conclusively verified, access is denied (HTTP 403 Forbidden or 401 Unauthorized).
- **Least Privilege**: Users and roles are granted only the minimum necessary capabilities required for their function.
- **No Direct Execution**: User-uploaded media directories strictly forbid direct PHP script execution.

---

## 2. Threat Mitigations & Controls

### 1. SQL Injection (SQLi)
- **Mitigation**: 100% of database queries execute via parameterized PDO prepared statements through `FavoriteCMS\Core\Database`.
- **Query Structure**: Data parameters are never directly concatenated into SQL strings.
  ```php
  // Safe parameterized pattern:
  $user = $db->selectOne("SELECT * FROM users WHERE username = ? AND status = ?", [$username, 'active']);
  ```

### 2. Cross-Site Scripting (XSS)
- **Input Sanitization**: Content submitted via the post editor is processed through `ContentSanitizer`, which strips unauthorized JavaScript, `on*` event attributes, `javascript:` pseudoprotocols, and malicious tags.
- **Output Escaping**: View templates consistently escape variables using `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.

### 3. Cross-Site Request Forgery (CSRF)
- **Cryptographic Tokens**: State-changing forms (login, signup, post creation, settings, role updates) include a cryptographically secure 256-bit token (`bin2hex(random_bytes(32))`).
- **Timing Attack Resistance**: Submitted tokens are validated against the session using PHP's constant-time comparison function `hash_equals()`.
- **Automatic Rotation**: Tokens are rotated on security-sensitive actions and session renewal.

### 4. Malicious File Uploads & Web Shells
The media subsystem enforces strict multi-layered upload defense:
1. **Extension Whitelist**: Only explicitly permitted extensions (e.g. `.jpg`, `.mp4`, `.pdf`) are accepted.
2. **Strict Blacklist**: Executable and script extensions (`.php`, `.phtml`, `.php5`, `.phar`, `.pl`, `.py`, `.cgi`, `.sh`, `.exe`, `.js`) are strictly forbidden.
3. **Double-Extension Shield**: Files named `image.php.jpg` or `shell.phtml.png` are detected and blocked.
4. **MIME Signature Verification**: PHP's `finfo` validates the actual binary magic bytes on disk.
5. **Apache Webroot Protection**: The `public/uploads/` directory contains an `.htaccess` rule that disables PHP execution (`php_flag engine off` and `RemoveHandler .php`).

### 5. Authentication & Password Security
- **Modern Hashing**: Passwords are never stored in plaintext. They are encrypted using `password_hash()` with `PASSWORD_DEFAULT` (bcrypt or Argon2).
- **Session Security**: Session cookies are configured with `httponly=true` (inaccessible to JavaScript) and `samesite=Lax` (protects against cross-origin leaking).
- **Ban Invalidation**: Banned accounts have their active sessions terminated immediately upon their very next request.

### 6. Role Tampering & Privilege Escalation
- **Server-Side Enforcement**: Role privileges are verified exclusively on the server.
- **Post Approval Guard**: If a standard user (`subscriber`) attempts to bypass moderation by sending `status=published`, `PostController` overrides the field to `pending`.
- **Admin-Only Guards**: Administrative controllers (`/admin/settings`, `/admin/themes`, `/admin/plugins`, `/admin/users`) enforce `current_user_can('manage_options')` / `canManageUsers()`, returning HTTP 403 to unauthorized users.

