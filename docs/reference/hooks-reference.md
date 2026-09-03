# Hooks & Events Reference

Exhaustive list of Action and Filter hooks available in Favorite CMS Universal.

---

## 1. Action Hooks (`add_action`)

| Tag | Fired In | Parameters | Description |
|-----|----------|------------|-------------|
| `init` | `Kernel::handle()` | `Application $app` | Fired on every request after active plugins are booted, before routing. |
| `plugins.loaded` | `PluginManager::bootActivePlugins()` | `array $pluginIds` | Fired when all active plugins have executed their entry scripts. |
| `plugin.activated` | `PluginManager::activatePlugin()` | `string $pluginId` | Fired immediately after an administrator activates a plugin. |
| `plugin.deactivated`| `PluginManager::deactivatePlugin()`| `string $pluginId` | Fired immediately after an administrator deactivates a plugin. |
| `plugin.uninstalled`| `PluginManager::uninstallPlugin()` | `string $pluginId` | Fired immediately before a plugin directory is removed from disk. |

---

## 2. Filter Hooks (`add_filter`)

| Tag | Fired In | Parameters | Return Expectation | Description |
|-----|----------|------------|--------------------|-------------|
| `template_include` | `Engine::render()` | `?string $path, string $template, array $data` | `string` (file path) | Allows extensions to override the resolved template file path. |
| `the_content` | View rendering | `string $content` | `string` (HTML) | Filters the body content of a post before output. |
| `the_title` | View rendering | `string $title` | `string` | Filters post or page headlines. |
