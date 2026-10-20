{{--
    Checkout field — reusable per-field renderer for the checkout form.

    Mirrors the structure of x-auth.field but with a `.checkout__*`
    namespace so the existing auth components stay untouched. Renders
    label + control + per-field error (icon + text, never color alone).
    Old values are preserved by `old($name)`.

    Props
    -----
    @param  string  $name           field name (required)
    @param  string  $label          human label (required)
    @param  string  $type           input type (default 'text')
    @param  string  $placeholder    placeholder text
    @param  string  $autocomplete   autocomplete attribute
    @param  string  $inputmode      inputmode attribute
    @param  string  $value          default value
    @param  bool    $required       mark the field required
    @param  array   $options        for <select>: [value => label, …]
    @param  bool    $textarea       render as <textarea> instead of <input>
    @param  int     $rows           textarea rows (default 3)
--}}

@props([
    'name'         => '',
    'label'        => '',
    'type'         => 'text',
    'placeholder'  => '',
    'autocomplete' => null,
    'inputmode'    => null,
    'value'        => null,
    'required'     => false,
    'options'      => [],
    'textarea'     => false,
    'rows'         => 3,
])

@php
    $hasError = $errors->any() && $errors->has($name);
    $oldValue = old($name, $value);
    $inputId  = 'checkout-field-' . $name;
    $errorId  = $inputId . '-error';
@endphp

<div
    class="checkout__field"
    data-checkout-field="{{ $name }}"
    @if ($hasError) data-error="true" @endif
>
    <label class="checkout__label" for="{{ $inputId }}">
        {{ $label }}
        @if ($required)
            <span class="checkout__required" aria-hidden="true">*</span>
        @endif
    </label>

    <div class="checkout__control">
        @if (!empty($options))
            <select
                id="{{ $inputId }}"
                name="{{ $name }}"
                class="checkout__input checkout__input--select"
                @if ($required) required @endif
                @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                @if ($hasError) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
            >
                @if ($placeholder !== '')
                    <option value="">{{ $placeholder }}</option>
                @endif
                @foreach ($options as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected((string) $oldValue === (string) $optValue)>
                        {{ $optLabel }}
                    </option>
                @endforeach
            </select>
        @elseif ($textarea)
            <textarea
                id="{{ $inputId }}"
                name="{{ $name }}"
                class="checkout__input checkout__input--textarea"
                rows="{{ $rows }}"
                @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                @if ($required) required @endif
                @if ($hasError) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
            >{{ $oldValue }}</textarea>
        @else
            <input
                id="{{ $inputId }}"
                name="{{ $name }}"
                type="{{ $type }}"
                class="checkout__input"
                value="{{ $oldValue }}"
                @if ($placeholder) placeholder="{{ $placeholder }}" @endif
                @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                @if ($inputmode) inputmode="{{ $inputmode }}" @endif
                @if ($required) required @endif
                @if ($hasError) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif
            />
        @endif
    </div>

    @if ($hasError)
        <p class="checkout__error" id="{{ $errorId }}" role="alert">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="checkout__error-icon">
                <path d="M12 2 1 21h22L12 2zm0 6 7.5 13h-15L12 8zm-1 4v4h2v-4h-2zm0 5v2h2v-2h-2z" fill="currentColor"/>
            </svg>
            <span>{{ $errors->first($name) }}</span>
        </p>
    @endif
</div>
