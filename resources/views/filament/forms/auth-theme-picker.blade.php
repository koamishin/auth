@php
    $themes = [
        'default' => ['name' => 'Default', 'primary' => '#1f1f23', 'accent' => '#f4f4f5'],
        'rose' => ['name' => 'Rose', 'primary' => '#e5484d', 'accent' => '#fddad9'],
        'ocean' => ['name' => 'Ocean', 'primary' => '#3d7fc4', 'accent' => '#d8eaf7'],
        'sage-garden' => ['name' => 'Sage Garden', 'primary' => '#679a71', 'accent' => '#cfe6cf'],
        'claude' => ['name' => 'Claude', 'primary' => '#d97757', 'accent' => '#f5e8dd'],
    ];
@endphp

<div
    x-data="{ selected: $wire.get('data.auth_color_theme') || 'inherit' }"
    x-effect="selected = $wire.get('data.auth_color_theme') || 'inherit'"
    class="grid grid-cols-2 gap-3 sm:grid-cols-3"
>
    {{-- Inherit --}}
    <button
        type="button"
        @click="$wire.set('data.auth_color_theme', 'inherit')"
        class="group relative flex items-center gap-3 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'inherit'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <span class="w-9 h-9 rounded-lg border border-dashed border-gray-300 dark:border-white/20 bg-gradient-to-br from-gray-100 to-gray-200 dark:from-white/10 dark:to-white/5 flex items-center justify-center shrink-0">
            <x-filament::icon icon="heroicon-o-swatch" class="w-4 h-4 text-gray-400" />
        </span>
        <div class="min-w-0">
            <p class="text-xs font-semibold text-gray-900 dark:text-white">Portal default</p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">Use organization theme</p>
        </div>
    </button>

    @foreach ($themes as $key => $theme)
        <button
            type="button"
            @click="$wire.set('data.auth_color_theme', '{{ $key }}')"
            class="group relative flex items-center gap-3 rounded-xl border p-3 text-left transition-all cursor-pointer"
            :class="selected === '{{ $key }}'
                ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
                : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
        >
            <span
                class="w-9 h-9 rounded-lg shrink-0 border border-black/5 dark:border-white/10"
                style="background: linear-gradient(135deg, {{ $theme['primary'] }} 0%, {{ $theme['primary'] }} 55%, {{ $theme['accent'] }} 55%, {{ $theme['accent'] }} 100%)"
            ></span>
            <div class="min-w-0">
                <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $theme['name'] }}</p>
                <p class="text-[10px] font-mono text-gray-500 dark:text-gray-400">{{ $theme['primary'] }}</p>
            </div>
        </button>
    @endforeach
</div>
