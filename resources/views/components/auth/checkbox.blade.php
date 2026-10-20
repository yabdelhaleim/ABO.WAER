@props([
    'name' => 'remember',
    'label' => 'Remember me',
    'checked' => false,
])

<label class="auth-checkbox">
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked(old($name, $checked))
        {{ $attributes }}
    />
    <span class="auth-checkbox__box" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
            <path d="M9.6 16.6 5.4 12.4 4 13.8l5.6 5.6 11-11L19.2 7z" fill="currentColor"/>
        </svg>
    </span>
    <span class="auth-checkbox__label">{{ $label }}</span>
</label>
