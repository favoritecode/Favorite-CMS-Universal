<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support;

/**
 * Makes every Favorite Pay admin fragment follow the active CMS/theme color mode.
 * Does not set or persist a mode; the active theme remains the single source of truth.
 */
final class AdminThemeBridge
{
    public static function render(string $html): string
    {
        if (str_contains($html, 'fpay-theme-bridge-style')) {
            return $html;
        }

        $style = <<<'CSS'
<style id="fpay-theme-bridge-style">
/* Favorite Pay inherits the CMS theme; no independent mode or preference. */
.favorite-pay-admin-shell { color: var(--text, #253044); color-scheme: light; }
.favorite-pay-admin-shell :is(input,select,textarea,button) { color-scheme: inherit; }

/* DARK MODE — cover inline-styled legacy panels as well as newer components. */
html[data-theme="dark"] .favorite-pay-admin-shell,
body[data-theme="dark"] .favorite-pay-admin-shell,
html.dark .favorite-pay-admin-shell,
body.dark .favorite-pay-admin-shell,
html[data-bs-theme="dark"] .favorite-pay-admin-shell,
body[data-bs-theme="dark"] .favorite-pay-admin-shell {
  color-scheme: dark !important;
  color: #cbd5e1 !important;
}
html[data-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em),
body[data-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em),
html.dark .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em),
body.dark .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6,label,legend,p,small,span,div,li,dt,dd,td,th,caption,strong,b,em) { color: #cbd5e1; }
html[data-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6),
body[data-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6),
html.dark .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6),
body.dark .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(h1,h2,h3,h4,h5,h6) { color: #f8fafc !important; }

/* Cards, empty states, summaries and legacy inline white/near-white panels. */
html[data-theme="dark"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
body[data-theme="dark"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
html.dark .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
body.dark .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#fff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #fff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#ffffff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #ffffff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background: white"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#f"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #f"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#fff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #fff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#ffffff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #ffffff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background: white"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background:#f"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background: #f"],
html.dark .favorite-pay-admin-shell [style*="background:#fff"],
html.dark .favorite-pay-admin-shell [style*="background: #fff"],
html.dark .favorite-pay-admin-shell [style*="background:#ffffff"],
html.dark .favorite-pay-admin-shell [style*="background: #ffffff"],
html.dark .favorite-pay-admin-shell [style*="background: white"],
html.dark .favorite-pay-admin-shell [style*="background:#f"],
html.dark .favorite-pay-admin-shell [style*="background: #f"],
body.dark .favorite-pay-admin-shell [style*="background:#fff"],
body.dark .favorite-pay-admin-shell [style*="background: #fff"],
body.dark .favorite-pay-admin-shell [style*="background:#ffffff"],
body.dark .favorite-pay-admin-shell [style*="background: #ffffff"],
body.dark .favorite-pay-admin-shell [style*="background: white"],
body.dark .favorite-pay-admin-shell [style*="background:#f"],
body.dark .favorite-pay-admin-shell [style*="background: #f"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#fff"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #fff"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#ffffff"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #ffffff"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: white"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#f"],
html[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #f"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#fff"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #fff"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#ffffff"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #ffffff"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: white"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background:#f"],
body[data-bs-theme="dark"] .favorite-pay-admin-shell [style*="background: #f"] {
  background-color: #111827 !important;
  background-image: none;
  border-color: #334155 !important;
  color: #cbd5e1 !important;
}

/* Targeted fix for legacy inline rgb/hex background declarations seen on
   Withdrawals settings and Dashboard summary panels. */
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #fff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#fff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #ffffff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#ffffff"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: white"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:white"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: rgb(255"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:rgb(255"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f8fafc"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f8fafc"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f1f5f9"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f1f5f9"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f9fafb"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f9fafb"],
html.dark .favorite-pay-admin-shell [style*="background-color: #fff"],
html.dark .favorite-pay-admin-shell [style*="background-color:#fff"],
html.dark .favorite-pay-admin-shell [style*="background-color: #ffffff"],
html.dark .favorite-pay-admin-shell [style*="background-color:#ffffff"],
html.dark .favorite-pay-admin-shell [style*="background-color: white"],
html.dark .favorite-pay-admin-shell [style*="background-color:white"],
html.dark .favorite-pay-admin-shell [style*="background-color: rgb(255"],
html.dark .favorite-pay-admin-shell [style*="background-color:rgb(255"],
html.dark .favorite-pay-admin-shell [style*="background-color: #f8fafc"],
html.dark .favorite-pay-admin-shell [style*="background-color:#f8fafc"],
html.dark .favorite-pay-admin-shell [style*="background-color: #f1f5f9"],
html.dark .favorite-pay-admin-shell [style*="background-color:#f1f5f9"],
html.dark .favorite-pay-admin-shell [style*="background-color: #f9fafb"],
html.dark .favorite-pay-admin-shell [style*="background-color:#f9fafb"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #fff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#fff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #ffffff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#ffffff"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: white"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:white"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: rgb(255"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:rgb(255"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f8fafc"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f8fafc"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f1f5f9"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f1f5f9"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color: #f9fafb"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="background-color:#f9fafb"] {
  background-color: #111827 !important;
  background-image: none !important;
  border-color: #334155 !important;
  color: #cbd5e1 !important;
}

/* Legacy inline pale text was nearly invisible against those white panels. */
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #cbd5e1"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#cbd5e1"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #94a3b8"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#94a3b8"],
html.dark .favorite-pay-admin-shell [style*="color: #cbd5e1"],
html.dark .favorite-pay-admin-shell [style*="color:#cbd5e1"],
html.dark .favorite-pay-admin-shell [style*="color: #94a3b8"],
html.dark .favorite-pay-admin-shell [style*="color:#94a3b8"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #cbd5e1"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#cbd5e1"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #94a3b8"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#94a3b8"] {
  color: #cbd5e1 !important;
}

/* Inline light foreground colors are common in older dashboard widgets. */
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #0f172a"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#0f172a"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #1e293b"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#1e293b"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #334155"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#334155"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #64748b"],
html[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#64748b"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #0f172a"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#0f172a"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #1e293b"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#1e293b"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #334155"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#334155"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color: #64748b"],
body[data-theme="dark"] .favorite-pay-admin-shell [style*="color:#64748b"],
body.dark .favorite-pay-admin-shell [style*="color: #0f172a"],
body.dark .favorite-pay-admin-shell [style*="color: #1e293b"],
html.dark .favorite-pay-admin-shell [style*="color: #0f172a"],
html.dark .favorite-pay-admin-shell [style*="color: #1e293b"] { color: #cbd5e1 !important; }

html[data-theme="dark"] .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body[data-theme="dark"] .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
html.dark .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body.dark .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea) {
  background-color: #0b1120 !important; color: #e2e8f0 !important; border-color: #475569 !important; color-scheme: dark;
}
html[data-theme="dark"] .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th),
body[data-theme="dark"] .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th),
html.dark .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th),
body.dark .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(table,thead,tbody,tr,td,th) { border-color: #334155 !important; }
html[data-theme="dark"] .favorite-pay-admin-shell :is(thead th,.fpay-table th),
body[data-theme="dark"] .favorite-pay-admin-shell :is(thead th,.fpay-table th),
html.dark .favorite-pay-admin-shell :is(thead th,.fpay-table th),
body.dark .favorite-pay-admin-shell :is(thead th,.fpay-table th),
html[data-bs-theme="dark"] .favorite-pay-admin-shell :is(thead th,.fpay-table th),
body[data-bs-theme="dark"] .favorite-pay-admin-shell :is(thead th,.fpay-table th) { background: #1e293b !important; color: #cbd5e1 !important; }
html[data-theme="dark"] .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary),
body[data-theme="dark"] .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary),
html.dark .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary),
body.dark .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary),
html[data-bs-theme="dark"] .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary),
body[data-bs-theme="dark"] .favorite-pay-admin-shell a:not(.button-primary):not(.fpay-btn-primary) { color: #93c5fd; }
html[data-theme="dark"] .favorite-pay-admin-shell hr,
body[data-theme="dark"] .favorite-pay-admin-shell hr,
html.dark .favorite-pay-admin-shell hr,
body.dark .favorite-pay-admin-shell hr,
html[data-bs-theme="dark"] .favorite-pay-admin-shell hr,
body[data-bs-theme="dark"] .favorite-pay-admin-shell hr { border-color: #334155 !important; }

/* LIGHT MODE — remove stale dark fills from components with inline styling. */
html[data-theme="light"] .favorite-pay-admin-shell,
body[data-theme="light"] .favorite-pay-admin-shell,
html[data-bs-theme="light"] .favorite-pay-admin-shell,
body[data-bs-theme="light"] .favorite-pay-admin-shell {
  color-scheme: light !important;
  color: #253044;
}
html[data-theme="light"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
body[data-theme="light"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
html[data-bs-theme="light"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card),
body[data-bs-theme="light"] .favorite-pay-admin-shell :is(.card,.fpay-card,.notice,.postbox,.inside,.widefat,.tablenav,.form-table,.table-responsive,.empty-state,.empty-state-box,.stat-card,.summary-card,.metric-card,.panel,.settings-card,.payment-card,.withdrawal-card) {
  background-color: #ffffff;
  border-color: #dbe3ee;
  color: #253044;
}
@media (prefers-reduced-motion: no-preference) {
  .favorite-pay-admin-shell :is(.card,.fpay-card,input,select,textarea) { transition: background-color .15s ease, color .15s ease, border-color .15s ease; }
}
</style>
<script id="fpay-theme-preference-sync">(function(){try{var r=document.documentElement,b=document.body;function sync(){var mode=(r.getAttribute('data-theme')||r.getAttribute('data-bs-theme')||(r.classList.contains('dark')?'dark':'')||(b&&(b.getAttribute('data-theme')||b.getAttribute('data-bs-theme')||(b.classList.contains('dark')?'dark':'')))||'').toLowerCase();if((mode==='dark'||mode==='light')&&r.getAttribute('data-theme')!==mode){r.setAttribute('data-theme',mode);}}sync();if(window.MutationObserver){new MutationObserver(sync).observe(r,{attributes:true,attributeFilter:['data-theme','data-bs-theme','class']});if(b)new MutationObserver(sync).observe(b,{attributes:true,attributeFilter:['data-theme','data-bs-theme','class']});}}catch(e){}})();</script>
CSS;
        return '<div class="favorite-pay-admin-shell">' . $style . $html . '</div>';
    }
}
