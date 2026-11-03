@php
    $app = $this->getRecord();
    $tenant = $app->tenant;
    $isProd = $app->environment === 'production';
    $configuration = $app->getEnvironmentConfig();
    $users = $app->users()->latest('tenant_application_user.created_at')->limit(8)->get();
@endphp

<x-filament-panels::page>
    <div class="min-w-0 w-full space-y-6 max-w-7xl mx-auto -mt-2">
        @if($this->newApplicationCredentials)
            <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" x-data="{ copiedEnvSecret: {} }">
                <p class="font-semibold text-base">Save your application credentials</p>
                <p class="mt-1 text-xs opacity-90">Secret keys are shown only once upon creation or rotation. Store them securely on your server.</p>
                @foreach($this->newApplicationCredentials as $environment => $credentials)
                    <div class="mt-3 p-3 rounded-lg bg-black/5 dark:bg-black/30 border border-amber-200 dark:border-amber-800/40">
                        <p class="font-bold text-xs uppercase tracking-wider">{{ ucfirst($environment) }} Environment</p>
                        <p class="font-mono text-xs mt-1 break-all">Publishable key: {{ $credentials['publishable_key'] }}</p>
                        @if($credentials['secret_key'])
                            <div class="flex items-center justify-between mt-1 gap-2">
                                <p class="font-mono text-xs break-all text-emerald-800 dark:text-emerald-300">Secret key: {{ $credentials['secret_key'] }}</p>
                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText('{{ $credentials['secret_key'] }}'); copiedEnvSecret['{{ $environment }}'] = true; setTimeout(() => copiedEnvSecret['{{ $environment }}'] = false, 2000)"
                                    class="shrink-0 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-amber-100"
                                >
                                    <span x-text="copiedEnvSecret['{{ $environment }}'] ? 'Copied!' : 'Copy Secret'"></span>
                                </button>
                            </div>
                        @else
                            <p class="text-xs text-gray-500 mt-1">No secret key available.</p>
                        @endif
                    </div>
                @endforeach
                <button type="button" wire:click="dismissNewCredentials" class="mt-4 text-xs font-semibold underline underline-offset-2">
                    I’ve saved these keys
                </button>
            </div>
        @endif

        @php
            $regenSecret = $this->regeneratedSecret;
            $rotatedSecret = $this->rotatedSecretKey;
        @endphp
        @if($rotatedSecret)
            <div role="status" class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                <p class="font-bold">Secret key rotated ({{ ucfirst($rotatedSecret['environment']) }})</p>
                <p>Copy this key now. It cannot be retrieved later and must only be stored on your server.</p>
                <code class="block break-all rounded-lg bg-black/60 p-3 text-emerald-400">{{ $rotatedSecret['secret_key'] }}</code>
            </div>
        @endif
        @if($regenSecret)
            <div role="status" class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100 space-y-3" x-data="{ copiedRegen: false }">
                <div class="flex items-center justify-between">
                    <p class="font-bold flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-key" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                        <span>OAuth Client Secret Rotated ({{ ucfirst($regenSecret['environment']) }})</span>
                    </p>
                    <button
                        type="button"
                        @click="navigator.clipboard.writeText('{{ $regenSecret['secret'] }}'); copiedRegen = true; setTimeout(() => copiedRegen = false, 2000)"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-amber-100 hover:bg-amber-300 dark:hover:bg-amber-700 transition-colors"
                    >
                        <span x-text="copiedRegen ? 'Copied!' : 'Copy Secret'"></span>
                    </button>
                </div>
                <p>New secret generated. Store it securely in your <code>.env</code> file now:</p>
                <div class="p-3 rounded-lg bg-black/60 text-emerald-400 font-mono text-xs break-all select-all">
                    {{ $regenSecret['secret'] }}
                </div>
            </div>
        @endif
        <!-- Top Application Sub-Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center text-lg font-bold shadow-xs">
                    @if($app->framework === 'nextjs')
                        <span class="font-mono">▲</span>
                    @elseif($app->framework === 'laravel')
                        <span class="text-red-500 font-mono">Lv</span>
                    @elseif($app->framework === 'vue')
                        <span class="text-emerald-500 font-mono">V</span>
                    @elseif($app->framework === 'react')
                        <span class="text-cyan-500 font-mono">⚛</span>
                    @elseif($app->framework === 'nodejs')
                        <span class="text-amber-500 font-mono text-sm">Node</span>
                    @elseif($app->framework === 'mobile')
                        <span class="text-sky-500 font-mono">M</span>
                    @elseif($app->framework === 'vanilla')
                        <span class="text-primary-500 font-mono">JS</span>
                    @else
                        <x-filament::icon icon="heroicon-o-cube" class="w-6 h-6 text-gray-500 dark:text-gray-400" />
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h1 class="text-xl font-bold text-gray-950 dark:text-white tracking-tight">{{ $app->name }}</h1>
                        @if($isProd)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Production
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Development
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5 truncate">
                        Publishable key: <span class="text-gray-700 dark:text-gray-300">{{ $configuration->publishable_key }}</span>
                    </p>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex flex-wrap items-center gap-2.5" x-data="{ copied: false }">
                <form method="POST" action="{{ route('filament.admin.toggle-environment', ['tenant' => $tenant, 'application' => $app, 'environment' => $isProd ? 'development' : 'production']) }}">
                    @csrf
                <button type="submit"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-arrow-path" class="w-3.5 h-3.5 text-gray-500" />
                    Switch to {{ $isProd ? 'Development' : 'Production' }}
                </button>
                </form>

                <button
                    type="button"
                    @click="navigator.clipboard.writeText(@js($configuration->publishable_key)); copied = true; setTimeout(() => copied = false, 2000)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-white hover:bg-gray-50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copied ? 'Copied key!' : 'Copy Publishable Key'"></span>
                </button>

                <button
                    type="button"
                    wire:click="mountAction('rotateSecretKey')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-amber-50 hover:bg-amber-100 dark:bg-amber-500/10 dark:hover:bg-amber-500/20 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-key" class="w-3.5 h-3.5 text-amber-500" />
                    <span>Rotate Secret Key</span>
                </button>

                @if(! $isProd)
                    <form method="POST" action="{{ route('filament.admin.toggle-environment', ['tenant' => $tenant, 'application' => $app, 'environment' => 'production']) }}">
                        @csrf
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white shadow-xs transition-colors"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Configure production
                    </button>
                    </form>
                @endif
            </div>
        </div>

        @livewire(\App\Filament\Widgets\ApplicationQuickstartWidget::class, [
            'record' => $app,
        ], key('quickstart-guide-' . $app->id . '-' . $app->environment . '-' . $app->updated_at?->format('U.u')))

        <!-- 4 Quick-Action Insight Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" x-data="{ copiedCmd: false, copiedSkills: false }">
            <!-- Card 1: Build faster with agents -->
            <div class="flex flex-col justify-between p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs hover:border-primary-500/50 transition-all">
                <div>
                    <div class="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 flex items-center justify-center mb-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Build with Agents</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4">
                        Install the official SDK to configure and deploy auth workflows with coding agents.
                    </p>
                </div>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ $app->getInstallCommand() }}'); copiedCmd = true; setTimeout(() => copiedCmd = false, 2000)"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-gray-50 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-white/10 transition-colors"
                >
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copiedCmd ? 'Copied!' : 'Copy install command'"></span>
                </button>
            </div>

            <!-- Card 2: Set up Organizations -->
            <div class="flex flex-col justify-between p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs hover:border-primary-500/50 transition-all">
                <div>
                    <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center mb-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Set up Organizations</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4">
                        Build multi-tenant apps with B2B Authentication and isolated tenant directories.
                    </p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\Tenants\TenantResource::getUrl('index') }}"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-gray-50 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-white/10 transition-colors text-center"
                >
                    Explore Orgs →
                </a>
            </div>

            <!-- Card 3: Go to production -->
            <div class="flex flex-col justify-between p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs hover:border-primary-500/50 transition-all">
                <div>
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Go to production</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4">
                        Take your application live and connect production HTTPS domain callbacks.
                    </p>
                </div>
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('edit', ['record' => $app]) }}"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-gray-50 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-white/10 transition-colors text-center"
                >
                    Configure Env 🚀
                </a>
            </div>

            <!-- Card 4: Install Agent Skills -->
            <div class="flex flex-col justify-between p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs hover:border-primary-500/50 transition-all">
                <div>
                    <div class="w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center mb-3">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">Agent Skills</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed mb-4">
                        Equip your AI coding agents with specialized Koamishin authentication knowledge.
                    </p>
                </div>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText('npx @koamishin/skills add auth'); copiedSkills = true; setTimeout(() => copiedSkills = false, 2000)"
                    class="w-full inline-flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold bg-gray-50 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-white/10 transition-colors"
                >
                    <svg class="w-3.5 h-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span x-text="copiedSkills ? 'Copied!' : 'Copy install command'"></span>
                </button>
            </div>
        </div>

        <!-- Development Info Banner -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4 flex items-center gap-3 text-xs text-gray-700 dark:text-gray-300 shadow-xs">
            <svg class="w-4 h-4 text-primary-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>
                @if($isProd)
                    <strong>Production Instance:</strong> Live authentication requests are processed with strict SSL and active JWKS key verification.
                @else
                    <strong>Development Instance:</strong> Use this instance for internal testing and local sandboxes. When you're ready to invite real users, create or switch to your production instance.
                @endif
            </span>
        </div>

        <!-- API Keys Card -->
        <div id="api-keys" class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs dark:border-white/10 dark:bg-gray-900" x-data="{ copiedPk: false }">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-gray-950 dark:text-white tracking-tight">API Keys ({{ ucfirst($app->environment) }})</h2>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Publishable keys identify this environment in browser or mobile clients. Secret keys grant full server-side management access—never expose them in client code.
                    </p>
                </div>
                <span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 py-0.5 text-[11px] font-medium text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    {{ $configuration->isConnected() ? ($configuration->client_type === 'confidential' ? 'Confidential client' : 'Public PKCE client') : 'Callback not registered' }}
                </span>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="rounded-lg border border-gray-200 bg-gray-50/70 p-3.5 dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">Publishable key</span>
                        <button type="button" @click="navigator.clipboard.writeText(@js($configuration->publishable_key)); copiedPk = true; setTimeout(() => copiedPk = false, 2000)"
                            class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                            <span x-text="copiedPk ? 'Copied!' : 'Copy'"></span>
                        </button>
                    </div>
                    <code class="mt-1.5 block break-all font-mono text-xs text-gray-800 dark:text-gray-200">{{ $configuration->publishable_key }}</code>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50/70 p-3.5 dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">Secret key</span>
                        <button type="button" wire:click="mountAction('rotateSecretKey')"
                            class="text-xs font-semibold text-amber-600 hover:underline dark:text-amber-400">
                            {{ $configuration->secret_key_prefix ? 'Rotate secret key' : 'Create secret key' }}
                        </button>
                    </div>
                    <code class="mt-1.5 block font-mono text-xs text-gray-600 dark:text-gray-300">{{ $configuration->secret_key_prefix ?? 'Not issued — click Create secret key' }}</code>
                </div>
            </div>

            @if($configuration->oauth_client_id)
                <details class="mt-3.5 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                    <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-300">Legacy OAuth credentials</summary>
                    <p class="mt-2 break-all">Client ID: <code class="text-gray-800 dark:text-gray-200">{{ $configuration->oauth_client_id }}</code></p>
                    <p class="mt-1">Existing OAuth client IDs and secrets continue to work independently of the new keys.</p>
                    @if($configuration->oauthClient?->confidential())
                        <button type="button" wire:click="mountAction('regenerateSecret')" class="mt-2 font-semibold text-amber-600 hover:underline dark:text-amber-400">
                            Rotate OAuth client secret
                        </button>
                    @endif
                </details>
            @endif
        </div>

        <!-- Active Users Table -->
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 overflow-hidden shadow-xs">
            <div class="p-5 border-b border-gray-100 dark:border-white/5 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-gray-900 dark:text-white tracking-tight">Active Users ({{ ucfirst($app->environment) }})</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ $app->environment === 'production' ? 'Production users enrolled in live authentication' : 'Sandbox development users isolated from production' }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-400">
                        {{ $users->count() }} enrolled
                    </span>
                    <a
                        href="{{ \App\Filament\Resources\Users\UserResource::getUrl('index') }}"
                        class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline inline-flex items-center gap-1"
                    >
                        View all directory →
                    </a>
                </div>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse($users as $user)
                    <div class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 flex items-center justify-center text-xs font-bold shadow-xs">
                                {{ strtoupper(substr($user->name, 0, 2)) }}
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-900 dark:text-white">{{ $user->name }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-8 text-xs text-gray-500 dark:text-gray-400">
                            <div>
                                <span class="text-gray-400 dark:text-gray-500 block text-[10px] uppercase font-mono tracking-wider">Status</span>
                                @if($user->isBanned())
                                    <span class="text-red-600 dark:text-red-400 font-medium">Banned</span>
                                @else
                                    <span class="text-emerald-600 dark:text-emerald-400 font-medium">Active</span>
                                @endif
                            </div>
                            <div>
                                <span class="text-gray-400 dark:text-gray-500 block text-[10px] uppercase font-mono tracking-wider">Joined</span>
                                <span class="text-gray-700 dark:text-gray-300">{{ $user->created_at?->format('M j, Y') ?? '—' }}</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-xs text-gray-400">
                        No users registered yet.
                    </div>
                @endforelse
            </div>

            <div class="p-3 bg-gray-50 dark:bg-white/5 border-t border-gray-100 dark:border-white/5 text-[11px] text-gray-500 dark:text-gray-400 flex items-center justify-between">
                <span>Updated just now</span>
                <span class="text-gray-600 dark:text-gray-400">{{ $users->count() }} active accounts</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
