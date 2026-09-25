<?php

declare(strict_types=1);

namespace FavoriteCMS\Rendering;

/**
 * Generic Core Frontend Back-to-Top Control & Theme-Aware Enhancements
 *
 * Provides a lightweight, accessible, theme-agnostic Back-to-Top button
 * and desktop sticky sidebar layout rules for any active Favorite CMS theme.
 */
class BackToTop
{
    /**
     * Render the complete Back-to-Top button markup, styles, and script.
     */
    public static function render(): string
    {
        return <<<HTML
<style id="cms-core-frontend-enhancements">
/* Core Frontend: Sticky Theme Sidebar on Desktop */
@media (min-width: 1024px) {
    .sidebar,
    [role="complementary"],
    .site-sidebar,
    .widget-area,
    aside.sidebar {
        position: -webkit-sticky;
        position: sticky;
        top: var(--cms-sidebar-top, 24px);
        max-height: calc(100vh - var(--cms-sidebar-top, 24px) - 24px);
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
    }
    .sidebar::-webkit-scrollbar,
    [role="complementary"]::-webkit-scrollbar,
    .site-sidebar::-webkit-scrollbar,
    .widget-area::-webkit-scrollbar,
    aside.sidebar::-webkit-scrollbar {
        width: 4px;
    }
    .sidebar::-webkit-scrollbar-thumb,
    [role="complementary"]::-webkit-scrollbar-thumb,
    .site-sidebar::-webkit-scrollbar-thumb,
    .widget-area::-webkit-scrollbar-thumb,
    aside.sidebar::-webkit-scrollbar-thumb {
        background: rgba(100, 116, 139, 0.25);
        border-radius: 4px;
    }
}

/* Core Frontend: Theme-Aware Back to Top Button */
.cms-back-to-top {
    --_btt-bg: var(--cms-btt-bg, var(--surface, var(--card-bg, #1e293b)));
    --_btt-color: var(--cms-btt-color, var(--text, #ffffff));
    --_btt-border: var(--cms-btt-border, var(--border, rgba(255, 255, 255, 0.15)));
    --_btt-hover-bg: var(--cms-btt-hover-bg, var(--accent, var(--primary, #2563eb)));
    --_btt-hover-color: var(--cms-btt-hover-color, #ffffff);
    --_btt-focus: var(--cms-btt-focus, var(--accent, var(--primary, #3b82f6)));
    --_btt-shadow: var(--cms-btt-shadow, 0 4px 14px rgba(0, 0, 0, 0.16));

    position: fixed;
    bottom: max(24px, env(safe-area-inset-bottom, 24px));
    right: max(24px, env(safe-area-inset-right, 24px));
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background-color: var(--_btt-bg);
    color: var(--_btt-color);
    border: 1px solid var(--_btt-border);
    box-shadow: var(--_btt-shadow);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(12px) scale(0.92);
    transition: opacity 0.25s ease, transform 0.25s ease, visibility 0.25s ease, background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    user-select: none;
    line-height: 1;
}

.cms-back-to-top[data-visible="true"] {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}

.cms-back-to-top:hover {
    background-color: var(--_btt-hover-bg);
    color: var(--_btt-hover-color);
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.24);
}

.cms-back-to-top:active {
    transform: translateY(0) scale(0.96);
}

.cms-back-to-top:focus-visible {
    outline: 2px solid var(--_btt-focus);
    outline-offset: 3px;
}

.cms-back-to-top svg {
    display: block;
    pointer-events: none;
}

@media (prefers-color-scheme: dark) {
    .cms-back-to-top {
        --_btt-bg: var(--cms-btt-bg, var(--surface, #1e293b));
        --_btt-color: var(--cms-btt-color, var(--text, #f8fafc));
        --_btt-border: var(--cms-btt-border, var(--border, #334155));
    }
}

[data-theme="dark"] .cms-back-to-top,
.dark .cms-back-to-top {
    --_btt-bg: var(--cms-btt-bg, var(--surface, #1e293b));
    --_btt-color: var(--cms-btt-color, var(--text, #f8fafc));
    --_btt-border: var(--cms-btt-border, var(--border, #334155));
}

@media (prefers-reduced-motion: reduce) {
    .cms-back-to-top {
        transition: none !important;
        transform: none !important;
    }
}

@media (max-width: 640px) {
    .cms-back-to-top {
        bottom: max(16px, env(safe-area-inset-bottom, 16px));
        right: max(16px, env(safe-area-inset-right, 16px));
        width: 40px;
        height: 40px;
    }
    .cms-back-to-top svg {
        width: 18px;
        height: 18px;
    }
}
</style>

<button type="button" class="cms-back-to-top" id="cms-back-to-top" aria-label="Back to top" hidden>
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M18 15l-6-6-6 6"/>
    </svg>
</button>

<script>
(function() {
    var btn = document.getElementById('cms-back-to-top');
    if (!btn) return;

    var scrollThreshold = 300;
    var ticking = false;

    function updateVisibility() {
        var scrolled = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        var shouldShow = scrolled > scrollThreshold;
        if (shouldShow) {
            btn.removeAttribute('hidden');
            btn.setAttribute('data-visible', 'true');
        } else {
            btn.setAttribute('data-visible', 'false');
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                btn.setAttribute('hidden', '');
            } else {
                setTimeout(function() {
                    if (btn.getAttribute('data-visible') === 'false') {
                        btn.setAttribute('hidden', '');
                    }
                }, 260);
            }
        }
        ticking = false;
    }

    window.addEventListener('scroll', function() {
        if (!ticking) {
            window.requestAnimationFrame(updateVisibility);
            ticking = true;
        }
    }, { passive: true });

    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) {
            window.scrollTo(0, 0);
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    updateVisibility();
})();
</script>
HTML;
    }
}

