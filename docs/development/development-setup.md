# Local Development Setup Guide

This guide details the process for setting up a local development environment for contributing to **Favorite CMS Universal** or building custom themes and plugins.

---

## 1. Prerequisites

- **PHP**: PHP 8.1+ CLI with extensions: `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`, `gd`.
- **Database**: MySQL 5.7+ or MariaDB 10.3+ (e.g. running via XAMPP, WAMP, or local service).
- **Composer**: Composer 2.x for dependency management and running test suites.
- **Git**: Git for version control.

---

## 2. Step-by-Step Repository Setup

1. **Clone the Repository**:
   ```bash
   git clone https://github.com/favoritecode/Favorite-CMS-Universal.git
   cd Favorite-CMS-Universal
   ```

2. **Install Development Dependencies**:
   ```bash
   composer install
   ```
   This installs PHPUnit 10 and generates the optimized PSR-4 classmap.

3. **Database Configuration**:
   Create a local MySQL database named `favorite_cms`:
   ```sql
   CREATE DATABASE IF NOT EXISTS `favorite_cms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. **Environment File (`.env`)**:
   Copy `.env.example` to `.env` (or let the web installer generate it):
   ```ini
   APP_NAME="Favorite CMS Universal"
   APP_ENV=local
   APP_KEY=base64:randomkeyhere
   APP_DEBUG=true
   APP_URL=http://favorite-cms.local

   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=favorite_cms
   DB_USERNAME=root
   DB_PASSWORD=
   DB_PREFIX=fcms_
   ```

5. **Run the PHPUnit Test Suite**:
   ```bash
   composer test
   ```
   Verify that all integration and unit tests pass.

