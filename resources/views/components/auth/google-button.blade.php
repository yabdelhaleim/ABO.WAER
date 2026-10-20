@props([
    'href' => '#',
    'enabled' => false,
])

@php
    $label = trim((string) $slot);
    $label = $label !== '' ? $label : 'Continue with Google';
@endphp

@if($enabled)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'auth-btn auth-btn--google']) }}>
        <x-auth.google-icon />
        <span>{{ $label }}</span>
    </a>
@else
    <button type="button" disabled
        {{ $attributes->merge(['class' => 'auth-btn auth-btn--google auth-btn--disabled']) }}
        title="Google sign-in is not configured for this store yet."
        aria-disabled="true">
        <x-auth.google-icon />
        <span>{{ $label }}</span>
    </button>
@endif
