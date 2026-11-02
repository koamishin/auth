@php
    $tenant = $this->getActiveTenant();
    $metrics = $this->getMetrics();
    $applications = $this->getApplications();
    $activeAppId = session('current_application_id');
@endphp

<x-filament-panels::page>
    <div class="space-y-6 max-w-7xl mx-auto -mt-2">
        <!-- 1. Header Banner -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-xl bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/20 flex items-center justify-center text-primary-600 dark:text-primary-400 shadow-xs">
                    <x-filament::icon icon="heroicon-o-cube" class="w-6 h-6" />
                </div>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl font-bold text-gray-950 dark:text-white tracking-tight">Applications</h1>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-500/30">
                            {{ $metrics['total'] }} {{ \Illuminate\Support\Str::plural('App', $metrics['total']) }}
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        Client applications, OAuth2 credentials, and integration SDK setups for {{ $tenant ? $tenant->name : 'Organization' }}.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a
                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('create') }}"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-plus" class="w-4 h-4" />
                    <span>New Application</span>
                </a>
            </div>
        </div>

        <!-- 2. Metrics Ribbon -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400 text-xs">
                    <span>Total Apps</span>
                    <x-filament::icon icon="heroicon-o-cube" class="w-4 h-4 text-primary-500" />
                </div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $metrics['total'] }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">{{ $tenant ? $tenant->name : 'All' }}</div>
            </div>

            <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400 text-xs">
                    <span>Production</span>
                    <x-filament::icon icon="heroicon-o-check-badge" class="w-4 h-4 text-emerald-500" />
                </div>
                <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ $metrics['production'] }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Live SSL endpoints</div>
            </div>

            <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400 text-xs">
                    <span>Development</span>
                    <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-4 h-4 text-amber-500" />
                </div>
                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ $metrics['development'] }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Localhost sandboxes</div>
            </div>

            <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                <div class="flex items-center justify-between text-gray-500 dark:text-gray-400 text-xs">
                    <span>Enrolled Users</span>
                    <x-filament::icon icon="heroicon-o-users" class="w-4 h-4 text-blue-500" />
                </div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $metrics['users_count'] }}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">Across applications</div>
            </div>
        </div>

        <!-- 3. Search & Interactive Filters Bar -->
        <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <!-- Search Input -->
            <div class="relative flex-1 max-w-md">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by name, client ID, or description..."
                    class="w-full pl-9 pr-3 py-1.5 rounded-lg text-xs bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary-500"
                />
            </div>

            <!-- Environment Filter Pills -->
            <div class="flex items-center gap-1.5 bg-gray-100 dark:bg-white/5 p-1 rounded-lg">
                <button
                    type="button"
                    wire:click="$set('environmentFilter', 'all')"
                    class="px-2.5 py-1 rounded-md text-xs font-semibold transition-all {{ $environmentFilter === 'all' ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-xs' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900' }}"
                >
                    All ({{ $metrics['total'] }})
                </button>
                <button
                    type="button"
                    wire:click="$set('environmentFilter', 'production')"
                    class="px-2.5 py-1 rounded-md text-xs font-semibold transition-all {{ $environmentFilter === 'production' ? 'bg-white dark:bg-gray-800 text-emerald-600 dark:text-emerald-400 shadow-xs' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900' }}"
                >
                    Production ({{ $metrics['production'] }})
                </button>
                <button
                    type="button"
                    wire:click="$set('environmentFilter', 'development')"
                    class="px-2.5 py-1 rounded-md text-xs font-semibold transition-all {{ $environmentFilter === 'development' ? 'bg-white dark:bg-gray-800 text-amber-600 dark:text-amber-400 shadow-xs' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900' }}"
                >
                    Development ({{ $metrics['development'] }})
                </button>
            </div>
        </div>

        <!-- 4. Applications Grid -->
        @if($applications->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5" x-data="{ copiedId: {} }">
                @foreach($applications as $app)
                    @php
                        $isProd = $app->environment === 'production';
                        $isActive = $activeAppId === $app->id;
                    @endphp
                    <div
                        wire:key="app-card-{{ $app->id }}"
                        class="group relative flex flex-col justify-between rounded-xl bg-white dark:bg-gray-900 border {{ $isActive ? 'border-primary-500 ring-2 ring-primary-500/20' : 'border-gray-200 dark:border-white/10 hover:border-primary-500/50 dark:hover:border-primary-500/50' }} p-5 shadow-xs transition-all hover:shadow-md"
                    >
                        <!-- Card Header -->
                        <div>
                            <div class="flex items-center justify-between gap-3 mb-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 flex items-center justify-center font-bold text-sm shrink-0 shadow-xs">
                                        @if($app->framework === 'nextjs')
                                            <span class="font-mono">▲</span>
                                        @elseif($app->framework === 'laravel')
                                            <span class="text-red-500 font-mono text-xs">Lv</span>
                                        @elseif($app->framework === 'vue')
                                            <span class="text-emerald-500 font-mono text-xs">V</span>
                                        @elseif($app->framework === 'react')
                                            <span class="text-cyan-500 font-mono text-xs">⚛</span>
                                        @elseif($app->framework === 'nodejs')
                                            <span class="text-amber-500 font-mono text-xs">Node</span>
                                        @else
                                            <span class="text-primary-500 font-mono text-xs">JS</span>
                                        @endif
                                    </div>

                                    <div class="min-w-0">
                                        <a
                                            href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                                            class="text-sm font-bold text-gray-950 dark:text-white truncate block hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                                        >
                                            {{ $app->name }}
                                        </a>
                                        <span class="text-[11px] text-gray-400 font-mono">
                                            {{ $app->getFrameworkLabel() }}
                                        </span>
                                    </div>
                                </div>

                                <div class="shrink-0 flex items-center gap-1.5">
                                    @if($isProd)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            Production
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                            Development
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if($app->description)
                                <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2 mb-3 leading-relaxed">
                                    {{ $app->description }}
                                </p>
                            @endif

                            <!-- Client ID with Copy Button -->
                            <div class="flex items-center justify-between p-2 rounded-lg bg-gray-50 dark:bg-white/5 border border-gray-100 dark:border-white/5 text-[11px] mb-3 font-mono">
                                <span class="text-gray-500 truncate mr-2" title="{{ $app->oauth_client_id }}">
                                    ID: {{ \Illuminate\Support\Str::limit($app->oauth_client_id ?? 'None', 26) }}
                                </span>
                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText('{{ $app->oauth_client_id }}'); copiedId['{{ $app->id }}'] = true; setTimeout(() => copiedId['{{ $app->id }}'] = false, 2000)"
                                    class="shrink-0 text-primary-600 dark:text-primary-400 hover:underline font-semibold"
                                >
                                    <span x-text="copiedId['{{ $app->id }}'] ? 'Copied!' : 'Copy'"></span>
                                </button>
                            </div>

                            <!-- Specs & Meta details -->
                            <div class="grid grid-cols-2 gap-2 text-[11px] text-gray-500 dark:text-gray-400 mb-4 pt-1">
                                <div>
                                    <span class="text-gray-400 block text-[10px] uppercase font-mono">Users</span>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $app->users_count }} enrolled</span>
                                </div>
                                <div>
                                    <span class="text-gray-400 block text-[10px] uppercase font-mono">Callbacks</span>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ count($app->redirect_uris ?? []) }} URI(s)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer & Quick Links -->
                        <div class="pt-3.5 border-t border-gray-100 dark:border-white/5 flex items-center justify-between text-xs">
                            <div class="flex items-center gap-2">
                                @if($isActive)
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-primary-600 dark:text-primary-400">
                                        <x-filament::icon icon="heroicon-m-check-circle" class="w-3.5 h-3.5" />
                                        Active
                                    </span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="switchApp({{ $app->id }})"
                                        class="text-[11px] font-medium text-gray-500 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                                    >
                                        Set context
                                    </button>
                                @endif
                            </div>

                            <div class="flex items-center gap-3">
                                <a
                                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('edit', ['record' => $app]) }}"
                                    class="text-gray-500 hover:text-gray-900 dark:hover:text-white transition-colors"
                                    title="Edit settings"
                                >
                                    <x-filament::icon icon="heroicon-m-pencil-square" class="w-4 h-4" />
                                </a>

                                <button
                                    type="button"
                                    wire:click="deleteApplication({{ $app->id }})"
                                    wire:confirm="Are you sure you want to delete '{{ $app->name }}'? All client credentials and environment memberships will be permanently removed."
                                    class="text-gray-400 hover:text-red-500 transition-colors"
                                    title="Delete application"
                                >
                                    <x-filament::icon icon="heroicon-m-trash" class="w-4 h-4" />
                                </button>

                                <a
                                    href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('view', ['record' => $app]) }}"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                                >
                                    <span>Manage</span>
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="w-3.5 h-3.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            @if($applications->hasPages())
                <div class="p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-white/10 shadow-xs">
                    {{ $applications->links() }}
                </div>
            @endif
        @else
            <!-- Empty State -->
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-white/15 bg-white dark:bg-gray-900 p-12 text-center space-y-4">
                <div class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-400 flex items-center justify-center mx-auto">
                    <x-filament::icon icon="heroicon-o-cube" class="w-6 h-6" />
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-950 dark:text-white">No applications found</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 max-w-sm mx-auto mt-1">
                        @if($search !== '' || $environmentFilter !== 'all')
                            No applications match your active search or filter criteria. Try clearing the filter.
                        @else
                            You haven't provisioned any client applications yet. Create your first app to connect SDKs and OAuth2 clients.
                        @endif
                    </p>
                </div>
                <div>
                    @if($search !== '' || $environmentFilter !== 'all')
                        <button
                            type="button"
                            wire:click="$set('search', ''); $set('environmentFilter', 'all');"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-white/10 text-gray-800 dark:text-gray-200"
                        >
                            Reset filters
                        </button>
                    @else
                        <a
                            href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('create') }}"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors"
                        >
                            <x-filament::icon icon="heroicon-m-plus" class="w-4 h-4" />
                            <span>Create Application</span>
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
