# Security Policy

Favorite CMS Universal treats security as an essential engineering priority. This document outlines the security architecture of Favorite CMS Universal v1.0.0 and instructions for reporting vulnerabilities responsibly.

---

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0.0 | :x:                |

---

## Built-In Security Architecture

Favorite CMS Universal implements multiple layers of defense to secure web installations out of the box:

### 1. Database Parameterization (SQL Injection Defense)
- All database queries across models, services, and core controllers use **PDO Prepared Statements** with strict parameter binding.
- Raw query concatenation with untrusted user input is strictly prohibited across the codebase.

### 2. Cross-Site Request Forgery (CSRF) Protection
- State-changing HTTP requests (`POST`, `PUT`, `DELETE`) require a valid cryptographic CSRF token.
- Tokens are verified using timing-attack resistant `hash_equals()` comparison via `csrf_verify()` and the core Kernel middleware.
- Forms render CSRF tokens via `csrf_field()` or provide headers via `X-CSRF-Token`.

### 3. Cross-Site Scripting (XSS) & Content Sanitization
- Public outputs are escaped using UTF-8 context-aware escaping functions: `esc_html()`, `esc_attr()`, and `esc_url()`.
- Post and page content submitted by non-administrator users is sanitized using bundled **HTMLPurifier 4.19.0**, removing dangerous tags, malicious attributes, `javascript:` pseudoprotocols, and malicious inline events.
- Raw HTML editing is restricted to users holding the `unfiltered_html` capability (Administrators and Super Admins).

### 4. Password Security & Authentication
- User passwords are encrypted using PHP's native `password_hash()` algorithm utilizing `PASSWORD_DEFAULT` (bcrypt / Argon2).
- Plaintext passwords are never stored in memory longer than necessary and are automatically omitted from model serialization (`User::toArray()`).
- Session hijacking protections include `auth_version` tracking: changing a password or updating roles increments the account version, instantly terminating all other active sessions for that user.

### 5. Media Upload Hardening
- File uploads are validated through MIME-type checking, extension whitelisting, and multi-extension shielding (e.g. blocking `.php.jpg`, `.phtml`, executable binaries).
- Upload directories (`public/uploads`) have script execution disabled via Apache `.htaccess` directives preventing uploaded files from executing as server scripts.

### 6. Session Security
- Sessions are configured with `cookie_httponly = true` and `cookie_samesite = Lax` (and `cookie_secure` enabled automatically over HTTPS).
- Session identifiers are regenerated upon privilege elevation or successful login to eliminate session fixation vulnerabilities.

---

## Reporting a Vulnerability

If you discover a security vulnerability in Favorite CMS Universal, please report it responsibly:

1. **Do not create a public GitHub issue.**
2. Send an email to the security team at: **security@favoriteweb.net**
3. Include detailed information to assist reproduction:
   - Type of vulnerability (e.g., SQLi, XSS, CSRF, RCE, IDOR)
   - Step-by-step instructions or proof-of-concept payload
   - Impact assessment
   - Target version and server environment details

### Response Timeline
- **Acknowledgement:** Within 48 hours of initial report.
- **Triage & Assessment:** Within 5 business days.
- **Fix & Advisory:** A patch will be published with the next maintenance release alongside appropriate credit to the reporter.

Thank you for helping keep Favorite CMS Universal and its users secure!
