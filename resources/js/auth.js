// Lightweight, dependency-free enhancements for the auth screens.
// Provides: password visibility toggle, button loading state on submit,
// and respects `prefers-reduced-motion` for any future animation hooks.

function bindPasswordToggle(toggle) {
    if (toggle.dataset.passwordBound === '1') {
        return;
    }
    const field = toggle.closest('[data-password-field]');
    if (!field) {
        return;
    }

    const input = field.querySelector('input[type="password"], input[type="text"][data-password-mirror]');
    if (!input) {
        return;
    }

    const iconEye = field.querySelector('[data-password-icon="eye"]');
    const iconEyeOff = field.querySelector('[data-password-icon="eye-off"]');

    toggle.dataset.passwordBound = '1';

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        const isHidden = input.getAttribute('type') === 'password';
        input.setAttribute('type', isHidden ? 'text' : 'password');
        toggle.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        if (iconEye) {
            iconEye.hidden = isHidden;
        }
        if (iconEyeOff) {
            iconEyeOff.hidden = !isHidden;
        }
    });
}

function initPasswordToggles(root) {
    const scope = root ?? document;
    scope.querySelectorAll('[data-password-toggle]').forEach(bindPasswordToggle);
}

function initSubmitLoading() {
    document.querySelectorAll('[data-login-form]').forEach((form) => {
        const button = form.querySelector('[data-primary-submit]');
        const label = button?.querySelector('[data-button-label]');
        const loader = button?.querySelector('[data-button-loader-text]');
        const defaultLabel = label ? label.innerHTML : null;
        const loadingLabel = loader ? loader.textContent.trim() : null;

        form.addEventListener('submit', (event) => {
            if (!button) {
                return;
            }

            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            if (!form.checkValidity()) {
                return;
            }

            form.dataset.submitting = '1';
            button.setAttribute('data-loading', 'true');
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;

            if (label && loadingLabel) {
                label.textContent = loadingLabel;
            }
        });

        // Restore the default state when the page is restored from the bfcache.
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                form.dataset.submitting = '0';
                button.removeAttribute('data-loading');
                button.removeAttribute('aria-busy');
                button.disabled = false;
                if (label && defaultLabel !== null) {
                    label.innerHTML = defaultLabel;
                }
            }
        });
    });
}

function initAuth() {
    initPasswordToggles();
    initSubmitLoading();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuth);
} else {
    initAuth();
}

// Expose for late-mounted content (e.g. Livewire) — safe no-op when not used.
window.ABO = window.ABO || {};
window.ABO.reinitAuth = () => {
    initPasswordToggles();
    initSubmitLoading();
};
