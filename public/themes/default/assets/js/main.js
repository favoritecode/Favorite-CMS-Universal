/* Favorite CMS Default Theme: mobile navigation, account menu and image fallbacks. No dependencies. */
(function () {
    'use strict';
    var doc = document;

    function init() {
        var toggle = doc.getElementById('mobile-nav-btn');
        var panel = doc.getElementById('header-nav-wrap');

        if (toggle && panel) {
            var setOpen = function (open, focusToggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.classList.toggle('is-open', open);
                if (!open && focusToggle) { toggle.focus(); }
            };
            toggle.addEventListener('click', function () {
                setOpen(toggle.getAttribute('aria-expanded') !== 'true');
            });
            doc.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && panel.classList.contains('is-open')) { setOpen(false, true); }
            });
            panel.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('a[href]') && panel.classList.contains('is-open')) { setOpen(false); }
            });
            if (window.matchMedia) {
                var desktop = window.matchMedia('(min-width: 1024px)');
                var onChange = function (q) { if (q.matches) { setOpen(false); } };
                if (desktop.addEventListener) { desktop.addEventListener('change', onChange); } else if (desktop.addListener) { desktop.addListener(onChange); }
            }
        }

        var wrappers = '.post-card__media, .entry-media, .featured-post-card__media, .widget-recent-posts__thumb';
        doc.querySelectorAll('.post-card__media img, .entry-media img, .featured-post-card__media img, .widget-recent-posts__thumb img').forEach(function (img) {
            var hide = function () { var w = img.closest(wrappers); if (w) { w.classList.add('is-broken'); } };
            if (img.complete && img.getAttribute('src') && img.naturalWidth === 0) { hide(); }
            img.addEventListener('error', hide);
        });

        doc.querySelectorAll('.cms-account-menu').forEach(function (menu) {
            var trigger = menu.querySelector('.cms-account-trigger');
            if (!trigger || !menu.querySelector('.cms-account-dropdown')) { return; }
            var setMenu = function (open, focusTrigger) {
                menu.classList.toggle('is-open', open);
                trigger.setAttribute('aria-expanded', String(open));
                if (!open && focusTrigger) { trigger.focus(); }
            };
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = !menu.classList.contains('is-open');
                doc.querySelectorAll('.cms-account-menu.is-open').forEach(function (other) {
                    if (other === menu) { return; }
                    other.classList.remove('is-open');
                    var otherTrigger = other.querySelector('.cms-account-trigger');
                    if (otherTrigger) { otherTrigger.setAttribute('aria-expanded', 'false'); }
                });
                setMenu(open);
            });
            doc.addEventListener('click', function (e) {
                if (!menu.contains(e.target) && menu.classList.contains('is-open')) { setMenu(false); }
            });
            menu.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && menu.classList.contains('is-open')) { e.stopPropagation(); setMenu(false, true); }
            });
        });
    }

    if (doc.readyState !== 'loading') { init(); } else { doc.addEventListener('DOMContentLoaded', init); }
})();
