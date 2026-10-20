// ABO Size Guide — modal controller
// ------------------------------------------------------------------
// Self-contained, no global state. One modal per `[data-size-guide]`
// instance. The trigger is any element with `data-size-guide-open="<id>"`.
//
// Accessibility:
// - role="dialog" + aria-modal="true" + aria-labelledby on the title (set in Blade).
// - Focus moves into the dialog on open, is trapped while open, and
//   returns to the trigger on close.
// - Escape closes the dialog.
// - Click on the backdrop closes the dialog.
// - [data-product-size] on the page (if present) is used to mark the
//   matching row .is-active on open. If the attribute is missing or
//   its value doesn't match any row, no row is highlighted.
//
// Reduced motion is handled by CSS (no JS animation here).

const SELECTORS = {
    trigger:    '[data-size-guide-open]',
    modal:      '[data-size-guide]',
    dialog:     '[data-size-guide-dialog]',
    close:      '[data-size-guide-close]',
    row:        '[data-size-row]',
};

const activeInstances = new Set();

function getFocusable(root) {
    return Array.from(
        root.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((el) => el.offsetParent !== null);
}

function openModal(modal) {
    if (modal.dataset.sizeGuideOpen === '1') return;
    modal.dataset.sizeGuideOpen = '1';
    modal.hidden = false;
    document.documentElement.classList.add('size-guide-open');

    // Sync selected-size highlight: only mark a row active if the
    // page exposes [data-product-size] AND that value matches a row.
    syncSelectedSize(modal);

    // Move focus into the dialog. Prefer the close button, fall back
    // to the dialog itself.
    const dialog = modal.querySelector(SELECTORS.dialog);
    const focusTarget = modal.querySelector(SELECTORS.close) || dialog;
    if (focusTarget) focusTarget.focus({ preventScroll: true });

    // Bind per-modal listeners (kept in a closure so each modal is
    // independent).
    const onKey = (ev) => {
        if (ev.key === 'Escape') {
            ev.preventDefault();
            closeModal(modal);
            return;
        }
        if (ev.key === 'Tab') {
            trapFocus(ev, modal);
        }
    };
    modal._sizeGuideKey = onKey;
    document.addEventListener('keydown', onKey);

    activeInstances.add(modal);
}

function closeModal(modal) {
    if (modal.dataset.sizeGuideOpen !== '1') return;
    modal.dataset.sizeGuideOpen = '0';
    modal.hidden = true;
    document.documentElement.classList.remove('size-guide-open');

    if (modal._sizeGuideKey) {
        document.removeEventListener('keydown', modal._sizeGuideKey);
        modal._sizeGuideKey = null;
    }

    activeInstances.delete(modal);

    // Return focus to the trigger.
    const trigger = document.querySelector(`[data-size-guide-open="${modal.id}"]`);
    if (trigger) trigger.focus({ preventScroll: true });
}

function trapFocus(ev, modal) {
    const focusable = getFocusable(modal);
    if (focusable.length === 0) {
        ev.preventDefault();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (ev.shiftKey && active === first) {
        ev.preventDefault();
        last.focus();
    } else if (!ev.shiftKey && active === last) {
        ev.preventDefault();
        first.focus();
    }
}

function syncSelectedSize(modal) {
    const selected = document.querySelector('[data-product-size]');
    if (!selected) {
        // No size selector on the page (current state) → no row highlighted.
        return;
    }
    const value = (selected.dataset.productSize || selected.value || selected.textContent || '').trim();
    if (!value) return;
    const rows = modal.querySelectorAll(SELECTORS.row);
    rows.forEach((row) => row.classList.remove('is-active'));
    const match = Array.from(rows).find((row) => row.dataset.sizeRow === value);
    if (match) match.classList.add('is-active');
}

function onClick(ev) {
    const trigger = ev.target.closest(SELECTORS.trigger);
    if (trigger) {
        ev.preventDefault();
        const id = trigger.dataset.sizeGuideOpen;
        const modal = document.getElementById(id);
        if (modal) openModal(modal);
        return;
    }
    const closer = ev.target.closest(SELECTORS.close);
    if (closer) {
        ev.preventDefault();
        const modal = closer.closest(SELECTORS.modal);
        if (modal) closeModal(modal);
    }
}

function init() {
    document.addEventListener('click', onClick);
    // Lock body scroll when at least one modal is open.
    // (We use a class on <html> for the lock; CSS handles the actual
    //  overflow:hidden. We just need to make sure the class is removed
    //  when all modals are closed — the open()/close() functions above
    //  toggle the class, but a hard close (e.g. page navigation) is
    //  safe because the class will be removed on next paint.)
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
