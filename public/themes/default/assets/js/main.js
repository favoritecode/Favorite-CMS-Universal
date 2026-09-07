/**
 * Favorite CMS — Default Theme JavaScript
 * Lightweight, zero dependencies, accessible interactions.
 */
document.addEventListener('DOMContentLoaded', function() {
    // 1. Mobile Menu Toggle
    const navBtn = document.getElementById('mobile-nav-btn');
    const navWrap = document.getElementById('header-nav-wrap');

    if (navBtn && navWrap) {
        navBtn.addEventListener('click', function() {
            const isExpanded = navBtn.getAttribute('aria-expanded') === 'true';
            navBtn.setAttribute('aria-expanded', !isExpanded);
            navWrap.classList.toggle('is-open');
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && navWrap.classList.contains('is-open')) {
                navBtn.setAttribute('aria-expanded', 'false');
                navWrap.classList.remove('is-open');
                navBtn.focus();
            }
        });
    }

    // 2. Safe image fallback if broken or missing
    document.querySelectorAll('.post-card-thumb img, .single-featured-media img').forEach(function(img) {
        img.addEventListener('error', function() {
            const wrapper = img.closest('.post-card-thumb') || img.closest('.single-featured-media');
            if (wrapper) {
                wrapper.style.display = 'none';
            }
        });
    });

    // 3. User Account Dropdown Toggle
    document.querySelectorAll('.cms-account-menu').forEach(function(menu) {
        const trigger = menu.querySelector('.cms-account-trigger');
        const dropdown = menu.querySelector('.cms-account-dropdown');
        if (!trigger || !dropdown) return;

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = menu.classList.contains('is-open');

            // Close any other open account menus
            document.querySelectorAll('.cms-account-menu.is-open').forEach(function(other) {
                if (other !== menu) {
                    other.classList.remove('is-open');
                    other.querySelector('.cms-account-trigger')?.setAttribute('aria-expanded', 'false');
                }
            });

            menu.classList.toggle('is-open', !isOpen);
            trigger.setAttribute('aria-expanded', String(!isOpen));
        });

        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (!menu.contains(e.target) && menu.classList.contains('is-open')) {
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });

        // Close on Escape key
        menu.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && menu.classList.contains('is-open')) {
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.focus();
            }
        });
    });
});

