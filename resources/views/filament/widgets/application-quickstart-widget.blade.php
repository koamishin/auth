@php
    $frameworks = $this->getAvailableFrameworks();
    $currentFramework = $frameworks[$framework] ?? $frameworks['laravel'];
    $steps = $this->getSteps();
    $currentStep = collect($steps)->firstWhere('number', $activeStep) ?? $steps[0];
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 space-y-6 shadow-xs" x-data="{ copiedStep: false }">
    <!-- 1. Header with Framework Selector Pills -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-5">
        <div>
            <div class="flex items-center gap-2.5">
                <h2 class="text-base font-bold text-gray-950 dark:text-white tracking-tight flex items-center gap-2">
                    <x-filament::icon icon="heroicon-m-bolt" class="w-5 h-5 text-primary-500" />
                    <span>Quickstart Integration Guide</span>
                </h2>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-400 border border-primary-200 dark:border-primary-500/30 font-mono">
                    {{ $currentFramework['name'] }}
                </span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">
                {{ $currentFramework['description'] }}
            </p>
        </div>

        <!-- Framework Switcher -->
        <div class="flex flex-wrap items-center gap-1.5 p-1 bg-gray-100 dark:bg-white/5 rounded-xl border border-gray-200/50 dark:border-white/5">
            @foreach($frameworks as $key => $meta)
                <button
                    type="button"
                    wire:click="setFramework('{{ $key }}')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $framework === $key ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-xs' : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white' }}"
                >
                    <span class="font-mono text-xs">{{ $meta['badge'] }}</span>
                    <span>{{ $meta['name'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <!-- 2. Step Progress Navigation Bar -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach($steps as $step)
            @php
                $isActive = $activeStep === $step['number'];
                $isCompleted = $activeStep > $step['number'];
            @endphp
            <button
                type="button"
                wire:click="setStep({{ $step['number'] }})"
                class="group flex items-center gap-3 p-3 rounded-xl border text-left transition-all {{ $isActive ? 'border-primary-500 ring-2 ring-primary-500/20 bg-primary-50/20 dark:bg-primary-500/5' : ($isCompleted ? 'border-gray-200 dark:border-white/10 bg-gray-50/40 dark:bg-white/[0.02]' : 'border-gray-200 dark:border-white/5 opacity-60 hover:opacity-100') }}"
            >
                <div class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs shrink-0 transition-colors {{ $isActive ? 'bg-primary-600 text-white' : ($isCompleted ? 'bg-emerald-500 text-white' : 'bg-gray-200 dark:bg-white/10 text-gray-600 dark:text-gray-300') }}">
                    @if($isCompleted)
                        <x-filament::icon icon="heroicon-m-check" class="w-4 h-4" />
                    @else
                        {{ $step['number'] }}
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase font-mono tracking-wider text-gray-400 dark:text-gray-500">Step {{ $step['number'] }}</p>
                    <p class="text-xs font-bold text-gray-900 dark:text-white truncate">{{ $step['title'] }}</p>
                </div>
            </button>
        @endforeach
    </div>

    <!-- 3. Active Step Details & Code Block -->
    <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-white/[0.02] p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 font-bold text-xs flex items-center justify-center">
                        {{ $currentStep['number'] }}
                    </span>
                    <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                        {{ $currentStep['title'] }}
                    </h3>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 pl-8">
                    {{ $currentStep['subtitle'] }}
                </p>
            </div>

            <!-- Optional Package Manager Selector on Step 1 for JS frameworks -->
            @if($currentStep['number'] === 1 && $framework !== 'laravel')
                <div class="flex items-center gap-1 bg-white dark:bg-gray-800 p-1 rounded-lg border border-gray-200 dark:border-white/10 self-start sm:self-auto">
                    @foreach(['npm', 'pnpm', 'yarn', 'bun'] as $pm)
                        <button
                            type="button"
                            wire:click="setPackageManager('{{ $pm }}')"
                            class="px-2 py-0.5 rounded text-[11px] font-mono font-semibold transition-all {{ $packageManager === $pm ? 'bg-primary-600 text-white shadow-xs' : 'text-gray-500 hover:text-gray-900 dark:hover:text-white' }}"
                        >
                            {{ $pm }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Code Block with Copy Action -->
        <div class="rounded-xl overflow-hidden border border-gray-800 bg-gray-950 shadow-inner">
            <div class="flex items-center justify-between px-4 py-2.5 bg-gray-900/90 border-b border-gray-800 text-xs">
                <div class="flex items-center gap-2 text-gray-400 font-mono text-[11px]">
                    <x-filament::icon icon="heroicon-m-document-text" class="w-3.5 h-3.5 text-primary-400" />
                    <span>{{ $currentStep['file'] }}</span>
                </div>

                <button
                    type="button"
                    @click="navigator.clipboard.writeText('{{ addslashes($currentStep['code']) }}'); copiedStep = true; setTimeout(() => copiedStep = false, 2000)"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-semibold bg-white/10 hover:bg-white/20 text-gray-200 transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-clipboard-document" class="w-3.5 h-3.5 text-gray-400" />
                    <span x-text="copiedStep ? 'Copied Snippet!' : 'Copy Snippet'"></span>
                </button>
            </div>

            <pre class="p-4 text-xs font-mono text-emerald-400 overflow-x-auto leading-relaxed select-all">{{ $currentStep['code'] }}</pre>
        </div>

        <!-- Callout Tip / Security Note -->
        <div class="p-3.5 rounded-lg border border-blue-200 dark:border-blue-900/40 bg-blue-50/60 dark:bg-blue-950/20 text-blue-900 dark:text-blue-200 flex items-start gap-3 text-xs leading-relaxed">
            <x-filament::icon icon="heroicon-m-information-circle" class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
            <span>{{ $currentStep['tip'] }}</span>
        </div>
    </div>

    <!-- 4. Footer Stepper Navigation Controls -->
    <div class="flex items-center justify-between pt-2">
        <div>
            @if($activeStep > 1)
                <button
                    type="button"
                    wire:click="setStep({{ $activeStep - 1 }})"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-white/10 transition-colors"
                >
                    <x-filament::icon icon="heroicon-m-arrow-left" class="w-3.5 h-3.5" />
                    <span>Previous Step</span>
                </button>
            @endif
        </div>

        <div class="flex items-center gap-3">
            @if($activeStep < count($steps))
                <button
                    type="button"
                    wire:click="setStep({{ $activeStep + 1 }})"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold bg-primary-600 hover:bg-primary-500 text-white shadow-xs transition-colors"
                >
                    <span>Next: {{ $steps[$activeStep]['title'] }}</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="w-3.5 h-3.5" />
                </button>
            @else
                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-m-check-circle" class="w-4 h-4" />
                    <span>Integration Setup Complete!</span>
                </span>
            @endif
        </div>
    </div>
</div>
