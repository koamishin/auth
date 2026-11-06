<div
    x-data="{ selected: $wire.get('data.auth_background') || 'inherit' }"
    x-effect="selected = $wire.get('data.auth_background') || 'inherit'"
    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
>
    {{-- Inherit --}}
    <button
        type="button"
        @click="$wire.set('data.auth_background', 'inherit')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'inherit'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-16 rounded-lg border border-dashed border-gray-300 dark:border-white/20 bg-gray-50 dark:bg-white/5 flex items-center justify-center overflow-hidden">
            <x-filament::icon icon="heroicon-o-photo" class="w-4 h-4 text-gray-400" />
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-900 dark:text-white">Portal default</p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">No extra treatment</p>
        </div>
    </button>

    {{-- Gradient --}}
    <button
        type="button"
        @click="$wire.set('data.auth_background', 'gradient')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'gradient'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-16 rounded-lg border border-gray-100 dark:border-white/5 overflow-hidden" style="background: linear-gradient(180deg, color-mix(in oklab, var(--primary) 16%, white) 0%, white 80%)"></div>
        <div>
            <p class="text-xs font-semibold text-gray-900 dark:text-white">Soft gradient</p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">Tinted fade from the top</p>
        </div>
    </button>

    {{-- Spotlight --}}
    <button
        type="button"
        @click="$wire.set('data.auth_background', 'spotlight')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'spotlight'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-16 rounded-lg border border-gray-100 dark:border-white/5 overflow-hidden bg-gray-950 relative">
            <div class="absolute inset-0" style="background: radial-gradient(60% 60% at 50% 0%, rgb(255 255 255 / 0.22), transparent 70%)"></div>
        </div>
        <div>
            <p class="text-xs font-semibold text-gray-900 dark:text-white">Spotlight</p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">Glow behind the form</p>
        </div>
    </button>

    {{-- Grid --}}
    <button
        type="button"
        @click="$wire.set('data.auth_background', 'grid')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'grid'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div
            class="h-16 rounded-lg border border-gray-100 dark:border-white/5 overflow-hidden bg-white dark:bg-gray-950"
            style="background-image: linear-gradient(rgb(0 0 0 / 0.06) 1px, transparent 1px), linear-gradient(90deg, rgb(0 0 0 / 0.06) 1px, transparent 1px); background-size: 12px 12px"
        ></div>
        <div>
            <p class="text-xs font-semibold text-gray-900 dark:text-white">Subtle grid</p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">Faint blueprint lines</p>
        </div>
    </button>
</div>
