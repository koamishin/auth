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
    $clientType = $this->data['client_type'] ?? ($config?->client_type ?? 'public');
@endphp

<x-filament-panels::page>
    <div
        class="min-w-0 w-full space-y-6 max-w-7xl mx-auto -mt-3"
        @keydown.window.prevent.ctrl.s="$wire.save()"
        @keydown.window.prevent.cmd.s="$wire.save()"
        x-data="{
            activeSection: window.location.hash ? window.location.hash.replace('#', '') : 'general',
            scrollToSection(id) {
                this.activeSection = id;
                const el = document.getElementById(id);
                if (el) {
                    const offset = 80;
                    const bodyRect = document.body.getBoundingClientRect().top;
                    const elementRect = el.getBoundingClientRect().top;
                    const elementPosition = elementRect - bodyRect;
                    const offsetPosition = elementPosition - offset;
                    window.scrollTo({
                        top: offsetPosition,
                        behavior: 'smooth'
                    });
                    history.replaceState(null, null, '#' + id);
                }
            },
            init() {
                if (window.location.hash) {
                    const target = window.location.hash.replace('#', '');
                    setTimeout(() => {
                        const el = document.getElementById(target);
                        if (el) {
                            const offset = 80;
                            const bodyRect = document.body.getBoundingClientRect().top;
                            const elementRect = el.getBoundingClientRect().top;
                            const elementPosition = elementRect - bodyRect;
                            const offsetPosition = elementPosition - offset;
                            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
                        }
                    }, 180);
                }
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            this.activeSection = entry.target.id;
                        }
                    });
                }, { rootMargin: '-10% 0px -70% 0px' });
                ['general', 'target-architecture', 'environment-callbacks', 'hosted-login-appearance', 'application-authentication-features', 'social-connections', 'danger-zone'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) observer.observe(el);
                });
            }
        }"
    >
        <!-- Top Application Settings Hero Header -->
        <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs p-6">
            <!-- Breadcrumbs -->
            <nav class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 mb-4 font-medium">
                <a href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('index') }}" class="hover:text-primary-600 transition-colors">Applications</a>
                <span>/</span>
                <a href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}" class="hover:text-primary-600 transition-colors truncate max-w-[180px]">{{ $app->name }}</a>
                <span>/</span>
                <span class="text-gray-900 dark:text-white font-semibold">Settings</span>
            </nav>

            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                <!-- App identity & core identifiers -->
                <div class="flex items-start gap-4">
                    <div class="w-13 h-13 rounded-2xl bg-gray-50 dark:bg-white/5 border border-gray-200/80 dark:border-white/10 flex items-center justify-center text-lg font-bold shadow-xs shrink-0 ring-4 ring-gray-100 dark:ring-white/5">
                        <x-framework-logo :framework="$app->framework" class="w-8 h-8" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-2xl font-bold text-gray-950 dark:text-white tracking-tight">{{ $app->name }} Settings</h1>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $isProd ? 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $isProd ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500' }}"></span>
                                {{ ucfirst($activeEnv) }} Sandbox
                            </span>
                        </div>

                        <!-- Identifier chips with click-to-copy -->
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <!-- App ID -->
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-mono bg-gray-100 dark:bg-white/5 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-white/10">
                                <span class="text-gray-400">ID:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $app->id }}</span>
                            </span>

                            <!-- Publishable Key -->
                            <div
                                x-data="{ copied: false }"
                                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] font-mono bg-gray-100 dark:bg-white/5 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-white/10 cursor-pointer hover:bg-gray-200 dark:hover:bg-white/10 transition-colors"
                                @click="navigator.clipboard.writeText('{{ $publishableKey }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                title="Click to copy publishable key"
                            >
                                <span class="text-gray-400">PK:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200 truncate max-w-[130px]">{{ $publishableKey }}</span>
                                <x-filament::icon x-show="!copied" icon="heroicon-m-clipboard-document" class="w-3 h-3 text-gray-400" />
                                <x-filament::icon x-show="copied" icon="heroicon-m-check" class="w-3 h-3 text-emerald-500" style="display: none;" />
                            </div>

                            <!-- OAuth Client ID -->
                            @if ($config?->oauth_client_id)
                                <div
                                    x-data="{ copied: false }"
                                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] font-mono bg-gray-100 dark:bg-white/5 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-white/10 cursor-pointer hover:bg-gray-200 dark:hover:bg-white/10 transition-colors"
                                    @click="navigator.clipboard.writeText('{{ $config->oauth_client_id }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                    title="Click to copy client ID"
                                >
                                    <span class="text-gray-400">Client ID:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 truncate max-w-[110px]">{{ $config->oauth_client_id }}</span>
                                    <x-filament::icon x-show="!copied" icon="heroicon-m-clipboard-document" class="w-3 h-3 text-gray-400" />
                                    <x-filament::icon x-show="copied" icon="heroicon-m-check" class="w-3 h-3 text-emerald-500" style="display: none;" />
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Header Actions & Controls -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <!-- Environment Switcher -->
                    <div role="group" aria-label="Active environment switcher" class="inline-flex rounded-xl bg-gray-100 p-1 dark:bg-white/5 border border-gray-200 dark:border-white/10">
                        <button
                            type="button"
                            wire:click="selectEnvironment('development')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ ! $isProd ? 'bg-white dark:bg-gray-800 text-amber-700 dark:text-amber-300 shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}"
                        >
                            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-3.5 h-3.5" />
                            <span>Development</span>
                        </button>
                        <button
                            type="button"
                            wire:click="selectEnvironment('production')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $isProd ? 'bg-white dark:bg-gray-800 text-emerald-700 dark:text-emerald-300 shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}"
                        >
                            <x-filament::icon icon="heroicon-o-check-badge" class="w-3.5 h-3.5" />
                            <span>Production</span>
                        </button>
                    </div>

                    <!-- Setup Guide Sheet Action -->
                    <button
                        type="button"
                        wire:click="mountAction('openSetupGuide')"
                        title="Open interactive setup guide"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-white/10 shadow-xs transition-colors cursor-pointer"
                    >
                        <x-filament::icon icon="heroicon-m-question-mark-circle" class="w-4 h-4 text-primary-500" />
                        <span>Setup Guide</span>
                    </button>

                    <!-- Overview Link -->
                    <a
                        href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-200 dark:border-white/10 shadow-xs transition-colors"
                    >
                        <x-filament::icon icon="heroicon-m-eye" class="w-4 h-4 text-gray-400" />
                        <span>Overview</span>
                    </a>

                    <!-- Save Changes Button -->
                    <button
                        type="submit"
                        form="edit-application-form"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="save" class="flex items-center gap-1.5">
                            <x-filament::icon icon="heroicon-m-check" class="w-3.5 h-3.5" />
                            <span>Save changes</span>
                            <span class="hidden sm:inline-block ml-1 text-[10px] px-1.5 py-0.5 rounded bg-primary-700/60 font-mono text-primary-100">⌘S</span>
                        </span>
                        <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span>Saving...</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- 2-Column Settings Dashboard Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Left Sticky Sidebar Settings Navigation -->
            <aside class="lg:col-span-3 lg:sticky lg:top-4 space-y-4">
                <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs p-3">
                    <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider px-3 pt-2 pb-1.5">
                        Settings Menu
                    </p>

                    <nav class="space-y-1">
                        <!-- General -->
                        <button
                            type="button"
                            @click="scrollToSection('general')"
                            :class="activeSection === 'general' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">General</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">Identity & profile</p>
                            </div>
                        </button>

                        <!-- Target Architecture -->
                        <button
                            type="button"
                            @click="scrollToSection('target-architecture')"
                            :class="activeSection === 'target-architecture' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-cpu-chip" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">Architecture</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">SDK & client type</p>
                            </div>
                        </button>

                        <!-- Environment & Callbacks -->
                        <button
                            type="button"
                            @click="scrollToSection('environment-callbacks')"
                            :class="activeSection === 'environment-callbacks' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-globe-alt" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">Environment & URLs</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">Redirects & callbacks</p>
                            </div>
                        </button>

                        <!-- Hosted Login Appearance -->
                        <button
                            type="button"
                            @click="scrollToSection('hosted-login-appearance')"
                            :class="activeSection === 'hosted-login-appearance' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-paint-brush" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">Hosted Login</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">Branding & themes</p>
                            </div>
                        </button>

                        <!-- Authentication Features -->
                        <button
                            type="button"
                            @click="scrollToSection('application-authentication-features')"
                            :class="activeSection === 'application-authentication-features' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">Authentication</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">Passkeys, SSO & consent</p>
                            </div>
                        </button>

                        <!-- Social Connections -->
                        <button
                            type="button"
                            @click="scrollToSection('social-connections')"
                            :class="activeSection === 'social-connections' ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 font-semibold' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5 font-medium'"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-o-share" class="w-4 h-4 shrink-0 transition-colors" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate leading-tight">Social Connections</p>
                                <p class="text-[10px] text-gray-400 dark:text-gray-500 truncate font-normal">OAuth providers</p>
                            </div>
                        </button>

                        <div class="pt-2 border-t border-gray-100 dark:border-white/5 mt-2">
                            <!-- Danger Zone -->
                            <button
                                type="button"
                                @click="scrollToSection('danger-zone')"
                                :class="activeSection === 'danger-zone' ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400 font-semibold' : 'text-red-600 dark:text-red-400 hover:bg-red-50/50 dark:hover:bg-red-500/5 font-medium'"
                                class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs transition-colors text-left group cursor-pointer"
                            >
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 shrink-0 text-red-500 transition-colors" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate leading-tight">Danger Zone</p>
                                    <p class="text-[10px] text-red-400/80 truncate font-normal">Delete application</p>
                                </div>
                            </button>
                        </div>
                    </nav>
                </div>

                <!-- Active Scope Summary Pillbox -->
                <div class="rounded-2xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs p-4 space-y-3">
                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider font-mono">
                        {{ ucfirst($activeEnv) }} Scope
                    </p>

                    <div class="space-y-2 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Security Mode</span>
                            <span class="font-medium text-gray-900 dark:text-gray-200 font-mono">{{ $clientType === 'confidential' ? 'Confidential' : 'Public (PKCE)' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Callback URLs</span>
                            <span class="font-medium text-gray-900 dark:text-gray-200 font-mono">{{ $callbacksCount }} registered</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Self-Registration</span>
                            <span class="font-medium {{ $allowSignups ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500' }}">{{ $allowSignups ? 'Enabled' : 'Disabled' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">OAuth Consent</span>
                            <span class="font-medium {{ $consentRequired ? 'text-primary-600 dark:text-primary-400' : 'text-gray-500' }}">{{ $consentRequired ? 'Required' : 'Seamless' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Integration Tip -->
                <div class="rounded-2xl bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 p-4 text-xs text-gray-600 dark:text-gray-400 space-y-2">
                    <div class="flex items-center gap-1.5 font-semibold text-gray-900 dark:text-white">
                        <x-filament::icon icon="heroicon-o-light-bulb" class="w-4 h-4 text-amber-500 shrink-0" />
                        <span>Developer Tip</span>
                    </div>
                    <p class="text-[11px] leading-relaxed">
                        Environment configurations remain isolated. Changing callback URLs in Development will not affect your Production users.
                    </p>
                </div>
            </aside>

            <!-- Right Main Form & Settings Cards Area -->
            <main class="lg:col-span-9 space-y-8">
                <!-- Informational Environment Scope Notice -->
                <div class="rounded-2xl border border-gray-200 dark:border-white/10 bg-gray-50/70 dark:bg-white/5 p-4 flex items-center justify-between gap-4 text-xs text-gray-600 dark:text-gray-300 shadow-xs">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 text-primary-500 shrink-0" />
                        <span>
                            You are configuring <strong>{{ ucfirst($activeEnv) }}</strong>. Any changes saved will update the {{ $activeEnv }} environment credentials and redirects.
                        </span>
                    </div>
                    <button
                        type="button"
                        wire:click="selectEnvironment('{{ $isProd ? 'development' : 'production' }}')"
                        class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline shrink-0"
                    >
                        Switch to {{ $isProd ? 'Development' : 'Production' }}
                    </button>
                </div>

                <!-- Main Filament Form Wrapped Cleanly -->
                <form wire:submit="save" id="edit-application-form" class="space-y-6">
                    {{ $this->form }}

                    <!-- Settings Form Action Footer -->
                    <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-4 p-4 rounded-2xl bg-white/90 dark:bg-gray-900/90 backdrop-blur-md border border-gray-200 dark:border-white/10 shadow-lg">
                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl font-semibold text-xs text-white bg-primary-600 hover:bg-primary-500 shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="save" class="flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-m-check" class="w-3.5 h-3.5" />
                                    <span>Save all settings</span>
                                </span>
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
                                class="px-4 py-2.5 rounded-xl text-xs font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors"
                            >
                                Cancel
                            </a>
                        </div>

                        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span class="hidden md:inline">Unsaved modifications apply to {{ ucfirst($activeEnv) }}</span>
                            <button
                                type="button"
                                wire:click="mountAction('openSetupGuide')"
                                class="inline-flex items-center gap-1.5 font-semibold text-primary-600 hover:underline dark:text-primary-400"
                            >
                                <x-filament::icon icon="heroicon-m-question-mark-circle" class="w-4 h-4" />
                                <span>Setup Guide</span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Dedicated Danger Zone Card -->
                <div id="danger-zone" class="rounded-2xl border border-red-500/20 bg-red-500/5 dark:bg-red-500/5 p-6 shadow-xs space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400 flex items-center justify-center shrink-0">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-bold text-gray-950 dark:text-white">Danger Zone</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Irreversible actions that directly impact your application and users.</p>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-red-200/50 dark:border-red-500/10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="space-y-0.5">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white">Delete this application</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 max-w-xl">
                                Permanently removes <strong>{{ $app->name }}</strong>, its client configurations, whitelisted redirect URLs, and revokes all active tokens.
                            </p>
                        </div>

                        <button
                            type="button"
                            wire:click="mountAction('delete')"
                            class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold bg-red-600 hover:bg-red-700 text-white shadow-xs transition-colors shrink-0 cursor-pointer"
                        >
                            <x-filament::icon icon="heroicon-m-trash" class="w-3.5 h-3.5" />
                            <span>Delete Application</span>
                        </button>
                    </div>
                </div>
            </main>
        </div>
    </div>
</x-filament-panels::page>
