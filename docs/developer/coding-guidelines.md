# Coding Guidelines & Standards

All core files, themes, and official plugins must follow these standards.

---

## 1. PHP Version & Strict Types

- The minimum supported PHP version is **PHP 8.1**.
- Every PHP file must declare strict types at the very top:
  ```php
  <?php

  declare(strict_types=1);
  ```

---

## 2. Coding Style (PSR-12)

- Follow **PSR-12** formatting rules.
- 4 spaces for indentation (never hard tabs).
- Braces on their own line for classes and methods.
- CamelCase for class names: `PluginManager`, `InstallerController`.
- camelCase for method and function names: `bootActivePlugins()`, `add_route()`.
- snake_case for database table and column names: `plugin_settings`, `created_at`.

---

## 3. Extension Independence & Boundary Enforcement

- **NEVER** modify Core source files (`app/`, `bootstrap.php`, `public/index.php`) to implement a plugin or theme.
- **NEVER** edit another plugin's files directly.
- Use documented public Core APIs, hooks, actions, filters, and dynamic routers.
- Always use prepared statements when interacting with the database.
