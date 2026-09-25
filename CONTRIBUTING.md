# Contributing to Favorite CMS Universal

Thank you for your interest in contributing to Favorite CMS Universal! This project thrives on community feedback, bug reports, and quality code contributions.

---

## Code of Conduct & Contribution Principles

1. **Keep Core Clean & Focused:** Favorite CMS Universal is designed as a lightweight, dependable CMS core. Domain-specific features (ecommerce, forums, audio streaming) belong in standalone plugins, not in the core application.
2. **Backward Compatibility:** Changes to core models, database tables, or public interfaces (`Hook`, `Router`, `AdminMenu`, `ThemeLayoutService`) must maintain backward compatibility for existing themes and plugins.
3. **No Unrelated Changes:** Keep pull requests focused on a single bug fix, optimization, or feature. Avoid large refactors or formatting changes across unrelated files.
4. **No Secrets or Runtime Artifacts:** Never commit `.env` files, production credentials, API keys, session files, logs, or installation lockfiles (`storage/installed.lock`).

---

## Getting Started

1. **Fork the Repository:** Create a personal fork of [favoritecode/Favorite-CMS-Universal](https://github.com/favoritecode/Favorite-CMS-Universal) on GitHub.
2. **Clone Locally:**
   ```bash
   git clone https://github.com/<your-username>/Favorite-CMS-Universal.git
   cd Favorite-CMS-Universal
   ```
3. **Create a Feature Branch:**
   ```bash
   git checkout -b fix/issue-description
   # or
   git checkout -b feature/feature-name
   ```
4. **Local Environment:**
   Run Favorite CMS Universal using your local web server (XAMPP, WampServer, Laragon) or PHP's built-in server:
   ```bash
   php -S localhost:8000
   ```

---

## Coding Standards & Conventions

- **PHP Version:** Target PHP 8.1+ syntax (`declare(strict_types=1);`, typed properties, union types).
- **Standards:** Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards and [PSR-4](https://www.php-fig.org/psr/psr-4/) autoloading (`FavoriteCMS\` namespace mapped to `app/`).
- **Database Safety:** Always use prepared statements via `$db->query($sql, $params)`, `$db->select($sql, $params)`, or `$db->selectOne($sql, $params)`. Never interpolate variables into SQL strings.
- **Output Escaping:** Always sanitize untrusted output in views using `esc_html()`, `esc_attr()`, or `esc_url()`.
- **CSRF Tokens:** All state-changing forms (`POST`, `PUT`, `DELETE`) must include `csrf_field()`.

---

## Submitting a Pull Request (PR)

1. Ensure your branch contains only changes related to the specific issue.
2. Verify that existing features and installer flows function properly without regressions.
3. Commit your changes with clear, descriptive commit messages:
   ```bash
   git commit -m "Fix post editor draft recovery on session timeout"
   ```
4. Push to your fork:
   ```bash
   git push origin fix/issue-description
   ```
5. Open a Pull Request against the `main` branch of `favoritecode/Favorite-CMS-Universal`.
6. Provide a clear summary in your PR description:
   - What bug is resolved or what feature is introduced
   - How the change was tested
   - Confirmation that no credentials or generated files are included
