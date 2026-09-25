# Media Management Guide

This guide details how the **Media Subsystem** operates in Favorite CMS Universal v1.0.0, including file handling, security hardening, and role-based upload capacities.

---

## Role-Aware Upload Limits

Favorite CMS Universal implements a tiered, role-based upload capacity architecture managed by `UploadCapabilityService`:

| User Role | Configured CMS Ceiling | Capability Flag Required |
|---|:---:|---|
| **Super Admin / Admin** | Up to **7 GB** (7,168 MB) | `upload_large_media` |
| **Moderator / Editor** | Up to **500 MB** | `upload_moderator_media` |
| **Author / Standard User** | Up to **200 MB** | `upload_media` |

### Server Capacity Awareness
The effective upload limit for any user is bounded by your hosting environment's `php.ini` directives:
- `upload_max_filesize`
- `post_max_size` (must be equal to or greater than `upload_max_filesize`)
- `memory_limit`

`UploadCapabilityService` automatically inspects the PHP environment and informs users in the media uploader of their **effective maximum file size**:
```
Effective Limit = Min(Role CMS Ceiling, upload_max_filesize, post_max_size)
```

To enable large uploads (e.g. video files), configure your server's `php.ini` or `.user.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
```

---

## Supported File Types

The Media Library supports safe web media assets:
- **Images:** `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.svg` (sanitized attribute filter)
- **Audio:** `.mp3`, `.wav`, `.ogg`, `.m4a`
- **Video:** `.mp4`, `.webm`, `.ogv`, `.mov`
- **Documents:** `.pdf`, `.doc`, `.docx`, `.xls`, `.xlsx`, `.ppt`, `.pptx`, `.txt`, `.csv`
- **Archives:** `.zip`, `.gz`, `.tar`

---

## Security Hardening & Shielding

To protect servers against remote code execution vulnerabilities, the media subsystem applies strict defense layers:

### 1. Multi-Extension & Executable Shield
- The uploader rejects any file containing executable extensions or deceptive double extensions, such as:
  - `.php`, `.php3`, `.php4`, `.php5`, `.phtml`, `.phps`
  - `.exe`, `.sh`, `.bat`, `.cmd`, `.pl`, `.cgi`, `.py`
  - Double extensions such as `image.php.jpg` or `document.pdf.phtml`
- Filenames are sanitized, stripped of path traversal characters (`../`), and given unique random hashes to prevent file overwrites.

### 2. MIME-Type Inspection
- Rather than trusting the browser's declared file extension, the backend uses PHP's native `fileinfo` (`finfo_file`) to inspect the file's binary magic bytes before accepting it.

### 3. Server-Level Execution Blocking
- The `public/uploads` directory contains a dedicated `.htaccess` file disabling PHP execution:
  ```apache
  # Prevent execution of any scripts in the uploads directory
  <FilesMatch "\.(php|phtml|php3|php4|php5|phps|pl|py|cgi)$">
      Order Deny,Allow
      Deny from all
  </FilesMatch>
  php_flag engine off
  ```

---

## Image Thumbnail Generation

Uploaded bitmap images (`.jpg`, `.png`, `.webp`) are processed automatically via PHP's `gd` library:
- **Thumbnail:** `150x150` square cropped (for dashboard grids).
- **Medium:** `300x300` proportional.
- **Large:** `1024x1024` proportional.
- Original high-resolution source files are retained for full-screen display.
