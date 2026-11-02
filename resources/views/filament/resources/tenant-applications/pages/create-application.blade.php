<x-filament-panels::page>
    <div class="space-y-6 max-w-7xl mx-auto -mt-2">
        <!-- Top Application Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 shadow-xs">
                    <x-filament::icon icon="heroicon-o-cube" class="w-6 h-6" />
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-950 dark:text-white tracking-tight">Create Application</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Provision OAuth credentials, callback security policies, and client SDK configuration for your application.
                    </p>
                </div>
            </div>

            <div>
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('index') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-white/10 shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-arrow-left" class="w-3.5 h-3.5 text-gray-500" />
                    <span>Back to Applications</span>
                </a>
            </div>
        </div>

        <!-- 2-Column Split: Form (8 cols) + Sticky Live Architecture & Preview (4 cols) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            <!-- Form Column -->
            <div class="lg:col-span-8">
                <form wire:submit="create">
                    {{ $this->form }}

                    <div class="mt-6">
                        <x-filament::actions
                            :actions="$this->getFormActions()"
                        />
                    </div>
                </form>
            </div>

            <!-- Sticky Live Preview Column -->
            <div class="lg:col-span-4 sticky top-6 space-y-5">
                @php
                    $frameworkInfo = $this->getFrameworkInfo();
                    $appName = $this->data['name'] ?? null;
                    $env = $this->data['environment'] ?? 'development';
                    $isProd = $env === 'production';
                    $uris = $this->data['redirect_uris'] ?? [];
                    $allowSignups = $this->data['allow_signups'] ?? ($env === 'development');
                @endphp

                <!-- Live Card Preview -->
                <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-white/5 pb-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Live Preview</span>
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-500/30">
                            <span class="w-1.5 h-1.5 rounded-full bg-primary-500 animate-pulse"></span>
                            Ready to Provision
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center font-bold text-sm shadow-xs">
                            <span class="font-mono text-{{ $frameworkInfo['color'] }}-500">{{ $frameworkInfo['badge'] }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white truncate">
                                {{ $appName ?: 'Your Application Name' }}
                            </h3>
                            <div class="flex items-center gap-2 mt-0.5">
                                @if($isProd)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                                        ● Production
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400">
                                        ● Development
                                    </span>
                                @endif
                                <span class="text-[11px] text-gray-400 font-mono">
                                    {{ $frameworkInfo['name'] }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-gray-100 dark:border-white/5 space-y-2 text-xs">
                        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                            <span>Client Type:</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $frameworkInfo['client_type'] }}</span>
                        </div>
                        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                            <span>Self-Service Signup:</span>
                            <span class="font-semibold {{ $allowSignups ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $allowSignups ? 'Enabled (Open)' : 'Disabled (Admin Only)' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-gray-500 dark:text-gray-400">
                            <span>Callback Endpoint(s):</span>
                            <span class="font-mono font-semibold text-gray-800 dark:text-gray-200">{{ count($uris) }} configured</span>
                        </div>
                    </div>
                </div>

                <!-- Architectural Specifications -->
                <div class="p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs space-y-3.5">
                    <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 text-primary-500" />
                        <span>Security Specifications</span>
                    </div>

                    <ul class="space-y-2.5 text-xs text-gray-600 dark:text-gray-300">
                        <li class="flex items-start gap-2">
                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
                            <span><strong>Dual-Environment Provisioning:</strong> Automatically creates independent OAuth clients for both development sandbox and production.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
                            <span><strong>PKCE & Secure Code Grants:</strong> Enforces Proof Key for Code Exchange (RFC 7636) on all authorization requests.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <x-filament::icon icon="heroicon-m-check" class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" />
                            <span><strong>Confidential vs Public Client:</strong> Automatically determines client secret requirements based on selected technology stack.</span>
                        </li>
                    </ul>
                </div>

                <!-- Tailored SDK Package Info -->
                <div class="p-5 rounded-xl bg-gray-900 text-white border border-gray-800 shadow-xs space-y-2.5" x-data="{ copiedSdk: false }">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-300">Integration Package</span>
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText('{{ $frameworkInfo['package'] }}'); copiedSdk = true; setTimeout(() => copiedSdk = false, 2000)"
                            class="text-[11px] text-primary-400 hover:text-primary-300 font-mono font-semibold inline-flex items-center gap-1"
                        >
                            <span x-text="copiedSdk ? 'Copied!' : 'Copy'"></span>
                        </button>
                    </div>
                    <pre class="p-2.5 rounded-lg bg-black/40 text-emerald-400 font-mono text-xs overflow-x-auto select-all">{{ $frameworkInfo['package'] }}</pre>
                    <p class="text-[11px] text-gray-400 leading-relaxed">
                        After creation, tailored code snippets and configuration guides will be displayed on the application overview page.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
