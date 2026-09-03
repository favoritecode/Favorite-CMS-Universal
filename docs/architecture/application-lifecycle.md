# Application Lifecycle

Understanding the exact sequence of execution from an incoming HTTP request to final response delivery.

---

## 1. Lifecycle Sequence

```
1. public/index.php
   └── Defines APP_ROOT, starts session, loads bootstrap.php

2. bootstrap.php
   ├── Loads Composer autoloader (vendor/autoload.php)
   ├── Loads global helper functions (app/Core/helpers.php)
   ├── Instantiates FavoriteCMS\Core\Application
   ├── Loads .env configuration via FavoriteCMS\Core\Config
   ├── Registers Core Singletons (Database, Router, Engine, etc.)
   └── Returns $app instance

3. FavoriteCMS\Core\Kernel::handle(Request $request)
   ├── Step A: Installation Check ($app->isInstalled())
   │   ├── If NOT installed: Dispatches InstallerController
   │   └── If installed & requesting /install: Redirects to /
   │
   ├── Step B: Active Plugins Boot (PluginManager::bootActivePlugins())
   │   ├── Scans active plugins registered in settings table
   │   ├── Requires each plugin's entry point inside try/catch isolation
   │   └── Fires Hook::doAction('plugins.loaded')
   │
   ├── Step C: Core Lifecycle Hook
   │   └── Fires Hook::doAction('init', $app)
   │
   └── Step D: Request Dispatching
       ├── 1. Admin Request (/admin/*)
       │      ├── Auth check & login routing
       │      ├── Core Admin Controllers (Posts, Pages, Media, etc.)
       │      └── Dynamic Plugin Admin Pages (/admin/page/{slug})
       │
       ├── 2. Static Asset Request (/themes/*, /plugins/*)
       │      └── Serves asset with MIME type and Cache-Control
       │
       ├── 3. Dynamic Plugin Route (Router::dispatch($request))
       │      └── If route matches, executes handler and returns Response
       │
       └── 4. Frontend Controller Routing
              ├── Homepage (/)
              ├── Single Post (/post/{slug})
              ├── Static Page (/{slug})
              ├── Category Archive (/category/{slug})
              ├── Tag Archive (/tag/{slug})
              ├── Search (/search?q=...)
              └── 404 Not Found (notFound())

4. Response Emission ($response->send())
   ├── Emits HTTP Status Code
   ├── Sends Security & Caching Headers
   └── Outputs Response Content
```

---

## 2. Key Lifecycle Extension Points

Developers can hook into these lifecycle moments:

| Event Tag | Trigger Timing | Arguments |
|-----------|----------------|-----------|
| `init` | After active plugins are loaded and before request dispatching | `Application $app` |
| `plugins.loaded` | Immediately after all active plugins have booted | `array $loadedPluginIds` |
| `plugin.activated` | When a plugin is activated by admin | `string $pluginId` |
| `plugin.deactivated`| When a plugin is deactivated | `string $pluginId` |
| `plugin.uninstalled`| When a plugin is deleted | `string $pluginId` |
| `template_include` | Filter hook during template resolution | `?string $path, string $template, array $data` |
| `the_content` | Filter hook for post body content | `string $content` |
