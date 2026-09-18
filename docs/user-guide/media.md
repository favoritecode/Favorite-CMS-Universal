# Media Library & Large Media Uploads Guide

Favorite CMS Universal features a centralized, role-aware Media Library (`/admin/media`) capable of handling everything from blog thumbnails and product photography to high-definition video files, audio podcasts, and software archives.

---

## 1. Role-Based Upload Limits

To prevent server disk exhaustion while empowering administrative workflows, Favorite CMS applies role-based upload limits:

| Role | Configured CMS Maximum | Typical Use Cases |
|---|---|---|
| **Super Admin & Admin** | **7 GB** (7,168 MB) | Movies, documentary episodes, large zip archives, 4K video files |
| **Moderator & Editor** | **500 MB** | High-definition web series, audio podcasts, multi-page PDFs |
| **Normal User / Subscriber** | **200 MB** | High-resolution photography, short clips, audio voice notes, documents |

### Server Limits vs. CMS Configured Limits
The CMS configured limit sets the maximum allowed by software policy. However, file uploads must also comply with physical server configuration.

The **Effective Upload Limit** is determined dynamically by:
```
Effective Limit = min(
    Role Configured Limit,
    PHP upload_max_filesize,
    PHP post_max_size,
    Server Available Free Disk Space
)
```

The Media Library interface prominently displays both the **Configured Allowance** and the **Effective Server Ceiling** so you always know the exact maximum size your server will accept.

---

## 2. Uploading Media

1. Navigate to **Media** &rarr; **Add New Media** (or click the upload zone in `/admin/media`).
2. **Drag and Drop**: Drag files directly from your computer onto the upload dashed dropzone.
3. **Browse Files**: Or click **Select Files** to open your operating system file picker.
4. **Real-Time Progress Bar**: As the file streams to the server, a real-time percentage and byte counter track upload progress.
5. Upon completion, the file is automatically cataloged in the library and ready for immediate use.

---

## 3. Supported File Formats

- **Images**: JPEG (`.jpg`, `.jpeg`), PNG (`.png`), WebP (`.webp`), GIF (`.gif`), SVG (`.svg`), BMP (`.bmp`), ICO (`.ico`).
- **Videos**: MP4 (`.mp4`), WebM (`.webm`), Matroska (`.mkv`), QuickTime (`.mov`), AVI (`.avi`), OGG (`.ogv`).
- **Audio**: MP3 (`.mp3`), WAV (`.wav`), OGG (`.ogg`), M4A (`.m4a`).
- **Documents**: PDF (`.pdf`), Word (`.doc`, `.docx`), Excel (`.xls`, `.xlsx`), PowerPoint (`.ppt`, `.pptx`), Text (`.txt`), CSV (`.csv`).
- **Archives**: ZIP (`.zip`), TAR (`.tar`), GZ (`.gz`).

---

## 4. File Storage Structure

Uploaded files are stored systematically by year and month to avoid huge single-directory file counts:
```
public/uploads/
└── 2026/
    └── 09/
        ├── hero-banner.webp
        ├── podcast-ep12.mp3
        └── full-documentary.mp4
```

Public URLs are structured cleanly:
```text
http://example.com/uploads/2026/09/hero-banner.webp
```

---

## 5. Security & Upload Hardening

- **Executable Upload Blocking**: Files ending in `.php`, `.phtml`, `.exe`, `.bat`, `.sh`, `.cgi`, `.py`, or `.js` are strictly forbidden under all circumstances.
- **Double-Extension Protection**: Attack attempts such as `image.php.jpg` or `shell.phtml.png` are detected and rejected.
- **MIME-Type Verification**: Files are validated using PHP's `finfo` engine to verify that the file's binary signature matches its declared extension.
- **Path Traversal Defense**: File names are sanitized to prevent directory traversal attacks (`../`).

