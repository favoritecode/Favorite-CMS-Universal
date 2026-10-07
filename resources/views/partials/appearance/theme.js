/* Shared appearance controller; independent of editors and form state. */
(function (win, doc) {
    'use strict';
    var config = win.favoriteAppearanceConfig || {};
    var key = 'favorite_admin_theme';
    var selector = '.fc-theme-toggle, #admin-theme-toggle, #theme-toggle-btn, [data-appearance-toggle]';
    var pending = Promise.resolve();
    function valid(theme) { return theme === 'dark' || theme === 'light'; }
    function current() { return doc.documentElement.getAttribute('data-admin-theme') || 'dark'; }
    function updateButtons() {
        var dark = current() === 'dark';
        doc.querySelectorAll(selector).forEach(function (button) {
            var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            button.setAttribute('aria-pressed', String(dark));
            button.querySelectorAll('.theme-icon-sun, .fc-theme-icon-sun').forEach(function (icon) { icon.style.display = dark ? 'inline-flex' : 'none'; });
            button.querySelectorAll('.theme-icon-moon, .fc-theme-icon-moon').forEach(function (icon) { icon.style.display = dark ? 'none' : 'inline-flex'; });
        });
    }
    function updatePreview() {
        var frame = doc.getElementById('core-preview-iframe');
        if (!frame) return;
        try {
            if (frame.contentWindow.location.origin === win.location.origin && frame.contentWindow.FavoriteAppearance) frame.contentWindow.FavoriteAppearance.set(current(), false);
        } catch (e) {}
    }
    function persistServer(theme) {
        if (!config.endpoint || !win.fetch) return;
        // Preserve click order when the network is slow.
        pending = pending.then(function () {
            var data = new FormData();
            data.append('_token', config.token || '');
            data.append('theme', theme);
            return win.fetch(config.endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        }).catch(function () {});
    }
    function set(theme, persist) {
        if (!valid(theme)) return;
        doc.documentElement.setAttribute('data-admin-theme', theme);
        doc.documentElement.setAttribute('data-theme', theme);
        doc.documentElement.style.colorScheme = theme;
        if (persist) {
            try { win.localStorage.setItem(key, theme); } catch (e) {}
            try { doc.cookie = key + '=' + theme + ';path=/;max-age=31536000;SameSite=Lax' + (win.location.protocol === 'https:' ? ';Secure' : ''); } catch (e) {}
            persistServer(theme);
        }
        updateButtons();
        updatePreview();
        doc.dispatchEvent(new CustomEvent('favorite:appearance', { detail: { theme: theme } }));
    }
    var initial = valid(config.theme) ? config.theme : 'dark';
    try { var stored = win.localStorage.getItem(key); if (valid(stored)) initial = stored; } catch (e) {}
    win.FavoriteAppearance = { set: set, current: current };
    set(initial, false);
    doc.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest(selector);
        if (!button || button.disabled) return;
        set(current() === 'dark' ? 'light' : 'dark', true);
    });
    doc.addEventListener('DOMContentLoaded', function () {
        updateButtons();
        var frame = doc.getElementById('core-preview-iframe');
        if (frame) frame.addEventListener('load', updatePreview);
        updatePreview();
    });
    win.addEventListener('storage', function (event) {
        if (event.key === key && valid(event.newValue)) set(event.newValue, false);
    });
})(window, document);
