# Production Release & Packaging Process

This document describes the automated and manual verification procedures for packaging production release distributions (`Favorite-CMS-Universal.zip`) of **Favorite CMS Universal**.

---

## 1. Production Package Integrity Rules

A production release archive must provide a zero-configuration, WordPress-like experience for end users on shared hosting:
- **Single Root Structure**: Unzipping the archive places `app/`, `config/`, `public/`, etc., directly in the target directory without an extra nested parent folder.
- **Pre-Bundled Dependencies**: The `vendor/` directory must be included with pre-optimized autoloaders so that end users never need Composer or terminal access.
- **Strict Exclusions**: The production archive must NEVER contain:
  - `.git/` or `.github/`
  - `tests/` or `phpunit.xml`
  - `.env` or local database configurations
  - `storage/installed.lock`, logs, cache, or session dumps
  - Development tools, temporary scripts, or local scratch files

---

## 2. Release Steps

1. **Code & Syntax Verification**:
   - Run PHP syntax check (`php -l`) across all PHP files.
   - Run the complete PHPUnit test suite (`composer test`).
2. **Version Consistency**:
   - Verify that `composer.json`, `README.md`, and documentation indices reference the target release version (e.g. `1.0.0-beta`).
3. **Packaging**:
   - Build `Favorite-CMS-Universal.zip` from the clean working tree of `main`.
4. **Independent Sandbox Validation**:
   - Extract the generated ZIP into a clean, empty directory.
   - Verify absence of forbidden files (`.git`, `.env`, `installed.lock`, `tests/`).
   - Complete a live browser installation test to verify that the fresh installer executes cleanly and writes `storage/installed.lock` upon completion.
5. **Git Push**:
   - Commit all documentation and release preparation changes to `main`.
   - Push to `origin/main` without force pushing.
6. **Publishing GitHub Release**:
   - Attach the validated `Favorite-CMS-Universal.zip` to the GitHub Release.

