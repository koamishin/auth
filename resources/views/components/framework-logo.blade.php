@props([
    'framework' => 'nextjs',
    'class' => 'w-5 h-5',
])

@php
    $fw = strtolower((string) $framework);
@endphp

@if($fw === 'laravel')
    {{-- Official Laravel Logo --}}
    <svg viewBox="0 0 73 70" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="Laravel">
        <path d="M72.0999 15.6599L44.8299 0.089859C44.4799 -0.0201416 44.0799 -0.0301416 43.7299 0.089859L1.08994 15.6599C0.429941 15.9399 0.0099411 16.5899 0.0099411 17.2999V52.6999C0.0099411 53.4099 0.429941 54.0599 1.08994 54.3399L28.3599 69.9099C28.7099 70.0199 29.1099 70.0299 29.4599 69.9099L72.0999 54.3399C72.7599 54.0599 73.1799 53.4099 73.1799 52.6999V17.2999C73.1799 16.5899 72.7599 15.9399 72.0999 15.6599ZM42.8499 6.22986L64.2199 18.4399L51.3599 23.1199L29.9899 10.9099L42.8499 6.22986ZM4.34994 19.3499L25.7199 11.5499V35.9499L4.34994 43.7499V19.3499ZM30.0599 37.5299L51.4299 29.7299V54.1299L30.0599 61.9299V37.5299ZM45.6999 63.6399L34.3499 57.1499V33.4899L45.6999 37.6199V63.6399ZM68.8399 50.6499L47.4699 58.4499V34.0499L68.8399 26.2499V50.6499Z" fill="#FF2D20"/>
    </svg>
@elseif($fw === 'nextjs')
    {{-- Official Next.js Logo --}}
    <svg viewBox="0 0 180 180" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="Next.js">
        <mask id="nextjs-logo-mask-{{ md5($class) }}" mask-type="alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="180" height="180">
            <circle cx="90" cy="90" r="90" fill="black" />
        </mask>
        <g mask="url(#nextjs-logo-mask-{{ md5($class) }})">
            <circle cx="90" cy="90" r="90" fill="black" class="dark:fill-white" />
            <path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.168 149.508 157.52Z" fill="url(#paint0_linear_next-{{ md5($class) }})" />
            <rect x="115" y="54" width="12" height="72" fill="url(#paint1_linear_next-{{ md5($class) }})" />
        </g>
        <defs>
            <linearGradient id="paint0_linear_next-{{ md5($class) }}" x1="109" y1="116.5" x2="144.5" y2="160.5" gradientUnits="userSpaceOnUse">
                <stop stop-color="white" class="dark:stop-color-black" />
                <stop offset="1" stop-color="white" stop-opacity="0" />
            </linearGradient>
            <linearGradient id="paint1_linear_next-{{ md5($class) }}" x1="121" y1="54" x2="120.799" y2="106.875" gradientUnits="userSpaceOnUse">
                <stop stop-color="white" class="dark:stop-color-black" />
                <stop offset="1" stop-color="white" stop-opacity="0" />
            </linearGradient>
        </defs>
    </svg>
@elseif($fw === 'react')
    {{-- Official React Logo --}}
    <svg viewBox="-11.5 -10.23174 23 20.46348" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="React">
        <circle cx="0" cy="0" r="2.05" fill="#61DAFB"/>
        <g stroke="#61DAFB" stroke-width="1">
            <ellipse rx="11" ry="4.2"/>
            <ellipse rx="11" ry="4.2" transform="rotate(60)"/>
            <ellipse rx="11" ry="4.2" transform="rotate(120)"/>
        </g>
    </svg>
@elseif($fw === 'vue')
    {{-- Official Vue.js Logo --}}
    <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="Vue.js">
        <path d="M315.1 34.3 256 136.7 196.9 34.3H0l256 443.4L512 34.3z" fill="#41b883"/>
        <path d="M315.1 34.3 256 136.7 196.9 34.3h-94.5l153.6 266 153.6-266z" fill="#34495e"/>
    </svg>
@elseif($fw === 'nodejs')
    {{-- Official Node.js Logo --}}
    <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="Node.js">
        <linearGradient id="node_logo_grad-{{ md5($class) }}" x1="0" x2="512" y1="256" y2="256" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#3f8b3d"/>
            <stop offset="1" stop-color="#6abf4b"/>
        </linearGradient>
        <path d="M268.1 3.2c-7.5-4.3-16.7-4.3-24.2 0L44.6 119c-7.6 4.3-11.8 12.4-11.8 21v232c0 8.6 4.7 16.7 11.8 21L244 508.8c7.5 4.3 16.7 4.3 24.2 0L467.4 393c7.6-4.3 11.8-12.4 11.8-21V140c0-8.6-4.7-16.7-11.8-21z" fill="url(#node_logo_grad-{{ md5($class) }})"/>
        <path d="M343.9 400c10.3 16.8 23.7 29.2 47.5 29.2 19.9 0 32.7-10 32.7-23.7 0-16.5-13.1-22.3-35-31.9l-12-5.2c-34.7-14.8-57.8-33.3-57.8-72.5 0-36.1 27.5-63.6 70.5-63.6 30.6 0 52.6 10.6 68.4 38.5l-37.5 24.1c-8.2-14.8-17.1-20.6-31-20.6-14.1 0-23 8.9-23 20.6 0 14.4 8.9 20.3 29.6 29.2l12 5.2c40.9 17.5 64 35.4 64 75.6 0 43.3-34 67-79.7 67-44.7 0-73.5-21.3-87.7-49.2z" fill="#ffffff"/>
    </svg>
@elseif($fw === 'vanilla' || $fw === 'javascript')
    {{-- Official JavaScript Logo --}}
    <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="JavaScript">
        <path d="M0 0h512v512H0z" fill="#f7df1e"/>
        <path d="M343.9 400c10.3 16.8 23.7 29.2 47.5 29.2 19.9 0 32.7-10 32.7-23.7 0-16.5-13.1-22.3-35-31.9l-12-5.2c-34.7-14.8-57.8-33.3-57.8-72.5 0-36.1 27.5-63.6 70.5-63.6 30.6 0 52.6 10.6 68.4 38.5l-37.5 24.1c-8.2-14.8-17.1-20.6-31-20.6-14.1 0-23 8.9-23 20.6 0 14.4 8.9 20.3 29.6 29.2l12 5.2c40.9 17.5 64 35.4 64 75.6 0 43.3-34 67-79.7 67-44.7 0-73.5-21.3-87.7-49.2zM174 404.2c7.6 13.4 14.4 24.7 31 24.7 15.8 0 25.8-6.2 25.8-30.2V235.1h48.1v164.2c0 49.8-29.2 72.5-71.8 72.5-38.5 0-60.8-19.9-72.2-43.9z" fill="#000000"/>
    </svg>
@elseif($fw === 'mobile')
    {{-- Clean Mobile / Native Device Logo --}}
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }} aria-label="Mobile">
        <rect x="5" y="2" width="14" height="20" rx="3" ry="3" class="stroke-sky-500 fill-sky-500/10"/>
        <line x1="12" y1="18" x2="12.01" y2="18" class="stroke-sky-500"/>
    </svg>
@else
    {{-- Generic Framework Icon --}}
    <x-filament::icon icon="heroicon-o-cube" {{ $attributes->merge(['class' => $class]) }} />
@endif
