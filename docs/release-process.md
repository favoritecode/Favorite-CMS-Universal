# Release Process and Verification Guide

This document describes the official release procedure and cryptographic integrity checks for **Favorite CMS Universal**.

---

## Canonical Release Philosophy

1. **The Verified Release ZIP is Authoritative:**
   The official production release ZIP is pre-tested and verified prior to publication.
   - Core CMS code inside the production package is final and must not be altered.
   - Documentation, developer guides, and repository maintenance files live in the GitHub repository and are not inserted into the production ZIP.
2. **Byte-for-Byte Reproducibility:**
   The release asset attached to the official GitHub Release must remain byte-for-byte identical to the verified build.
3. **Ecosystem Decoupling:**
   Core releases never bundle standalone ecosystem plugins (*Favorite Multimedia*, *Favorite Web Tools*, *Favorite Shop*). Core provides the extension runtime so these plugins are installed independently.

---

## Release Verification Checklist

### 1. Cryptographic Checksum Calculation
Before publishing, compute and record the exact SHA-256 hash and file size of the production archive:

```powershell
Get-FileHash -Path "release\Favorite-CMS-Universal-v1.0.0.zip" -Algorithm SHA256
(Get-Item "release\Favorite-CMS-Universal-v1.0.0.zip").Length
```

### 2. Git Tagging
Create an annotated Git tag pointing directly to the final release commit:
```bash
git tag -a v1.0.0 -m "Favorite CMS Universal v1.0.0 official release"
git push origin v1.0.0
```

### 3. GitHub Release Creation
Publish the GitHub release pointing to the tag:
- **Title:** `Favorite CMS Universal v1.0.0`
- **Tag:** `v1.0.0`
- **Asset Attachment:** Attach the exact verified file `Favorite-CMS-Universal-v1.0.0.zip`.

### 4. Post-Release Download & Byte Check
After publishing:
1. Download the release ZIP asset back from GitHub.
2. Calculate its SHA-256 hash.
3. Verify that the downloaded hash matches the original local hash:
   ```
   Original Local SHA-256 == Downloaded GitHub Release SHA-256
   ```
4. If the checksums match, the release is formally verified. If they differ, the release must be immediately investigated.
