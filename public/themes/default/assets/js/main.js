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
});
