# Local XAMPP Operations Guide

Managing Favorite CMS on Windows with XAMPP.

---

## 1. Starting & Stopping Services

Always use the **XAMPP Control Panel**:
- **Start Apache**: Listens on Port 80 (and 443 for SSL).
- **Start MySQL**: Listens on Port 3306.

---

## 2. Testing Persistence Across Restarts

To verify that stopping or rebooting your machine does not trigger the installer:

1. Create a post in `/admin/posts/new` and publish it.
2. In the XAMPP Control Panel, click **Stop** on Apache and **Stop** on MySQL.
3. Open Command Prompt / PowerShell and verify port 80 is released:
   ```powershell
   Get-NetTCPConnection -LocalPort 80 -ErrorAction SilentlyContinue
   ```
4. Click **Start** on Apache and MySQL.
5. Refresh `http://favorite-cms.local/`.
6. Your site loads immediately with your published post and settings intact. The installer will **never** reappear.
