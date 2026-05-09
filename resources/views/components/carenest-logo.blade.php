@props([
    'variant' => 'full',  // full | icon | full-white | icon-white
    'class' => 'h-8 w-auto',
    'alt' => 'CareNest',
])
@php
    $src = match($variant) {
        'full-white' => asset('img/logo/carenest-full-white.png'),
        'icon'       => asset('img/logo/carenest-icon.png'),
        'icon-white' => asset('img/logo/carenest-icon-white.png'),
        default      => asset('img/logo/carenest-full-color.png'),
    };
@endphp
<img src="{{ $src }}" alt="{{ $alt }}" {{ $attributes->merge(['class' => $class]) }} loading="lazy" />
