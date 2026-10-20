@props([
    'type' => 'submit',
    'loadingText' => 'Signing in…',
])

<button {{ $attributes->merge(['type' => $type]) }} class="auth-btn auth-btn--primary" data-primary-submit>
    <span class="auth-btn__label" data-button-label>{{ $slot }}</span>
    <span class="auth-btn__spinner" data-button-spinner aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round" stroke-dasharray="14 50"/>
        </svg>
    </span>
    <span class="auth-btn__loader" data-button-loader-text hidden>{{ $loadingText }}</span>
</button>
