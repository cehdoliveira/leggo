/**
 * Leggo Site — UI Core
 */

document.addEventListener('DOMContentLoaded', function () {
    initTheme();
    initSmoothScroll();
    initFadeInObserver();
    initWelcomeDismiss();
    initTableScrollMasks();
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

// Welcome banner dismiss (T5)
function initWelcomeDismiss() {
    var banner = document.getElementById('welcome-banner');
    var dismissBtn = document.getElementById('welcome-dismiss');
    if (!banner || !dismissBtn) return;
    dismissBtn.addEventListener('click', function () {
        banner.style.transition = 'opacity 0.3s ease, max-height 0.4s ease';
        banner.style.opacity = '0';
        banner.style.maxHeight = '0';
        banner.style.overflow = 'hidden';
        setTimeout(function () { banner.remove(); }, 420);
    });
}

// Table scroll mask — remove mask when no overflow or scroll reached end
function initTableScrollMasks() {
    document.querySelectorAll('.ranking-table-wrap:not(.ranking-table-preview)').forEach(function (wrap) {
        function update() {
            var overflows = wrap.scrollWidth > wrap.clientWidth + 2;
            var atEnd     = wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 4;
            if (!overflows || atEnd) {
                wrap.classList.add('scrolled-end');
            } else {
                wrap.classList.remove('scrolled-end');
            }
        }
        update();
        wrap.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update, { passive: true });
    });
}
