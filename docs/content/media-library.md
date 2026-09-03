# Media Library & Role-Aware Large File Uploads

Favorite CMS features a robust, role-aware media management system engineered to support rich multimedia including large videos, audio podcasts, documents, and images.

---

## 🚀 Role-Aware Upload Limits

Upload limits are calculated dynamically based on both user role permissions and the hosting server's real technical bottlenecks:

1. **Server Bottleneck Detection**:
   - The CMS inspects `upload_max_filesize`, `post_max_size`, and `memory_limit`.
   - The effective server ceiling is `min(upload_max_filesize, post_max_size)`.
   - Early `post_max_size` overflow detection returns a friendly HTTP 413 response instead of silent POST data loss.

2. **Administrators (`super-admin` & `admin`)**:
   - Automatically granted the maximum practical limit allowed by the server environment (or configured custom admin cap).
   - Allows uploading large movies, episodes, high-resolution media, and ZIP archives up to server limits.

3. **Standard Users**:
   - Governed by configurable limit settings (default 50 MB), strictly capped by server configuration.
   - Configurable from **Admin Panel &rarr; Settings &rarr; Media & Upload Capabilities**.

---

## 📂 Supported Formats & MIME Categories

- **Videos**: MP4 (`video/mp4`), WebM (`video/webm`), MKV (`video/x-matroska`), QuickTime (`video/quicktime`), AVI (`video/x-msvideo`), OGV (`video/ogg`).
- **Audio**: MP3 (`audio/mpeg`, `audio/mp3`), WAV (`audio/wav`), OGG (`audio/ogg`), M4A (`audio/mp4`, `audio/x-m4a`).
- **Images**: JPEG, PNG, GIF, WebP, SVG, BMP, ICO.
- **Documents**: PDF, Word (DOC, DOCX), Excel (XLS, XLSX), PowerPoint (PPT, PPTX), Plain Text (TXT), CSV.
- **Archives**: ZIP, TAR, GZ.

---

## 🔒 Security Protections

- **Multi-Extension Defense**: Files containing hidden executable extensions (e.g. `exploit.php.png` or `file.phtml.jpg`) are strictly rejected.
- **Direct Script Prohibition**: Executable extensions (`.php`, `.phtml`, `.exe`, `.sh`, `.pl`, `.cgi`, etc.) cannot be uploaded.
- **Server-Side MIME Verification**: Uses PHP `finfo` to verify real file contents on disk.
- **Low-Memory Streaming**: Uses streaming low-memory transfer (`move_uploaded_file()`) rather than buffering large video files into PHP memory.

---

## ⚡ Direct Upload & Modal Integration

- **Drag-and-Drop**: Drop files anywhere on the upload zone.
- **Real-Time Progress Bar**: Track large file uploads in real time using `XMLHttpRequest.upload.onprogress`.
- **In-Editor Insertion**: When writing posts, click **Add Media** to browse the library or upload files directly without navigating away from your draft.

