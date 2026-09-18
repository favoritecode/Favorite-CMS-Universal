# Packaging & Distribution for Plugins

How to package a Favorite CMS plugin for distribution and installation via the CMS Admin Panel.

---

## 1. ZIP File Requirements

When creating a distributable plugin ZIP archive:
1. The archive must contain the plugin files inside a single root directory named after the plugin identifier (e.g. `my-plugin/`).
2. `plugin.json` must be present at `my-plugin/plugin.json`.
3. The archive must **not** contain hidden `.git/`, `.DS_Store`, or temporary files.

### Recommended Packaging Command (PowerShell):
```powershell
Compress-Archive -Path "plugins/my-plugin" -DestinationPath "my-plugin.zip" -Force
```

### Linux/macOS:
```bash
zip -r my-plugin.zip my-plugin -x "*.git*"
```

---

## 2. Uploading via Admin Panel

1. Go to **Admin Dashboard &rarr; Plugins**.
2. Click **Add New / Upload Plugin**.
3. Choose your `my-plugin.zip` file.
4. Click **Install Now**.
5. The CMS validates the archive, extracts it safely, and makes it available for immediate activation.
