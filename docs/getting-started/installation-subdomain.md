# Subdomain Installation Guide

This guide describes how to install and configure **Favorite CMS Universal** on a subdomain (e.g. `blog.example.com`, `news.example.com`, or `cms.example.com`).

---

## 1. Subdomain DNS & Host Setup

1. **Create Subdomain in Hosting Panel**:
   - In cPanel, navigate to **Domains** &rarr; **Create A New Domain**.
   - Enter your desired subdomain: `blog.example.com`.
   - Set the **Document Root** to a dedicated folder, for example: `public_html/blog/` or `subdomains/blog/`.

2. **DNS Verification**:
   - Verify that an `A` record or `CNAME` for `blog` points to your hosting server's public IP.

3. **SSL Certificate**:
   - Issue a free Let's Encrypt / AutoSSL certificate for `blog.example.com` before or immediately after installation.

---

## 2. File Deployment

1. **Upload Distribution ZIP**:
   - Upload `Favorite-CMS-Universal.zip` directly into your subdomain's document root (`public_html/blog/`).
2. **Extract Files**:
   - Extract the archive so that `index.php`, `.htaccess`, `app/`, `config/`, `public/`, etc., are located in the subdomain root:
   ```
   public_html/blog/
   ├── app/
   ├── config/
   ├── database/
   ├── index.php
   ├── .htaccess
   ├── public/
   ├── resources/
   ├── storage/
   ├── themes/
   └── vendor/
   ```
3. **Verify File Permissions**:
   - Standard cPanel permissions: `0755` for directories, `0644` for files.
   - Ensure `storage/` and `public/uploads/` are writable by PHP.

---

## 3. Running the Subdomain Web Wizard

1. Open your web browser and visit your subdomain:
   ```text
   http://blog.example.com/
   ```
   *(or `https://blog.example.com/` if SSL is active)*.
2. The installer automatically recognizes that the site is running on a distinct host and sets the base URL correctly.
3. **Step 1 — Welcome & System Checks**:
   - Verify that all PHP extensions show `pass` and writable directories show `pass`.
4. **Step 2 — Database Setup**:
   - Enter your MySQL Database Name, Database Username, and Password.
   - Set a table prefix (default: `fcms_`).
5. **Step 3 — Site Information**:
   - Enter your Site Title and verify the detected Site URL (`https://blog.example.com/`).
   - Create your Administrator Username, Email, and Password.
6. **Step 4 — Install**:
   - Click **Install Favorite CMS**. The schema migrations execute, the admin user is created, and the installation lock is placed.

---

## 4. Subdomain Specific Considerations

- **Shared Cookies**: By default, session cookies use `samesite=Lax` and isolate sessions to the subdomain host, preventing session collision with the main domain (`example.com`).
- **Media URLs**: Uploaded media files are served from `https://blog.example.com/uploads/YYYY/MM/filename.ext`.
- **Moving to a Main Domain Later**: If you later decide to migrate the subdomain to the apex domain (`example.com`), simply move the files and update the `site_url` setting in **Admin &rarr; Settings &rarr; General**.

