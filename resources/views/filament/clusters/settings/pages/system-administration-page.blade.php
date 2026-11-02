<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">System Tools & Infrastructure</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Core infrastructure management, security policies, audit records, and multi-tenant organization directories.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($this->getSystemNavigations() as $item)
                <div class="flex flex-col justify-between p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs hover:border-primary-500/50 transition-all">
                    <div>
                        <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center mb-3 text-primary-600 dark:text-primary-400">
                            <x-filament::icon :icon="$item['icon']" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ $item['title'] }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4">
                            {{ $item['description'] }}
                        </p>
                    </div>

                    <a
                        href="{{ $item['url'] }}"
                        class="inline-flex items-center justify-between w-full py-2 px-3 rounded-lg text-xs font-semibold bg-gray-50 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-white/10 transition-colors"
                    >
                        <span>Manage {{ $item['title'] }}</span>
                        <x-filament::icon icon="heroicon-m-arrow-right" class="w-3.5 h-3.5 text-gray-500" />
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
