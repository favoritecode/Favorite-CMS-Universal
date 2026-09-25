# Disaster Recovery & Development Workspace Restoration

This guide outlines the official procedure to completely restore the **Favorite CMS Universal CORE** development environment in the event of local hardware failure, accidental file deletion, or developer onboarding on a new machine.

---

## What Git Restores vs. What Requires Separate Backups

> [!IMPORTANT]
> **Git restores source code.**  
> Git does **NOT** restore:
> - Private `.env` values and database credentials
> - Real passwords and encryption keys
> - Production database records, articles, and user tables
> - User-uploaded media (`public/uploads/`)
> - External storage data or backup archives
> - Runtime active sessions (`storage/sessions/`)
> - Server-specific secrets or SSL certificates
>
> Operational data (database tables and user uploads) must be restored from full-site backup archives created via the Backup Subsystem (`/admin/tools` or `storage/backups/`).

---

## Step-by-Step Restoration Procedure

### Step 1: Obtain the Master Core Repository
Clone the official master repository:
```bash
git clone https://github.com/favoritecode/Favorite-CMS-Universal.git
cd Favorite-CMS-Universal
```
*Alternatively, download the source archive from GitHub:*  
Visit `https://github.com/favoritecode/Favorite-CMS-Universal` &rarr; Click **Code** &rarr; **Download ZIP**, then extract the contents into your target development folder.

---

### Step 2: Initialize Local Environment Configuration
Copy the template configuration file:
```bash
cp .env.example .env
```
Open `.env` in an editor and specify your local developer environment parameters:
```ini
APP_NAME="Favorite CMS Development"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=favorite_cms_dev
DB_USERNAME=root
DB_PASSWORD=
DB_PREFIX=fc_
```

---

### Step 3: Restore Dependencies (Developer Tools)
Favorite CMS Universal bundles its production vendor runtime (`HTMLPurifier` and class loaders) out of the box.  
To run PHPUnit automated test suites locally, install dev dependencies via Composer:
```bash
composer install
```

---

### Step 4: Configure Local Database
Create an empty MySQL or MariaDB database on your local server:
```sql
CREATE DATABASE `favorite_cms_dev` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

If restoring from an existing database backup dump (`database.sql`):
```bash
mysql -u root -p favorite_cms_dev < path/to/database.sql
```
If starting a clean development instance, run the core schema migrations:
```bash
php migrate.php
```

---

### Step 5: Configure Local Web Server
Ensure your local web server points to the project root:
- **Local PHP Server:**
  ```bash
  php -S localhost:8000
  ```
- **XAMPP / WampServer / Laragon:**  
  Place the directory inside `htdocs/` or `www/` and ensure Apache `mod_rewrite` is active.

---

### Step 6: Restore User Media Uploads (If Available)
If you have an archive of user media, copy the contents into:
```
public/uploads/
```
Ensure directory write permissions are maintained on `storage/` and `public/uploads/`.

---

### Step 7: Run Automated Tests to Verify Integrity
Execute the PHPUnit test suite to verify that your restored development environment functions properly:
```bash
php vendor/phpunit/phpunit/phpunit
# or
composer test
```

You are now fully restored and ready to continue development, testing, and contribution!
