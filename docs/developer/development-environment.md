# Developer Environment Setup

This document outlines the recommended local development environment for contributing to Favorite CMS Universal or authoring extensions.

---

## 1. System Requirements

- **PHP CLI**: PHP 8.1+ (8.2 or 8.3 recommended) with extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `json`.
- **Composer**: Composer 2.x
- **Database**: Local MySQL 5.7+ or MariaDB 10.4+ (e.g. via XAMPP)
- **Editor**: Visual Studio Code, PhpStorm, or Sublime Text

---

## 2. Git Setup & Local Cloning

```bash
git clone https://github.com/favoritecode/Favorite-CMS-Universal.git
cd Favorite-CMS-Universal
composer install
cp .env.example .env
```

---

## 3. Running the Built-In PHP Development Server

If you prefer testing without configuring Apache/XAMPP virtual hosts, use PHP's built-in web server:

```bash
php -S 127.0.0.1:8000 -t public
```

Then browse to `http://127.0.0.1:8000`.

---

## 4. Running the Automated Test Suite

Run PHPUnit directly via Composer:

```bash
composer test
# or:
vendor/bin/phpunit
```
All tests must report **OK** before submitting changes.
