<x-filament-panels::page.simple>
    @if (\App\Models\Tenant::query()->doesntExist())
        <nav aria-label="Setup progress" class="mb-6">
            <ol class="grid grid-cols-3 gap-2">
                <li class="flex flex-col gap-2">
                    <div class="h-[3px] w-full rounded-full bg-gray-950 dark:bg-white"></div>
                    <div class="flex items-center gap-1.5">
                        <span class="flex size-4 shrink-0 items-center justify-center rounded-full bg-gray-950 text-[10px] font-medium text-white dark:bg-white dark:text-gray-950">
                            ✓
                        </span>
                        <span class="truncate text-xs font-medium text-gray-950 dark:text-white">
                            Instance
                        </span>
                    </div>
                </li>
                <li class="flex flex-col gap-2">
                    <div class="h-[3px] w-full rounded-full bg-gray-950 dark:bg-white"></div>
                    <div class="flex items-center gap-1.5">
                        <span class="flex size-4 shrink-0 items-center justify-center rounded-full bg-gray-950 text-[10px] font-medium text-white dark:bg-white dark:text-gray-950">
                            ✓
                        </span>
                        <span class="truncate text-xs font-medium text-gray-950 dark:text-white">
                            Admin
                        </span>
                    </div>
                </li>
                <li class="flex flex-col gap-2">
                    <div class="h-[3px] w-full rounded-full bg-gray-950 dark:bg-white"></div>
                    <div class="flex items-center gap-1.5">
                        <span class="flex size-4 shrink-0 items-center justify-center rounded-full bg-gray-950 text-[10px] font-medium text-white dark:bg-white dark:text-gray-950">
                            3
                        </span>
                        <span class="truncate text-xs font-medium text-gray-950 dark:text-white">
                            Organization
                        </span>
                    </div>
                </li>
            </ol>
        </nav>
    @endif

    {{ $this->content }}
</x-filament-panels::page.simple>
