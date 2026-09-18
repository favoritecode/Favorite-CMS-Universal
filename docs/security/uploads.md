# Media Upload Hardening & Defenses

File uploads represent one of the most critical attack vectors in web applications. Favorite CMS Universal implements a multi-layer defense strategy to ensure that media uploads cannot execute arbitrary code on the server.

---

## 1. Extension Whitelisting & Strict Blacklisting

### Allowed Extensions
Only safe, verified formats are permitted in the Media Library:
- **Images**: `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `bmp`, `ico`.
- **Videos**: `mp4`, `webm`, `mkv`, `mov`, `avi`, `ogv`.
- **Audio**: `mp3`, `wav`, `ogg`, `m4a`.
- **Documents**: `pdf`, `doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx`, `txt`, `csv`.
- **Archives**: `zip`, `tar`, `gz`.

### Strict Blacklist (Immediate Rejection)
The following extensions are strictly forbidden under all circumstances:
```php
'php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar',
'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'bash',
'exe', 'bat', 'cmd', 'com', 'vbs', 'dll', 'so',
'html', 'htm', 'xhtml', 'shtml', 'js'
```

---

## 2. Double Extension & Hidden Script Defense

Attackers often attempt to bypass naive filters by naming files with multiple extensions (e.g. `exploit.php.jpg` or `shell.phtml.png`).

`MediaService` inspects the entire file name:
```php
// Check for double extension containing blocked extensions
$parts = explode('.', strtolower($filename));
if (count($parts) > 2) {
    foreach (array_slice($parts, 1, -1) as $intermediateExt) {
        if (in_array($intermediateExt, $this->blockedExtensions, true)) {
            throw new SecurityException("Potential malicious multi-extension file detected.");
        }
    }
}
```

---

## 3. Binary MIME Verification (`finfo`)

Relying on the user's declared browser MIME type (`$_FILES['file']['type']`) is unsafe because it can be easily spoofed.

Favorite CMS validates the actual binary magic bytes of the file on disk using PHP's `finfo` (File Information) extension:
```php
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($tmpFilePath);
```
If the detected binary signature does not correspond to an allowed MIME category, the upload is rejected immediately.

---

## 4. Web Server Directory Hardening

The `public/uploads/` directory contains its own `.htaccess` file preventing any PHP script execution even if a file were somehow uploaded:

```apache
# Disable script execution in uploads directory
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# Strip script execution handlers
RemoveHandler .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh
```

