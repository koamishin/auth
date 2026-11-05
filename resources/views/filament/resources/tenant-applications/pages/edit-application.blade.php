@php
    $app = $this->getRecord();
    $tenant = $app->tenant;
    $activeEnv = $this->data['environment'] ?? $app->environment;
    $isProd = $activeEnv === 'production';
    $config = $app->environments()->where('environment', $activeEnv)->first();
    $publishableKey = $config?->publishable_key ?? $app->getPublishableKey();
    $callbacksCount = count($config?->redirect_uris ?? []);
    $allowSignups = (bool) ($this->data['allow_signups'] ?? ($config?->getSetting('allow_signups', false) === true));
    $consentRequired = (bool) ($this->data['oauth_consent_enabled'] ?? ($config?->getSetting('oauth_consent_enabled', false) === true));
@endphp

<x-filament-panels::page>
    <div class="min-w-0 w-full space-y-6 max-w-7xl mx-auto -mt-2">
        <!-- Top Application Configuration Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-lg font-bold shadow-xs shrink-0">
                    <x-framework-logo :framework="$app->framework" class="w-7 h-7" />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h1 class="text-xl font-bold text-gray-950 dark:text-white tracking-tight">Configure {{ $app->name }}</h1>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $isProd ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $isProd ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500' }}"></span>
                            {{ ucfirst($activeEnv) }}
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5 truncate">
                        Publishable key: <span class="text-gray-700 dark:text-gray-300">{{ $publishableKey }}</span>
                    </p>
                </div>
            </div>

            <!-- Top Action Controls & Quick Environment Switcher -->
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Environment Toggle Buttons -->
                <div role="group" aria-label="Active environment" class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-white/5 border border-gray-200 dark:border-white/10">
                    <button
                        type="button"
                        wire:click="selectEnvironment('development')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all {{ ! $isProd ? 'bg-white dark:bg-gray-800 text-amber-700 dark:text-amber-300 shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}"
                    >
                        <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-3.5 h-3.5" />
                        <span>Development</span>
                    </button>
                    <button
                        type="button"
                        wire:click="selectEnvironment('production')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all {{ $isProd ? 'bg-white dark:bg-gray-800 text-emerald-700 dark:text-emerald-300 shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}"
                    >
                        <x-filament::icon icon="heroicon-o-check-badge" class="w-3.5 h-3.5" />
                        <span>Production</span>
                    </button>
                </div>

                <!-- Setup Guide Slide-Over Button -->
                <button
                    type="button"
                    wire:click="mountAction('openSetupGuide')"
                    title="Open Setup Guide"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-question-mark-circle" class="w-4 h-4 text-primary-500" />
                    <span>Setup Guide</span>
                </button>

                <!-- View Overview Link -->
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-eye" class="w-3.5 h-3.5 text-gray-500" />
                    <span>Overview</span>
                </a>

                <!-- Header Save Button -->
                <button
                    type="submit"
                    form="edit-application-form"
                    class="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-lg text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors cursor-pointer"
                >
                    <x-filament::icon icon="heroicon-m-check" class="w-3.5 h-3.5" />
                    <span>Save changes</span>
                </button>
            </div>
        </div>

        <!-- Environment Scope Banner & Status Strip -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="p-3.5 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-xs flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg {{ $isProd ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400' }} flex items-center justify-center shrink-0">
                    <x-filament::icon :icon="$isProd ? 'heroicon-o-check-badge' : 'heroicon-o-wrench-screwdriver'" class="w-4 h-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider font-mono">Scope</p>
                    <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ ucfirst($activeEnv) }} Sandbox</p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-xs flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                    <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider font-mono">Callbacks</p>
                    <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $callbacksCount }} {{ \Illuminate\Support\Str::plural('URI', $callbacksCount) }} registered</p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-xs flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg {{ $allowSignups ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-gray-100 dark:bg-white/5 text-gray-500' }} flex items-center justify-center shrink-0">
                    <x-filament::icon icon="heroicon-o-user-plus" class="w-4 h-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider font-mono">Self-Signup</p>
                    <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $allowSignups ? 'Open for all' : 'Invitation only' }}</p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-xs flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg {{ ! $consentRequired ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' }} flex items-center justify-center shrink-0">
                    <x-filament::icon icon="heroicon-o-sparkles" class="w-4 h-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wider font-mono">OAuth Prompt</p>
                    <p class="text-xs font-semibold text-gray-900 dark:text-white truncate">{{ ! $consentRequired ? 'Seamless (Skipped)' : 'Consent Prompt On' }}</p>
                </div>
            </div>
        </div>

        <!-- Informational banner -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/80 dark:bg-white/5 p-4 flex items-center gap-3 text-xs text-gray-600 dark:text-gray-300 shadow-xs">
            <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 text-primary-500 shrink-0" />
            <span>
                Configuring <strong>{{ ucfirst($activeEnv) }}</strong>. Callback URLs, signup policy, authentication features, and appearance settings apply directly to this environment.
            </span>
        </div>

        <!-- Main Form Content -->
        <form wire:submit="save" id="edit-application-form" class="space-y-6">
            {{ $this->form }}

            <div class="flex items-center justify-between gap-4 pt-4 border-t border-gray-200 dark:border-white/10">
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl font-semibold text-xs text-white bg-primary-600 hover:bg-primary-500 shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="save">Save configuration</span>
                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span>Saving changes...</span>
                        </span>
                    </button>

                    <a
                        href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                        class="px-4 py-2 rounded-xl text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors"
                    >
                        Cancel
                    </a>
                </div>

                <button
                    type="button"
                    wire:click="mountAction('openSetupGuide')"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
                >
                    <x-filament::icon icon="heroicon-m-question-mark-circle" class="w-4 h-4" />
                    <span>Need integration help? Open Setup Guide</span>
                </button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
