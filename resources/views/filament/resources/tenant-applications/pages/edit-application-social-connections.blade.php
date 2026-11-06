@php
    $app = $this->getRecord();
    $activeEnv = $this->data['environment'] ?? $app->environment;
    $isProd = $activeEnv === 'production';
    $states = $this->connectionStates();
    $active = collect($states)->filter(fn (array $state): bool => $state['resolved'])->count();
@endphp

<x-filament-panels::page>
    <div
        class="kms-settings min-w-0 w-full max-w-3xl mx-auto -mt-3"
        @keydown.window.prevent.ctrl.s="$wire.save()"
        @keydown.window.prevent.cmd.s="$wire.save()"
    >
        {{-- Application identity + environment scope --}}
        <header class="pb-6 border-b border-gray-200 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-x-8 gap-y-4">
                <div class="flex items-center gap-3.5 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center shrink-0">
                        <x-framework-logo :framework="$app->framework" class="w-6 h-6" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2 class="text-lg font-semibold text-gray-950 dark:text-white tracking-tight truncate">{{ $app->name }}</h2>
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold {{ $isProd ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $isProd ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                                {{ ucfirst($activeEnv) }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Let users sign in with a social account. {{ $active }} of {{ count($states) }} providers active.
                        </p>
                    </div>
                </div>

            </div>
        </header>

        <main class="pt-6">
            <form wire:submit="save" id="edit-social-connections-form" class="kms-settings-form">
                {{ $this->form }}
            </form>

            {{-- Sticky save bar --}}
            <div class="sticky bottom-4 z-10 mt-8 flex items-center justify-between gap-4 px-5 py-3 rounded-xl bg-gray-50/95 dark:bg-gray-900/95 backdrop-blur border border-gray-200 dark:border-white/10 shadow-lg">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Secrets are encrypted and never shown again.
                </span>
                <button
                    type="submit"
                    form="edit-social-connections-form"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-xs font-semibold text-white bg-primary-600 hover:bg-primary-500 shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="save" class="flex items-center gap-1.5">
                        <x-filament::icon icon="heroicon-m-check" class="w-3.5 h-3.5" />
                        Save changes
                    </span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        Saving…
                    </span>
                </button>
            </div>
        </main>
    </div>
</x-filament-panels::page>
