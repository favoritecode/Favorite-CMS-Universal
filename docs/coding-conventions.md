# Coding Conventions and Standards

This document establishes the official coding standards and engineering conventions for **Favorite CMS Universal v1.0.0**.

---

## 1. PHP Language Standards

- **Target Version:** PHP 8.1.0 or higher.
- **Strict Typing:** All PHP files must declare strict types at the very top:
  ```php
  <?php

  declare(strict_types=1);
  ```
- **Code Style:** Strictly follow the [PSR-12 Extended Coding Style Guide](https://www.php-fig.org/psr/psr-12/).
- **Indentation:** 4 spaces for indentation (no tabs in PHP source files).
- **Line Length:** Soft limit of 120 characters per line.

---

## 2. Namespace & Autoloading (PSR-4)

- Core classes are mapped via PSR-4 in `composer.json`:
  ```json
  "autoload": {
      "psr-4": {
          "FavoriteCMS\\": "app/"
      }
  }
  ```
- File names must match the class name exactly: `app/Core/Router.php` defines `class Router` in `namespace FavoriteCMS\Core`.
- Plugin classes should reside in `FavoriteCMS\Plugins\` or their dedicated namespace under their plugin folder.

---

## 3. Naming Conventions

| Component | Convention | Example |
|---|---|---|
| **Class Names** | PascalCase | `DatabaseProvisioner`, `UploadCapabilityService` |
| **Method Names** | camelCase | `getActiveTheme()`, `bootActivePlugins()` |
| **Variable Names** | camelCase | `$sessionUser`, `$postLimit` |
| **Constants** | UPPER_SNAKE_CASE | `APP_VERSION`, `CMS_NAME` |
| **Database Tables** | snake_case (plural) | `posts`, `users`, `plugin_settings` |
| **Database Columns**| snake_case | `created_at`, `auth_version`, `author_id` |
| **Hook Names** | snake_case or dot.notation | `widgets_init`, `plugin.activated`, `wp_head` |
| **Theme / Plugin IDs** | kebab-case | `my-theme`, `demo-counter` |

---

## 4. Database Safety Rules

- **Zero SQL String Interpolation:** Never concatenate variables directly into SQL queries:
  ```php
  // BAD - Vulnerable to SQL Injection:
  $db->query("SELECT * FROM users WHERE email = '{$email}'");

  // GOOD - Parameterized Prepared Statement:
  $db->selectOne("SELECT * FROM users WHERE email = ?", [$email]);
  ```
- **Explicit Transactions:** Wrap multi-table modifications inside database transactions:
  ```php
  $db->beginTransaction();
  try {
      $db->insert(...);
      $db->update(...);
      $db->commit();
  } catch (\Throwable $e) {
      $db->rollback();
      throw $e;
  }
  ```

---

## 5. Security & Escaping Rules

- **Output Escaping:** Always escape dynamic values when rendering HTML:
  - Text: `esc_html($text)`
  - Attributes: `esc_attr($attr)`
  - URLs: `esc_url($url)`
- **CSRF Verification:** State-changing requests (`POST`, `PUT`, `DELETE`) must verify CSRF tokens via `csrf_verify()`.
- **Capability Verification:** Restrict sensitive operations using `current_user_can('capability_name')`.
- **Zero Secrets in Repository:** Never commit real credentials, production `.env` files, API keys, or private SSH keys.
