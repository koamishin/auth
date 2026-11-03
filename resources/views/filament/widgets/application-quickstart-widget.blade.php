@php
    $frameworks = $this->getAvailableFrameworks();
    $frameworkOrder = ['nextjs', 'react', 'vue', 'vanilla', 'laravel', 'nodejs', 'mobile'];
    $currentFramework = $frameworks[$framework] ?? $frameworks['nextjs'];
    $connection = $this->getConnectionStatus();
    $enrolledUsersCount = $this->getEnrolledUsersCount();
    $isBrowserGuide = in_array($framework, ['nextjs', 'react', 'vue', 'vanilla', 'mobile'], true);
    $hasClientMismatch = $connection['connected'] && $connection['client_type'] === 'confidential' && $isBrowserGuide;
    $steps = $this->getSteps();
@endphp

<section class="min-w-0 rounded-xl border border-gray-200 bg-white shadow-xs dark:border-white/10 dark:bg-gray-900">
    <div class="mx-auto max-w-4xl px-5 py-8 sm:px-8 sm:py-10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    @if($enrolledUsersCount === 0)
                        <span class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                            <svg class="h-3.5 w-3.5 animate-spin text-primary-500" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                            </svg>
                            <span>Watching for users</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span>{{ $enrolledUsersCount }} {{ \Illuminate\Support\Str::plural('user', $enrolledUsersCount) }} connected</span>
                        </span>
                    @endif
                    <span class="text-xs font-semibold uppercase tracking-wider text-primary-600 dark:text-primary-400">
                        {{ ucfirst($this->getEnvironment()) }} environment
                    </span>
                </div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">Add authentication to your app</h2>
                <p class="max-w-2xl text-sm leading-6 text-gray-500 dark:text-gray-400">
                    Select a framework and install with your agent or follow the manual setup guide.
                </p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 text-sm font-bold text-gray-800 dark:border-white/10 dark:bg-white/5 dark:text-gray-100" aria-hidden="true">
                {{ $currentFramework['badge'] }}
            </div>
        </div>

        <div class="mt-7">
            <p class="mb-3 text-xs font-semibold text-gray-700 dark:text-gray-300">Choose your platform</p>
            <div role="tablist" aria-label="Platform" class="flex gap-2 overflow-x-auto pb-2">
                @foreach($frameworkOrder as $key)
                    @php
                        $meta = $frameworks[$key];
                    @endphp
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $framework === $key ? 'true' : 'false' }}"
                        wire:click="setFramework('{{ $key }}')"
                        class="flex min-w-24 shrink-0 flex-col items-center gap-2 rounded-xl border px-3 py-3 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 {{ $framework === $key ? 'border-gray-300 bg-gray-100 text-gray-950 dark:border-white/20 dark:bg-white/10 dark:text-white' : 'border-transparent text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white' }}"
                    >
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white font-mono text-sm font-bold shadow-xs dark:bg-gray-800" aria-hidden="true">{{ $meta['badge'] }}</span>
                        <span>{{ $meta['name'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-6 dark:border-white/10">
            <div role="group" aria-label="Setup method" class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                <button type="button" wire:click="setSetupMode('agent')" aria-pressed="{{ $setupMode === 'agent' ? 'true' : 'false' }}"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors {{ $setupMode === 'agent' ? 'bg-white text-gray-950 shadow-xs dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}">
                    Agent setup
                </button>
                <button type="button" wire:click="setSetupMode('manual')" aria-pressed="{{ $setupMode === 'manual' ? 'true' : 'false' }}"
                    class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors {{ $setupMode === 'manual' ? 'bg-white text-gray-950 shadow-xs dark:bg-gray-700 dark:text-white' : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}">
                    Manual setup
                </button>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $currentFramework['description'] }}</p>
        </div>

        <div class="mt-7 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Connect your app</h3>
                    @if($connection['connected'])
                        <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                            Registered callback: <code class="break-all text-gray-700 dark:text-gray-200">{{ $this->getCallbackUrl() }}</code>
                        </p>
                    @else
                        <p class="max-w-xl text-xs leading-5 text-gray-500 dark:text-gray-400">
                            Register your exact callback URL and choose whether this environment uses a browser/mobile or server client before testing sign-in.
                        </p>
                    @endif
                </div>
                <button type="button" wire:click="openConnectionSetup"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-gray-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200">
                    {{ $connection['connected'] ? 'Update connection' : 'Register callback' }}
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5" />
                </button>
            </div>
            @if($hasClientMismatch)
                <p role="status" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-100">
                    This guide uses a public client, but this environment is connected as a confidential server client. Use a separate application configured for browser or mobile PKCE; changing an existing client type would disrupt its integration.
                </p>
            @endif
        </div>

        @if($setupMode === 'agent')
            <div class="mt-8 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Build with your coding agent</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Copy this application-specific prompt into your agent. Review its changes and supply any secret key separately through your environment.
                    </p>
                </div>
                <div x-data="{ copied: false }" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950">
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-white/10">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Prompt</span>
                        <button type="button" @click="navigator.clipboard.writeText($refs.prompt.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white">
                            <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
                            <span x-text="copied ? 'Copied' : 'Copy prompt'"></span>
                        </button>
                    </div>
                    <pre class="overflow-x-auto whitespace-pre-wrap p-4 text-xs leading-6 text-gray-700 dark:text-gray-200"><code x-ref="prompt">{{ $this->getAgentPrompt() }}</code></pre>
                </div>
                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">The prompt contains your publishable key, not your secret key. An environment secret grants full management access and belongs only on a trusted server.</p>
            </div>
        @else
            <div class="mt-8">
                <p class="mb-7 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    Use an SDK version that supports publishable keys. Selecting a guide does not change your application configuration.
                </p>
                <ol class="space-y-0">
                    @foreach($steps as $step)
                        @php
                            $isLongCode = mb_strlen($step['code']) > 650;
                            $showPackageManagers = $step['file'] === 'terminal' && in_array($framework, ['nextjs', 'react', 'vue', 'nodejs'], true);
                        @endphp
                        <li class="relative flex gap-4 pb-10 last:pb-0">
                            @if(! $loop->last)
                                <span class="absolute bottom-0 left-3.5 top-8 w-px bg-gray-200 dark:bg-white/10" aria-hidden="true"></span>
                            @endif
                            <span class="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white dark:bg-gray-100 dark:text-gray-900">{{ $step['number'] }}</span>
                            <div class="min-w-0 flex-1 space-y-3 pt-0.5">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $step['title'] }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $step['subtitle'] }}</p>
                                </div>

                                <div x-data="{ copied: false, expanded: false }" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-gray-950">
                                    <div class="flex min-h-10 items-center justify-between gap-3 border-b border-gray-200 px-4 dark:border-white/10">
                                        @if($showPackageManagers)
                                            <div role="group" aria-label="Package manager" class="flex items-center gap-3">
                                                @foreach(['npm', 'pnpm', 'yarn', 'bun'] as $pm)
                                                    <button type="button" wire:click="setPackageManager('{{ $pm }}')" aria-pressed="{{ $packageManager === $pm ? 'true' : 'false' }}"
                                                        class="border-b-2 py-2 text-xs font-medium {{ $packageManager === $pm ? 'border-gray-900 text-gray-950 dark:border-white dark:text-white' : 'border-transparent text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}">{{ $pm }}</button>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="py-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $step['file'] }}</span>
                                        @endif
                                        <button type="button" @click="navigator.clipboard.writeText($refs.code.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">
                                            <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
                                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                        </button>
                                    </div>
                                    <pre @if($isLongCode) :class="expanded ? '' : 'max-h-64 overflow-hidden'" @endif class="overflow-x-auto p-4 text-xs leading-6 text-gray-800 dark:text-gray-200"><code x-ref="code">{{ $step['code'] }}</code></pre>
                                    @if($isLongCode)
                                        <button type="button" @click="expanded = !expanded" :aria-expanded="expanded.toString()"
                                            class="flex w-full items-center justify-center gap-1 border-t border-gray-200 py-2 text-xs font-semibold text-gray-500 hover:text-gray-900 dark:border-white/10 dark:text-gray-400 dark:hover:text-white">
                                            <span x-text="expanded ? 'Collapse code' : 'Expand code'"></span>
                                            <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5" ::class="expanded ? 'rotate-180' : ''" />
                                        </button>
                                    @endif
                                </div>
                                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $step['tip'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <div class="mt-8 flex gap-3 rounded-xl border border-gray-200 p-4 text-xs leading-5 text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-information-circle" class="h-4 w-4 shrink-0 text-gray-400" />
                    <p>After registering the callback, run your app and complete a test sign-in. The development and production environments have separate keys and users.</p>
                </div>
            </div>
        @endif
    </div>
</section>
