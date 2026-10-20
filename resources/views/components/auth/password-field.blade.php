@props([
    'id' => null,
    'name' => 'password',
    'label' => 'Password',
    'placeholder' => 'Password',
    'autocomplete' => 'current-password',
    'autofocus' => false,
    'required' => true,
])

@php
    $fieldId = $id ?: 'auth-field-'.$name;
    $hasError = $errors->any() && $errors->has($name);
    $errorId = $hasError ? $fieldId.'-error' : null;
@endphp

<div class="auth-field auth-field--password" @if($hasError) data-error="true" @endif
     data-password-field>
    <label for="{{ $fieldId }}" class="sr-only">{{ $label }}</label>

    <div class="auth-field__control">
        <x-auth.field-icon name="lock" />

        <input
            id="{{ $fieldId }}"
            type="password"
            name="{{ $name }}"
            placeholder="{{ $placeholder }}"
            autocomplete="{{ $autocomplete }}"
            @if($autofocus) autofocus @endif
            @if($required) required @endif
            @if($errorId) aria-describedby="{{ $errorId }}" @endif
            @if($hasError) aria-invalid="true" @endif
        />

        <button
            type="button"
            class="auth-field__toggle"
            data-password-toggle
            aria-label="Show {{ strtolower($label) }}"
            aria-pressed="false"
            tabindex="0"
        >
            <span data-password-icon="eye">
                <x-auth.field-icon name="eye" />
            </span>
            <span data-password-icon="eye-off" hidden>
                <x-auth.field-icon name="eye-off" />
            </span>
        </button>
    </div>

    @if($hasError)
        <p id="{{ $errorId }}" class="auth-field__error" role="alert">
            {{ $errors->first($name) }}
        </p>
    @endif
</div>
