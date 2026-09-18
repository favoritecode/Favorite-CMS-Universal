# Contributing Guidelines

Thank you for your interest in contributing to **Favorite CMS Universal**!

---

## 1. Core Architectural Boundary

Before submitting a proposal or pull request, please review our core architectural philosophy:

- **Core**: Contains only universal functionality needed by any website (users, roles, posts, pages, media, settings, widgets).
- **Plugins**: Specialized features (such as ecommerce, streaming, booking engines, subscription paywalls, and social networks) belong in **Plugins**, not Core.
- **Themes**: Visual presentation, responsive layouts, and typography.
- **Widgets**: Modular content blocks positioned inside theme regions.

---

## 2. Code Standards & Style

- **PHP Version**: Must remain fully compatible with PHP 8.1+.
- **Strict Types**: All PHP files must declare `declare(strict_types=1);`.
- **Formatting**: Follow PSR-12 coding style conventions.
- **Documentation**: Write clear docblocks and preserve existing code comments.
- **Zero Framework Bloat**: Do not introduce heavy third-party framework packages or Node.js build dependencies into the Core runtime.

---

## 3. Pull Request Workflow

1. Fork the repository and create a descriptive feature branch:
   ```bash
   git checkout -b feature/my-enhancement
   ```
2. Make your changes and add corresponding tests in `tests/`.
3. Validate syntax across all modified PHP files:
   ```bash
   php -l path/to/changed/file.php
   ```
4. Run the full test suite:
   ```bash
   composer test
   ```
   **100% of tests must pass.**
5. Commit your changes with clear, semantic commit messages (e.g. `feat: ...`, `fix: ...`, `docs: ...`).
6. Push your branch and open a Pull Request against `main`.

