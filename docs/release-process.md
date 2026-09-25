# Release Process and Verification Guide

This document defines the official release procedures, cryptographic integrity protocols, and release boundaries for **Favorite CMS Universal**.

---

## 1. Permanent Release Boundary (Core vs. Non-Core)

> [!IMPORTANT]
> **Only Favorite CMS CORE releases are permitted in this repository.**  
> Never mix core and non-core distribution pipelines.

### Core Release Flow:
```
Master Core Source (main)
   │
   ▼
Intentional Core Code Change & Verification
   │
   ▼
Version Increment (Semantic Versioning)
   │
   ▼
Clean Public Installer ZIP Packaging
   │
   ▼
Cryptographic SHA-256 Checksum Calculation
   │
   ▼
Immutable Git Tag (e.g. v1.0.1)
   │
   ▼
Favorite-CMS-Universal GitHub Release (Publish Release Asset)
```

### Non-Core Release Flow:
```
Standalone Plugin / Standalone Theme / External Product / Asset Pack
   │
   ▼
Redirect to Dedicated Distribution Channel:
   • Favorite-CMS-Assets (https://github.com/favoritecode/Favorite-CMS-Assets)
   OR
   • Dedicated Product Repository (Favorite-Multimedia, Favorite-Web-Tools, Favorite-Shop)
   │
   ▼
NEVER Publish in Favorite-CMS-Universal
```

---

## 2. Canonical Release Philosophy

1. **The Verified Release ZIP is Authoritative:**  
   The official production release ZIP is pre-tested and verified prior to publication.
   - Core CMS code inside the production package is final and must not be altered.
   - Documentation, developer guides, tests, and repository maintenance files live in the master repository and are intentionally excluded from the production installer ZIP.
2. **Byte-for-Byte Reproducibility:**  
   The release asset attached to the official GitHub Release must remain byte-for-byte identical to the verified build.
3. **Master Repository ≠ Public Installer ZIP:**  
   A developer or automation tool must never delete legitimate development files (such as automated tests or governance documentation) from the master repository merely to keep the installer ZIP clean.
4. **Historical Tag & Release Immutability:**  
   Existing published tags and release assets (including `v1.0.0`) are permanently immutable. Never move, overwrite, or rebuild released tags.

---

## 3. Official Core Release Checklist

### Step 1: Pre-Release Verification
- Run automated unit and integration tests: `composer test` or `php vendor/phpunit/phpunit/phpunit`.
- Run repository governance audit: `php scripts/check-repository-governance.php`.
- Ensure no untracked local junk, credentials (`.env`), or non-core plugins exist.

### Step 2: Build Clean Production ZIP
- Assemble core application files (`app/`, `config/`, `database/`, `plugins/`, `public/`, `resources/`, `storage/`, `themes/`, `vendor/`, `bootstrap.php`, `index.php`, `migrate.php`, `LICENSE`, `release.json`).
- Ensure no development-only tools, test directories, or documentation files are bundled into the production installer ZIP.

### Step 3: Compute Cryptographic Checksums
Compute and record the exact SHA-256 hash and file size of the production archive:
```powershell
Get-FileHash -Path "release\Favorite-CMS-Universal-vX.X.X.zip" -Algorithm SHA256
(Get-Item "release\Favorite-CMS-Universal-vX.X.X.zip").Length
```

### Step 4: Create and Push Git Tag
Create an annotated tag pointing to the release commit:
```bash
git tag -a vX.X.X -m "Favorite CMS Universal vX.X.X official release"
git push origin vX.X.X
```

### Step 5: Publish GitHub Release & Attach Asset
- Title: `Favorite CMS Universal vX.X.X`
- Tag: `vX.X.X`
- Body: Comprehensive release notes and SHA-256 checksum.
- Asset: Upload the clean production ZIP (raw binary stream).

### Step 6: Post-Release Download & Byte-Check
After publishing:
1. Download the release asset back from GitHub.
2. Calculate its SHA-256 hash.
3. Verify that the downloaded hash matches the original local hash:
   ```
   Original Local SHA-256 == Downloaded GitHub Release SHA-256
   ```
4. If the checksums match, the release is formally verified. If they differ, investigate immediately.
