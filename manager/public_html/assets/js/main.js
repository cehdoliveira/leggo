/**
 * Leggo - UI Core
 */

document.addEventListener('DOMContentLoaded', function () {
    initializeTheme();
    initializeSmoothScroll();
});

function initializeSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== '#' && document.querySelector(href)) {
                e.preventDefault();
                document
                    .querySelector(href)
                    .scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
}

function initializeTheme() {
    const storageKey = 'theme';
    const root = document.documentElement;

    const applyTheme = (theme) => {
        const isDark = theme === 'dark';
        root.setAttribute('data-theme', isDark ? 'dark' : 'light');

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.innerHTML = isDark
                ? '<i class="bi bi-sun"></i><span class="d-none d-md-inline ms-1">Claro</span>'
                : '<i class="bi bi-moon-stars"></i><span class="d-none d-md-inline ms-1">Escuro</span>';
            button.setAttribute(
                'aria-label',
                isDark ? 'Ativar tema claro' : 'Ativar tema escuro',
            );
        });
    };

    const saved = localStorage.getItem(storageKey) || 'dark';
    localStorage.setItem(storageKey, saved);
    applyTheme(saved);

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-theme-toggle]');
        if (!button) {
            return;
        }

        const isDark = root.getAttribute('data-theme') === 'dark';
        const nextTheme = isDark ? 'light' : 'dark';
        localStorage.setItem(storageKey, nextTheme);
        applyTheme(nextTheme);
    });
}
