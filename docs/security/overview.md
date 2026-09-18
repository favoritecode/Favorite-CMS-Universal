# Security Overview & Guidelines

Favorite CMS Universal treats security as an essential engineering constraint. Security mechanisms are integrated across every tier—authentication, session lifecycle, media ingestion, database abstraction, and administrative access.

---

## 1. Multi-Tier Security Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ 1. TRANSPORT & REQUEST SHIELD                               │
│    HTTPS detection, .htaccess rewrite, CSRF token validation│
├─────────────────────────────────────────────────────────────┤
│ 2. AUTHENTICATION & SESSION CONTROL                         │
│    password_hash() (bcrypt/argon2), HttpOnly & SameSite Lax,│
│    Instant session termination on banned accounts           │
├─────────────────────────────────────────────────────────────┤
│ 3. AUTHORIZATION & CAPABILITY GUARDS                        │
│    Role-based permissions, forced server-side post pending  │
│    review, strict 403 enforcement on admin settings         │
├─────────────────────────────────────────────────────────────┤
│ 4. INPUT SANITIZATION & OUTPUT ESCAPING                     │
│    ContentSanitizer HTML filtering, htmlspecialchars()      │
├─────────────────────────────────────────────────────────────┤
│ 5. MEDIA UPLOAD HARDENING                                   │
│    Extension whitelist, finfo binary MIME checking, double  │
│    extension blocks, disabled PHP execution in /uploads     │
├─────────────────────────────────────────────────────────────┤
│ 6. DATABASE LAYER                                           │
│    100% Parameterized PDO prepared statements               │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Realistic Security Expectations

Favorite CMS Universal does not make unrealistic claims such as "100% impenetrable" or "guaranteed never to fail." Rather, the CMS is:
- **Designed for** strict least-privilege operation.
- **Tested by** automated integration suites covering direct tampering, CSRF validation, and authorization bypasses.
- **Hardened using** industry-standard PHP and web server security practices.

Review the sub-documents in this directory for detailed technical specifications:
- [Authentication](authentication.md)
- [Authorization & Roles](authorization.md)
- [Upload Hardening](uploads.md)
- [Content Sanitization](content-sanitization.md)
- [Deployment Security](deployment-security.md)

