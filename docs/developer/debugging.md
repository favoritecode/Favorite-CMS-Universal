# Debugging & Diagnostics

Techniques and tools for diagnosing issues during development.

---

## 1. Application Log Files

The primary diagnostic log is located at:
```
storage/logs/favorite_cms.log
```

Log lines follow a structured format:
```
[2026-09-03 15:30:12] [INFO] Plugin activated: hello-favorite
[2026-09-03 15:32:45] [ERROR] Failed to boot plugin 'broken-plugin': Class not found
```

You can tail the log in PowerShell:
```powershell
Get-Content -Path storage/logs/favorite_cms.log -Wait -Tail 30
```

---

## 2. Debug Mode in `.env`

Enable full stack traces and verbose error reporting in local development:
```env
APP_DEBUG=true
```

In production, always set:
```env
APP_DEBUG=false
```
When `APP_DEBUG=false`, internal stack traces are suppressed, and friendly error templates are rendered to end visitors while full errors are recorded to the log.

---

## 3. Plugin Boot Diagnostics

To inspect plugins that failed to boot without crashing the site, inspect `PluginManager`:
```php
$pluginManager = new \FavoriteCMS\Plugins\PluginManager(app());
$errors = $pluginManager->getBootErrors();
// Returns: ['plugin-id' => 'Error message']
```
