@php
    $frameworkInfo = $this->getFrameworkInfo();
    $appName = $this->getAppName();
    $projectType = $this->getProjectType();
    $authOptions = $this->getAuthOptions();
    $env = $this->data['environment'] ?? 'development';
    $isProd = $env === 'production';
    $hasSocial = $authOptions['google'] || $authOptions['github'];
    $hasCredentials = $authOptions['email'] || $authOptions['phone'] || $authOptions['username'];
@endphp

<x-filament-panels::page>
    <div class="space-y-6 max-w-7xl mx-auto -mt-2">
        <!-- Top Back Navigation -->
        <div>
            <a
                href="{{ \App\Filament\Resources\TenantApplications\TenantApplicationResource::getUrl('index') }}"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white transition-colors"
            >
                <x-filament::icon icon="heroicon-m-arrow-left" class="w-4 h-4" />
                <span>Back</span>
            </a>
        </div>

        <!-- 2-Column Split: Clerk Creation Form (Left) + Interactive Live Sign-In Canvas (Right) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
            
            <!-- Left Column: Form Controls -->
            <div class="lg:col-span-6 space-y-6">
                <form wire:submit="create" class="space-y-6">
                    {{ $this->form }}

                    <!-- Clerk-Style Primary Action Button -->
                    <div class="pt-2">
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full py-3 px-6 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-[#6c47ff] to-[#5030e5] hover:from-[#5835ea] hover:to-[#4323d6] shadow-md shadow-[#6c47ff]/25 transition-all duration-200 flex items-center justify-center gap-2 cursor-pointer disabled:opacity-70"
                        >
                            <span wire:loading.remove>Create application</span>
                            <span wire:loading class="inline-flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                </svg>
                                <span>Provisioning application...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Interactive Clerk-Style Live Preview Canvas -->
            <div class="lg:col-span-6 sticky top-6">
                <div class="rounded-2xl border border-gray-200 dark:border-white/10 bg-slate-50/70 dark:bg-slate-950/40 p-8 min-h-[640px] flex flex-col items-center justify-center relative overflow-hidden [background-image:radial-gradient(#d1d5db_1.2px,transparent_1.2px)] dark:[background-image:radial-gradient(#374151_1.2px,transparent_1.2px)] [background-size:24px_24px]">
                    
                    <div class="flex items-center justify-center gap-6 w-full max-w-lg">
                        <!-- Floating Live Sign-In Modal -->
                        <div class="w-full max-w-[340px] rounded-2xl bg-white dark:bg-gray-900 border border-gray-200/80 dark:border-white/10 shadow-2xl p-7 text-center transition-all duration-300">
                            
                            <!-- Header -->
                            <h3 class="text-base font-bold text-gray-950 dark:text-white tracking-tight">
                                Sign in to {{ $appName ?: 'My Application' }}
                            </h3>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 mb-5">
                                Welcome back! Please sign in to continue
                            </p>

                            <!-- Social Buttons -->
                            <div class="space-y-2 mb-4">
                                @if($authOptions['google'])
                                    <button
                                        type="button"
                                        class="w-full flex items-center justify-center gap-2.5 py-2 px-3 rounded-lg border border-gray-200 dark:border-white/15 bg-white dark:bg-gray-800 text-xs font-semibold text-gray-800 dark:text-gray-200 shadow-xs hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    >
                                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24">
                                            <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"/>
                                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"/>
                                            <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.04 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/>
                                            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                                        </svg>
                                        <span>Continue with Google</span>
                                    </button>
                                @endif

                                @if($authOptions['github'])
                                    <button
                                        type="button"
                                        class="w-full flex items-center justify-center gap-2.5 py-2 px-3 rounded-lg border border-gray-200 dark:border-white/15 bg-white dark:bg-gray-800 text-xs font-semibold text-gray-800 dark:text-gray-200 shadow-xs hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                    >
                                        <svg class="w-4 h-4 fill-current shrink-0" viewBox="0 0 24 24">
                                            <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
                                        </svg>
                                        <span>Continue with GitHub</span>
                                    </button>
                                @endif
                            </div>

                            <!-- "or" Divider -->
                            @if($hasSocial && $hasCredentials)
                                <div class="relative my-4 flex items-center justify-center">
                                    <div class="w-full border-t border-gray-200 dark:border-white/10"></div>
                                    <span class="absolute bg-white dark:bg-gray-900 px-3 text-[10px] uppercase font-mono text-gray-400">or</span>
                                </div>
                            @endif

                            <!-- Credential Inputs -->
                            <div class="space-y-3 mb-4 text-left">
                                @if($authOptions['email'])
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                            Email address
                                        </label>
                                        <input
                                            type="email"
                                            placeholder="Enter your email address"
                                            disabled
                                            class="w-full px-3 py-2 rounded-lg text-xs bg-gray-50/70 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-800 dark:text-gray-200 placeholder-gray-400 cursor-not-allowed"
                                        />
                                    </div>
                                @endif

                                @if($authOptions['phone'])
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                            Phone number
                                        </label>
                                        <input
                                            type="tel"
                                            placeholder="+1 (555) 000-0000"
                                            disabled
                                            class="w-full px-3 py-2 rounded-lg text-xs bg-gray-50/70 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-800 dark:text-gray-200 placeholder-gray-400 cursor-not-allowed"
                                        />
                                    </div>
                                @endif

                                @if($authOptions['username'])
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-700 dark:text-gray-300 mb-1">
                                            Username
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="Enter your username"
                                            disabled
                                            class="w-full px-3 py-2 rounded-lg text-xs bg-gray-50/70 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-800 dark:text-gray-200 placeholder-gray-400 cursor-not-allowed"
                                        />
                                    </div>
                                @endif

                                @if($authOptions['passkeys'])
                                    <button
                                        type="button"
                                        class="w-full flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg border border-dashed border-gray-300 dark:border-white/15 text-[11px] font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors"
                                    >
                                        <svg class="w-3.5 h-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"/>
                                        </svg>
                                        <span>Sign in with passkey</span>
                                    </button>
                                @endif
                            </div>

                            <!-- Clerk-Style Primary Continue Button -->
                            <button
                                type="button"
                                class="w-full py-2.5 px-4 rounded-lg bg-gray-900 hover:bg-gray-800 dark:bg-white dark:hover:bg-gray-100 text-white dark:text-gray-900 text-xs font-semibold shadow-xs flex items-center justify-center gap-1.5 transition-colors"
                            >
                                <span>Continue</span>
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>

                            <!-- Sign up footer -->
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-4">
                                Don't have an account? <span class="font-semibold text-[#6c47ff] hover:underline cursor-pointer">Sign up</span>
                            </p>

                            <!-- Secured by branding -->
                            <div class="mt-5 pt-3 border-t border-gray-100 dark:border-white/5 flex items-center justify-center gap-1 text-[10px] text-gray-400">
                                <span>Secured by</span>
                                <span class="font-bold text-gray-800 dark:text-gray-200 tracking-tight flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-shield-check" class="w-3.5 h-3.5 text-primary-500" />
                                    koamishin
                                </span>
                            </div>
                        </div>

                        <!-- B2B Companion Card (Clerk Roles Preview) -->
                        @if($projectType === 'b2b')
                            <div class="hidden xl:block w-48 p-4 rounded-xl bg-white/95 dark:bg-gray-900/95 border border-gray-200 dark:border-white/10 shadow-lg text-xs space-y-3 transition-all duration-300">
                                <div>
                                    <h4 class="font-bold text-gray-900 dark:text-white">Roles</h4>
                                    <p class="text-[10px] text-gray-400 leading-tight">Manage default roles for members</p>
                                </div>
                                <div class="space-y-2 pt-2 border-t border-gray-100 dark:border-white/5 text-[11px]">
                                    <div class="flex items-center justify-between">
                                        <span class="font-semibold text-gray-800 dark:text-gray-200">Admin</span>
                                        <span class="text-[10px] text-gray-400 font-mono">Full access</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="font-semibold text-gray-800 dark:text-gray-200">Member</span>
                                        <span class="text-[10px] text-gray-400 font-mono">Limited access</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Under-preview subtitle -->
                    <p class="text-[11px] font-medium text-gray-400 dark:text-gray-500 mt-5 tracking-wide">
                        This is a preview
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>


