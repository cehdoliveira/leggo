/**
 * Leggo Site — UI Core
 */

document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initSmoothScroll();
    initFadeInObserver();
});

// Theme toggle — syncs icon on all [data-theme-toggle] buttons
function initTheme() {
    var root = document.documentElement;

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
            }
            btn.setAttribute('aria-label', theme === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro');
        });
    }

    // Inline script in head already set data-theme; read it as source of truth
    var current = root.getAttribute('data-theme') || 'dark';
    applyTheme(current);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (!btn) return;
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme', next);
        applyTheme(next);
    });
}

function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            var href = this.getAttribute('href');
            if (href !== '#' && document.querySelector(href)) {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
}

// Fade-in sections as they enter viewport
function initFadeInObserver() {
    var targets = document.querySelectorAll('.animate-fadein');
    if (!targets.length || !('IntersectionObserver' in window)) {
        targets.forEach(function (el) { el.classList.remove('animate-pending'); });
        return;
    }
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.remove('animate-pending');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    targets.forEach(function (el) { observer.observe(el); });
}
