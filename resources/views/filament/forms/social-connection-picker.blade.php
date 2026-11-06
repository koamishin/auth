@php
    $options = [
        [
            'value' => 'inherit',
            'title' => 'Use Portal connection',
            'description' => $portalEnabled
                ? 'Shared credentials managed by the organization.'
                : 'Not configured for this organization yet.',
            'available' => $portalEnabled,
        ],
        [
            'value' => 'disabled',
            'title' => 'Disabled',
            'description' => 'Hide this provider from this application.',
            'available' => true,
        ],
        [
            'value' => 'custom',
            'title' => 'Application credentials',
            'description' => 'Use this application\'s own client ID and secret.',
            'available' => true,
        ],
    ];
@endphp

<div
    x-data="{ selected: $wire.get('data.{{ $prefix }}_mode') || 'inherit' }"
    x-effect="selected = $wire.get('data.{{ $prefix }}_mode') || 'inherit'"
    class="grid grid-cols-1 gap-3 sm:grid-cols-3"
>
    @foreach ($options as $option)
        <button
            type="button"
            @click="$wire.set('data.{{ $prefix }}_mode', '{{ $option['value'] }}')"
            class="group relative flex flex-col gap-1.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
            :class="selected === '{{ $option['value'] }}'
                ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
                : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-gray-900 dark:text-white">{{ $option['title'] }}</span>
                <span
                    class="w-4 h-4 shrink-0 rounded-full border-2 flex items-center justify-center"
                    :class="selected === '{{ $option['value'] }}' ? 'border-primary-600 bg-primary-600' : 'border-gray-300 dark:border-white/20'"
                >
                    <span x-show="selected === '{{ $option['value'] }}'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
                </span>
            </div>
            <p class="text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                {{ $option['description'] }}
            </p>
            @if ($option['value'] === 'inherit')
                <span class="inline-flex w-fit items-center gap-1 text-[10px] font-medium {{ $option['available'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                    <span class="w-1 h-1 rounded-full {{ $option['available'] ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    {{ $option['available'] ? 'Available' : 'Unavailable' }}
                </span>
            @endif
        </button>
    @endforeach
</div>
