# Contributing to Favorite CMS Universal

Thank you for your interest in contributing to Favorite CMS Universal!

---

## 1. Ground Rules & Architectural Philosophy

1. **Standalone & Lightweight**: Favorite CMS Universal must remain self-contained. Do not introduce mandatory third-party heavy framework dependencies, background node services, or VPS-only daemons.
2. **Backward Compatibility**: Core APIs and public helper functions must remain stable.
3. **Extension Independence**: New business features belong in **Plugins**, not in the Core. Presentation rules belong in **Themes**.
4. **Never Modify Core to Build Extensions**: If an extension requires a new hook, router capability, or filter, propose an addition to the public extension API.

---

## 2. Coding Standards

- **PHP 8.1+**: Strict types declared on all files (`declare(strict_types=1);`).
- **PSR-12**: Format code according to PSR-12 conventions.
- **SQL Safety**: Always use parameterized PDO queries via `FavoriteCMS\Core\Database`.
- **Escaping**: Always escape view output using `htmlspecialchars()`.

---

## 3. Pull Request Process

1. Fork the repository and create your feature branch:
   ```bash
   git checkout -b feature/my-enhancement
   ```
2. Write unit/integration tests in `tests/` covering your changes.
3. Ensure all tests pass:
   ```bash
   composer test
   ```
4. Commit your changes with clear, descriptive messages.
5. Push to your fork and submit a Pull Request against `main`.

---

## 4. Reporting Security Vulnerabilities

If you discover a security vulnerability, please do **NOT** open a public issue. Instead, report it privately to the maintainers or via the security advisory tab on GitHub.
