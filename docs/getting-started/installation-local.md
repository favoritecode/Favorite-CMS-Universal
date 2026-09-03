# Local XAMPP Installation

Favorite CMS can be tested locally with XAMPP using the same browser installer used on shared hosting.

## Requirements

- XAMPP with Apache and MySQL/MariaDB.
- PHP 8.1 or newer.
- Composer only when working from a development clone. Release ZIPs include the needed vendor files.

## Recommended Virtual Host

Point an Apache virtual host to the `public/` directory:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/favorite-cms/public"
    ServerName favorite-cms.local
    <Directory "C:/xampp/htdocs/favorite-cms/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add this to your hosts file:

```text
127.0.0.1 favorite-cms.local
```

Then visit:

```text
http://favorite-cms.local/
```

Direct subfolder testing also works, for example:

```text
http://localhost/favorite-cms/public/
```

The installer detects the subdirectory and generates matching routes.

## Database Options

### Automatic

For local XAMPP, automatic database creation usually works when you enter the local MySQL admin account, commonly:

```text
Host: localhost
Port: 3306
Admin user: root
Admin password: empty unless you configured one
```

The installer creates the target database, verifies the connection, writes `.env`, runs migrations, creates the admin user, and writes `storage/installed.lock`.

### Manual

You can also create the database in phpMyAdmin first and then enter those credentials in Manual Database Setup. Favorite CMS does not assume the `root` account unless you explicitly enter it.

## Persistence Check

After installation:

1. Stop Apache and MySQL in XAMPP.
2. Start them again.
3. Refresh the local site.

The CMS should load normally. It should not return to the installer because installation state is stored persistently in `storage/installed.lock` and can be recovered from a valid database installation if the lock is missing.
