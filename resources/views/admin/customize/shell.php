<?php
/**
 * Master Fullscreen Customizer Shell — Favorite CMS Universal Core
 *
 * Provides a dedicated full-viewport visual editing application for /admin/customize.
 * Normal Admin sidebar and topbar are completely excluded.
 * Provides the generic Customizer contract, element registry, template APIs,
 * in-memory history (Undo/Redo), clipboard (Copy/Paste), keyboard shortcuts,
 * and audited same-origin preview protocol.
 */

declare(strict_types=1);

$themeId = $themeId ?? 'default';
$themeName = $themeName ?? ucfirst($themeId);
$adminTheme = $adminTheme ?? 'light';
$csrfToken = $csrfToken ?? csrf_token();
$pageTitle = $pageTitle ?? "Customize: {$themeName}";
$siteName = $siteName ?? 'Favorite CMS';
$siteFaviconUrl = $siteFaviconUrl ?? '';
$isThemeCustomizer = isset($contentView) && is_string($contentView) && file_exists($contentView) && $contentView !== APP_ROOT . '/resources/views/admin/customize/index.php';
?>
<!DOCTYPE html>
<html lang="en" data-admin-theme="<?php echo htmlspecialchars($adminTheme, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> &lsaquo; <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php if (!empty($siteFaviconUrl)): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($siteFaviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --admin-bg: #f8fafc;
            --admin-surface: #ffffff;
            --admin-surface-elevated: #ffffff;
            --admin-surface-subtle: #f1f5f9;
            --admin-border: #e2e8f0;
            --admin-border-subtle: #f1f5f9;
            --admin-border-focus: #3b82f6;
            --admin-text: #1e293b;
            --admin-text-heading: #0f172a;
            --admin-text-muted: #64748b;
            --admin-primary: #3b82f6;
            --admin-primary-hover: #2563eb;
            --admin-danger: #ef4444;
            --admin-danger-bg: rgba(239, 68, 68, 0.1);
            --admin-danger-border: #fca5a5;
            --admin-success: #22c55e;
            --admin-input-bg: #ffffff;
            --admin-input-border: #cbd5e1;
            --admin-input-text: #1e293b;
        }
        [data-admin-theme="dark"] {
            --admin-bg: #0f172a;
            --admin-surface: #1e293b;
            --admin-surface-elevated: #334155;
            --admin-surface-subtle: #1e293b;
            --admin-border: #334155;
            --admin-border-subtle: #1e293b;
            --admin-border-focus: #60a5fa;
            --admin-text: #e2e8f0;
            --admin-text-heading: #f8fafc;
            --admin-text-muted: #94a3b8;
            --admin-primary: #3b82f6;
            --admin-primary-hover: #60a5fa;
            --admin-danger: #f87171;
            --admin-danger-bg: rgba(248, 113, 113, 0.15);
            --admin-danger-border: rgba(248, 113, 113, 0.3);
            --admin-success: #4ade80;
            --admin-input-bg: #0f172a;
            --admin-input-border: #475569;
            --admin-input-text: #f8fafc;
        }
        html, body {
            width: 100vw;
            height: 100vh;
            max-width: 100vw;
            max-height: 100vh;
            overflow: hidden;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--admin-bg);
            color: var(--admin-text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Generic Core Shell Layout */
        .core-customizer-shell {
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .core-customizer-toolbar {
            height: 52px;
            background: var(--admin-surface);
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            gap: 12px;
            z-index: 100;
            flex-shrink: 0;
            user-select: none;
        }
        .core-toolbar-left, .core-toolbar-center, .core-toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .core-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            background: var(--admin-surface-subtle);
            color: var(--admin-text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid var(--admin-border);
            transition: all 0.15s ease;
        }
        .core-back-link:hover {
            background: var(--admin-border);
            color: var(--admin-text-heading);
        }
        .core-theme-badge {
            font-size: 12px;
            font-weight: 600;
            color: var(--admin-primary);
            background: rgba(59, 130, 246, 0.1);
            padding: 3px 8px;
            border-radius: 12px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .core-tool-btn {
            background: transparent;
            border: 1px solid var(--admin-border);
            color: var(--admin-text);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .core-tool-btn:hover:not(:disabled) {
            background: var(--admin-surface-subtle);
            color: var(--admin-text-heading);
        }
        .core-tool-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .core-device-switcher {
            display: flex;
            align-items: center;
            background: var(--admin-surface-subtle);
            border: 1px solid var(--admin-border);
            border-radius: 6px;
            padding: 2px;
            gap: 2px;
        }
        .core-device-btn {
            background: transparent;
            border: none;
            color: var(--admin-text-muted);
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .core-device-btn:hover {
            color: var(--admin-text);
        }
        .core-device-btn.active {
            background: var(--admin-surface);
            color: var(--admin-primary);
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }
        .core-btn-primary {
            background: var(--admin-primary);
            color: #ffffff;
            border: 1px solid transparent;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .core-btn-primary:hover:not(:disabled) {
            background: var(--admin-primary-hover);
        }
        .core-btn-danger-outline {
            background: transparent;
            border: 1px solid var(--admin-danger-border);
            color: var(--admin-danger);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .core-btn-danger-outline:hover {
            background: var(--admin-danger-bg);
        }
        .core-status-indicator {
            font-size: 12px;
            color: var(--admin-text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .core-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--admin-success);
            display: inline-block;
        }
        .core-status-dot.dirty {
            background: #f59e0b;
        }

        /* Workspace Structure */
        .core-workspace {
            display: flex;
            flex: 1;
            height: calc(100vh - 52px);
            overflow: hidden;
            position: relative;
        }
        .core-sidebar {
            width: 400px;
            min-width: 360px;
            max-width: 480px;
            height: 100%;
            background: var(--admin-surface);
            border-right: 1px solid var(--admin-border);
            overflow-y: auto;
            overflow-x: hidden;
            flex-shrink: 0;
            z-index: 10;
        }
        .core-preview-canvas {
            flex: 1;
            height: 100%;
            background: var(--admin-bg);
            display: flex;
            justify-content: center;
            align-items: stretch;
            overflow: hidden;
            position: relative;
        }
        .core-preview-wrapper {
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: stretch;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .core-preview-wrapper[data-device="desktop"] { width: 100%; }
        .core-preview-wrapper[data-device="tablet"]  { width: 768px; margin: 16px auto; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border-radius: 8px; overflow: hidden; border: 1px solid var(--admin-border); }
        .core-preview-wrapper[data-device="mobile"]  { width: 375px; margin: 16px auto; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border-radius: 8px; overflow: hidden; border: 1px solid var(--admin-border); }
        .core-preview-iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: #ffffff;
            display: block;
        }

        .core-mobile-toggle-btn { display: none; }
        @media (max-width: 768px) {
            .core-mobile-toggle-btn { display: inline-flex !important; }
            .core-device-switcher { display: none; }
            .core-sidebar {
                position: absolute;
                top: 52px;
                bottom: 0;
                left: 0;
                width: 85vw;
                max-width: 360px;
                z-index: 50;
                transform: translateX(-100%);
                transition: transform 0.25s ease-in-out;
                box-shadow: 2px 0 10px rgba(0,0,0,0.2);
            }
            .core-sidebar.is-open { transform: translateX(0); }
        }

        /* Generic Reusable Templates Modal */
        .core-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            visibility: hidden;
            transition: opacity 0.15s ease, visibility 0.15s ease;
        }
        .core-modal-backdrop.is-open {
            display: flex !important;
            opacity: 1;
            pointer-events: auto;
            visibility: visible;
        }
        .core-modal {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: 12px;
            width: 600px;
            max-width: 90vw;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .core-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--admin-border);
        }
        .core-modal-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="core-customizer-shell" id="core-customizer-shell">
        <?php if ($isThemeCustomizer): ?>
            <?php
            // Theme provides its own dedicated Customizer (e.g. Favorite Web)
            extract($viewData ?? [], EXTR_SKIP);
            include $contentView;
            ?>
        <?php else: ?>
            <!-- Generic Core Visual Builder Toolbar -->
            <header class="core-customizer-toolbar">
                <div class="core-toolbar-left">
                    <a href="<?php echo htmlspecialchars(site_path('/admin/themes'), ENT_QUOTES, 'UTF-8'); ?>" class="core-back-link" title="Return to Appearance Themes">&larr; Appearance</a>
                    <button type="button" class="core-tool-btn core-mobile-toggle-btn" id="core-toggle-sidebar-btn" title="Toggle Controls">&#9776; Controls</button>
                    <span class="core-theme-badge"><?php echo htmlspecialchars($themeName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <button type="button" class="core-tool-btn" id="core-btn-undo" title="Undo (Ctrl+Z)" disabled>&lsaquo; Undo</button>
                    <button type="button" class="core-tool-btn" id="core-btn-redo" title="Redo (Ctrl+Shift+Z)" disabled>Redo &rsaquo;</button>
                    <div class="core-status-indicator">
                        <span class="core-status-dot" id="core-status-dot"></span>
                        <span id="core-status-text">All changes saved</span>
                    </div>
                </div>

                <div class="core-toolbar-center">
                    <div class="core-device-switcher">
                        <button type="button" class="core-device-btn active" data-device="desktop" title="Desktop Preview">Desktop</button>
                        <button type="button" class="core-device-btn" data-device="tablet" title="Tablet Preview (768px)">Tablet</button>
                        <button type="button" class="core-device-btn" data-device="mobile" title="Mobile Preview (375px)">Mobile</button>
                    </div>
                    <button type="button" class="core-tool-btn" id="core-btn-reload" title="Reload Preview Frame">↻</button>
                    <a href="/?preview=1" target="_blank" class="core-tool-btn" id="core-btn-external" title="Open preview in new tab">↗ Preview</a>
                </div>

                <div class="core-toolbar-right">
                    <button type="button" class="core-tool-btn" id="core-btn-templates" title="Reusable Templates">📋 Templates</button>
                    <form method="POST" action="<?php echo htmlspecialchars(site_path('/admin/customize/reset'), ENT_QUOTES, 'UTF-8'); ?>" style="display:inline;" onsubmit="return confirm('Reset all theme customizations and sections back to defaults?');">
                        <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="core-btn-danger-outline">&#8635; Reset</button>
                    </form>
                    <button type="button" class="core-btn-primary" id="core-btn-save">Save Changes</button>
                </div>
            </header>

            <!-- Generic Core Workspace -->
            <div class="core-workspace">
                <aside class="core-sidebar" role="region" aria-label="Theme Customizer Controls">
                    <?php
                    extract($viewData ?? [], EXTR_SKIP);
                    include APP_ROOT . '/resources/views/admin/customize/index.php';
                    ?>
                </aside>
                <main class="core-preview-canvas">
                    <div class="core-preview-wrapper" id="core-preview-wrapper" data-device="desktop">
                        <iframe src="/?preview=1" class="core-preview-iframe" id="core-preview-iframe" title="Live Theme Preview"></iframe>
                    </div>
                </main>
            </div>
        <?php endif; ?>
    </div>

    <!-- Generic Reusable Templates Modal -->
    <div class="core-modal-backdrop" id="core-templates-modal" role="dialog" aria-modal="true" aria-labelledby="core-tpl-modal-title" hidden style="display: none;">
        <div class="core-modal">
            <div class="core-modal-header">
                <h3 id="core-tpl-modal-title" style="font-size: 15px; font-weight: 700;">Reusable Templates</h3>
                <button type="button" class="core-tool-btn" id="core-tpl-modal-close" style="border:none; font-size:18px; cursor:pointer;">&times;</button>
            </div>
            <div class="core-modal-body" id="core-templates-list">
                <p style="font-size: 13px; color: var(--admin-text-muted);">Loading templates...</p>
            </div>
        </div>
    </div>

    <!-- Core Generic Visual Builder Client Engine -->
    <script>
    (function() {
        'use strict';

        var CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
        var THEME_ID = <?php echo json_encode($themeId); ?>;
        var ORIGIN = window.location.origin;

        // 1. In-Memory History Manager (Undo / Redo)
        var HistoryManager = {
            undoStack: [],
            redoStack: [],
            maxDepth: 50,
            listeners: [],

            push: function(state) {
                var str = JSON.stringify(state);
                if (this.undoStack.length > 0 && this.undoStack[this.undoStack.length - 1] === str) {
                    return;
                }
                this.undoStack.push(str);
                if (this.undoStack.length > this.maxDepth) {
                    this.undoStack.shift();
                }
                this.redoStack = [];
                this.notify();
            },
            undo: function(currentState) {
                if (this.undoStack.length === 0) return null;
                if (currentState !== undefined && currentState !== null) {
                    var currentStr = JSON.stringify(currentState);
                    if (this.undoStack.length > 0 && this.undoStack[this.undoStack.length - 1] === currentStr) {
                        this.redoStack.push(this.undoStack.pop());
                    } else {
                        this.redoStack.push(currentStr);
                    }
                } else {
                    this.redoStack.push(this.undoStack.pop());
                }
                if (this.undoStack.length === 0) {
                    this.notify();
                    return null;
                }
                var targetState = JSON.parse(this.undoStack[this.undoStack.length - 1]);
                this.notify();
                return targetState;
            },
            redo: function(currentState) {
                if (this.redoStack.length === 0) return null;
                var nextStateStr = this.redoStack.pop();
                this.undoStack.push(nextStateStr);
                this.notify();
                return JSON.parse(nextStateStr);
            },
            canUndo: function() { return this.undoStack.length > 1; },
            canRedo: function() { return this.redoStack.length > 0; },
            subscribe: function(fn) { this.listeners.push(fn); },
            notify: function() {
                var self = this;
                this.listeners.forEach(function(fn) {
                    try { fn(self.canUndo(), self.canRedo()); } catch (e) {}
                });
            }
        };

        // 2. In-Memory Clipboard Manager
        var ClipboardManager = {
            storage: {},
            copy: function(type, data) {
                try {
                    this.storage[type] = JSON.parse(JSON.stringify(data));
                    return true;
                } catch (e) { return false; }
            },
            paste: function(type) {
                if (!this.storage[type]) return null;
                try {
                    return JSON.parse(JSON.stringify(this.storage[type]));
                } catch (e) { return null; }
            },
            has: function(type) {
                return !!this.storage[type];
            }
        };

        // 3. Global Keyboard Shortcuts Manager (with input guard)
        function initShortcuts(options) {
            options = options || {};
            document.addEventListener('keydown', function(e) {
                var tag = document.activeElement ? document.activeElement.tagName : '';
                var isInput = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (document.activeElement && document.activeElement.isContentEditable);

                // Ctrl+S / Cmd+S -> Save
                if ((e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                    if (typeof options.onSave === 'function') options.onSave();
                    return;
                }

                // If inside input/textarea, do not hijack editing shortcuts
                if (isInput) return;

                // Ctrl+Z / Cmd+Z -> Undo
                if ((e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
                    e.preventDefault();
                    if (typeof options.onUndo === 'function') options.onUndo();
                    return;
                }

                // Ctrl+Shift+Z / Cmd+Shift+Z / Ctrl+Y -> Redo
                if (((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'z' || e.key === 'Z')) ||
                    ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y'))) {
                    e.preventDefault();
                    if (typeof options.onRedo === 'function') options.onRedo();
                    return;
                }

                // Ctrl+C / Cmd+C -> Copy
                if ((e.ctrlKey || e.metaKey) && (e.key === 'c' || e.key === 'C')) {
                    if (typeof options.onCopy === 'function') options.onCopy();
                    return;
                }

                // Ctrl+V / Cmd+V -> Paste
                if ((e.ctrlKey || e.metaKey) && (e.key === 'v' || e.key === 'V')) {
                    if (typeof options.onPaste === 'function') options.onPaste();
                    return;
                }

                // Delete -> Delete element
                if (e.key === 'Delete' || e.key === 'Del') {
                    if (typeof options.onDelete === 'function') options.onDelete();
                    return;
                }

                // Escape -> Close modal / deselect
                if (e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27) {
                    if (typeof options.onEscape === 'function') options.onEscape();
                    return;
                }
            });
        }

        // 4. Same-Origin Preview Protocol
        function sendPreviewMessage(iframe, messageType, payload) {
            if (!iframe || !iframe.contentWindow) return;
            var message = {
                source: 'favorite-cms-customizer',
                type: messageType,
                payload: payload || {}
            };
            iframe.contentWindow.postMessage(message, window.location.origin);
        }

        window.addEventListener('message', function(e) {
            if (!e || e.origin !== window.location.origin) return;
            var iframe = document.getElementById('core-preview-iframe');
            if (!iframe || e.source !== iframe.contentWindow) return;
        });

        // 5. Template Client API
        var TemplateApi = {
            list: function() {
                return fetch(<?php echo json_encode(site_path('/admin/customize/templates'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function(r) { return r.json(); });
            },
            save: function(name, data) {
                var formData = new FormData();
                formData.append('_token', CSRF_TOKEN);
                formData.append('name', name);
                formData.append('data', JSON.stringify(data));
                return fetch(<?php echo json_encode(site_path('/admin/customize/templates/save'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function(r) { return r.json(); });
            },
            delete: function(templateId) {
                var formData = new FormData();
                formData.append('_token', CSRF_TOKEN);
                formData.append('template_id', templateId);
                return fetch(<?php echo json_encode(site_path('/admin/customize/templates/delete'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function(r) { return r.json(); });
            }
        };

        // Export to Global Builder Contract
        window.FavoriteBuilder = {
            history: HistoryManager,
            clipboard: ClipboardManager,
            templates: TemplateApi,
            shortcuts: initShortcuts,
            postMessage: sendPreviewMessage,
            origin: ORIGIN,
            csrfToken: CSRF_TOKEN,
            themeId: THEME_ID
        };

        // Initialize Core Shell UI if not overridden
        document.addEventListener('DOMContentLoaded', function() {
            var undoBtn = document.getElementById('core-btn-undo');
            var redoBtn = document.getElementById('core-btn-redo');
            if (undoBtn && redoBtn) {
                HistoryManager.subscribe(function(canUndo, canRedo) {
                    undoBtn.disabled = !canUndo;
                    redoBtn.disabled = !canRedo;
                });
            }

            var deviceBtns = document.querySelectorAll('.core-device-btn');
            var previewWrapper = document.getElementById('core-preview-wrapper');
            deviceBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    deviceBtns.forEach(function(b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    var dev = btn.getAttribute('data-device');
                    if (previewWrapper) previewWrapper.setAttribute('data-device', dev);
                });
            });

            var reloadBtn = document.getElementById('core-btn-reload');
            var previewIframe = document.getElementById('core-preview-iframe');
            if (reloadBtn && previewIframe) {
                reloadBtn.addEventListener('click', function() {
                    previewIframe.src = previewIframe.src;
                });
            }

            // Reusable Templates Modal Handlers
            var tplBtn = document.getElementById('core-btn-templates');
            var tplModal = document.getElementById('core-templates-modal');
            var tplClose = document.getElementById('core-tpl-modal-close');
            var tplList = document.getElementById('core-templates-list');

            function openTplModal() {
                if (!tplModal) return;
                tplModal.hidden = false;
                tplModal.classList.add('is-open');
                tplModal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                if (tplList) {
                    tplList.innerHTML = '<p style="font-size:13px; color:var(--admin-text-muted);">Loading templates...</p>';
                    TemplateApi.list().then(function(res) {
                        var items = res.templates || [];
                        if (items.length === 0) {
                            tplList.innerHTML = '<p style="font-size:13px; color:var(--admin-text-muted); text-align:center; padding:24px 0;">No saved templates yet. You can save any section or container as a reusable template.</p>';
                            return;
                        }
                        var html = '<ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:8px;">';
                        items.forEach(function(tpl) {
                            html += '<li style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border:1px solid var(--admin-border); border-radius:6px; background:var(--admin-surface);">';
                            html += '<div><strong>' + (tpl.name || 'Unnamed') + '</strong> <span style="font-size:11px; color:var(--admin-text-muted);">&bull; ' + (tpl.type || 'section') + '</span></div>';
                            html += '<button type="button" class="core-tool-btn core-tpl-del-btn" data-id="' + tpl.id + '" style="color:var(--admin-danger);">&times; Delete</button>';
                            html += '</li>';
                        });
                        html += '</ul>';
                        tplList.innerHTML = html;
                        tplList.querySelectorAll('.core-tpl-del-btn').forEach(function(delBtn) {
                            delBtn.addEventListener('click', function() {
                                var id = delBtn.getAttribute('data-id');
                                if (confirm('Delete this template?')) {
                                    TemplateApi.delete(id).then(function() { openTplModal(); });
                                }
                            });
                        });
                    }).catch(function() {
                        tplList.innerHTML = '<p style="color:var(--admin-danger); font-size:13px;">Error loading templates.</p>';
                    });
                }
            }

            function closeTplModal() {
                if (!tplModal) return;
                tplModal.hidden = true;
                tplModal.classList.remove('is-open');
                tplModal.style.display = 'none';
                document.body.style.overflow = '';
            }

            if (tplBtn) tplBtn.addEventListener('click', openTplModal);
            if (tplClose) tplClose.addEventListener('click', closeTplModal);
            if (tplModal) {
                tplModal.addEventListener('click', function(e) {
                    if (e.target === tplModal) closeTplModal();
                });
            var coreToggleBtn = document.getElementById('core-toggle-sidebar-btn');
            var coreSidebar = document.querySelector('.core-sidebar');
            if (coreToggleBtn && coreSidebar) {
                coreToggleBtn.addEventListener('click', function() {
                    coreSidebar.classList.toggle('is-open');
                });
            }

            initShortcuts({
                onSave: function() {
                    var saveBtn = document.getElementById('core-btn-save') || document.getElementById('fw-save-btn');
                    if (saveBtn) saveBtn.click();
                },
                onUndo: function() {
                    var btn = document.getElementById('core-btn-undo') || document.getElementById('fw-undo-btn');
                    if (btn && !btn.disabled) btn.click();
                },
                onRedo: function() {
                    var btn = document.getElementById('core-btn-redo') || document.getElementById('fw-redo-btn');
                    if (btn && !btn.disabled) btn.click();
                },
                onEscape: function() {
                    closeTplModal();
                }
            });
        });
    })();
    </script>
</body>
</html>

