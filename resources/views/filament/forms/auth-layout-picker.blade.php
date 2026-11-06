<div
    x-data="{ selected: $wire.get('data.auth_layout') || 'inherit' }"
    x-effect="selected = $wire.get('data.auth_layout') || 'inherit'"
    class="grid grid-cols-1 gap-3 sm:grid-cols-3"
>
    {{-- Minimal --}}
    <button
        type="button"
        @click="$wire.set('data.auth_layout', 'simple')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'simple'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-20 rounded-lg border border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-black/20 flex flex-col items-center justify-center gap-1.5 overflow-hidden">
            <span class="block h-1.5 w-10 rounded-full bg-gray-300 dark:bg-white/25"></span>
            <span class="block h-4 w-16 rounded-md border border-gray-200 bg-white dark:border-white/10 dark:bg-white/10"></span>
            <span class="block h-4 w-16 rounded-md bg-gray-800 dark:bg-primary-500/80"></span>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-gray-900 dark:text-white">Minimal</p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">Centered, no chrome</p>
            </div>
            <span
                class="w-4 h-4 rounded-full border-2 flex items-center justify-center"
                :class="selected === 'simple' ? 'border-primary-600 bg-primary-600' : 'border-gray-300 dark:border-white/20'"
            >
                <span x-show="selected === 'simple'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
            </span>
        </div>
    </button>

    {{-- Card --}}
    <button
        type="button"
        @click="$wire.set('data.auth_layout', 'card')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'card'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-20 rounded-lg border border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-black/20 flex items-center justify-center overflow-hidden">
            <div class="w-24 rounded-lg border border-gray-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/10 p-2 flex flex-col gap-1.5">
                <span class="block h-1.5 w-10 rounded-full bg-gray-300 dark:bg-white/25 self-center"></span>
                <span class="block h-3.5 rounded-md border border-gray-200 dark:border-white/10"></span>
                <span class="block h-3.5 rounded-md bg-gray-800 dark:bg-primary-500/80"></span>
            </div>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-gray-900 dark:text-white">Card</p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">Form on a raised card</p>
            </div>
            <span
                class="w-4 h-4 rounded-full border-2 flex items-center justify-center"
                :class="selected === 'card' ? 'border-primary-600 bg-primary-600' : 'border-gray-300 dark:border-white/20'"
            >
                <span x-show="selected === 'card'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
            </span>
        </div>
    </button>

    {{-- Split --}}
    <button
        type="button"
        @click="$wire.set('data.auth_layout', 'split')"
        class="group relative flex flex-col gap-2.5 rounded-xl border p-3 text-left transition-all cursor-pointer"
        :class="selected === 'split'
            ? 'border-primary-500 ring-2 ring-primary-500/30 bg-primary-50/50 dark:bg-primary-500/5'
            : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20 bg-white dark:bg-white/5'"
    >
        <div class="h-20 rounded-lg border border-gray-100 dark:border-white/5 bg-gray-50 dark:bg-black/20 flex overflow-hidden">
            <div class="w-1/2 bg-primary-600/80 flex items-center justify-center">
                <span class="block h-1.5 w-8 rounded-full bg-white/70"></span>
            </div>
            <div class="w-1/2 flex flex-col items-center justify-center gap-1.5">
                <span class="block h-1.5 w-8 rounded-full bg-gray-300 dark:bg-white/25"></span>
                <span class="block h-3.5 w-12 rounded-md border border-gray-200 dark:border-white/10"></span>
                <span class="block h-3.5 w-12 rounded-md bg-gray-800 dark:bg-primary-500/80"></span>
            </div>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold text-gray-900 dark:text-white">Split</p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">Branded panel + form</p>
            </div>
            <span
                class="w-4 h-4 rounded-full border-2 flex items-center justify-center"
                :class="selected === 'split' ? 'border-primary-600 bg-primary-600' : 'border-gray-300 dark:border-white/20'"
            >
                <span x-show="selected === 'split'" class="w-1.5 h-1.5 rounded-full bg-white"></span>
            </span>
        </div>
    </button>
</div>
