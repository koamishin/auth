@props([
    'framework' => 'nextjs',
    'class' => 'w-5 h-5',
])

@php
    $fw = strtolower((string) $framework);
    $icon = match ($fw) {
        'laravel' => 'kms-laravel',
        'nextjs' => 'kms-nextjs',
        'react' => 'kms-reactjs',
        'vue' => 'kms-vuejs',
        'nodejs' => 'kms-nodejs',
        'vanilla', 'javascript' => 'kms-javascript',
        'mobile' => 'heroicon-o-device-phone-mobile',
        default => 'heroicon-o-cube',
    };
@endphp

<x-filament::icon :icon="$icon" {{ $attributes->merge(['class' => $class]) }} />
