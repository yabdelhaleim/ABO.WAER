@props([
    'id' => null,
    'name',
    'type' => 'text',
    'value' => null,
    'placeholder' => '',
    'icon' => 'user',
    'autocomplete' => null,
    'autofocus' => false,
    'required' => true,
    'inputmode' => null,
    'describedBy' => null,
])

@php
    $fieldId = $id ?: 'auth-field-'.$name;
    $hasError = $errors->any() && $errors->has($name);
    $errorId = $hasError ? $fieldId.'-error' : null;
    $descriptions = array_filter([$describedBy, $errorId]);
@endphp

<div class="auth-field" @if($hasError) data-error="true" @endif>
    <label for="{{ $fieldId }}" class="sr-only">{{ ucfirst($name) }}</label>

    <div class="auth-field__control">
        <x-auth.field-icon :name="$icon" />

        <input
            id="{{ $fieldId }}"
            type="{{ $type }}"
            name="{{ $name }}"
            value="{{ old($name, $value) }}"
            placeholder="{{ $placeholder }}"
            @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if($autofocus) autofocus @endif
            @if($required) required @endif
            @if($inputmode) inputmode="{{ $inputmode }}" @endif
            @if(!empty($descriptions)) aria-describedby="{{ implode(' ', $descriptions) }}" @endif
            @if($hasError) aria-invalid="true" @endif
            {{ $attributes->except(['id','name','type','value','placeholder','autocomplete','autofocus','required','inputmode']) }}
        />
    </div>

    @if($hasError)
        <p id="{{ $errorId }}" class="auth-field__error" role="alert">
            {{ $errors->first($name) }}
        </p>
    @endif
</div>
