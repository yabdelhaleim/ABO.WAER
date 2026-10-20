// ABO Storefront — navigation enhancements
// - Reduced-motion class flag on <html> (read by both intro and hero).
// - Mobile menu sheet toggle (button + Escape to close + focus management).
// - Auto-fills the "is-reduced-motion" class on <html> so the home page
//   can avoid running heavy animations when the user prefers reduced motion.

function syncReducedMotionClass() {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.documentElement.classList.toggle('is-reduced-motion', reduce);
}

function initMobileMenu() {
    const trigger = document.querySelector('[data-abo-mobile-menu-trigger]');
    const sheet = document.querySelector('[data-abo-mobile-menu]');
    if (!trigger || !sheet) {
        return;
    }

    const setOpen = (open) => {
        sheet.hidden = !open;
        sheet.classList.toggle('is-open', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        trigger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    };

    trigger.addEventListener('click', () => {
        const isOpen = !sheet.hidden;
        setOpen(!isOpen);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !sheet.hidden) {
            setOpen(false);
            trigger.focus();
        }
    });
}

function initNav() {
    syncReducedMotionClass();
    initMobileMenu();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNav);
} else {
    initNav();
}
