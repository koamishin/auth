@php
    $app = $this->application();
    $user = $this->user();
    $membership = $this->membership();
    $metadata = $this->metadata();
    $passkeys = $this->passkeys();
    $socialAccounts = $this->socialAccounts();
    $sessions = $this->activeSessions();
    $isBanned = $user->isBanned();
    $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->filter()->take(2)->map(fn ($p) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($p, 0, 1)))->implode('');
@endphp

<x-filament-panels::page>
    <div class="kms-settings min-w-0 w-full max-w-4xl mx-auto -mt-3 space-y-8">
        {{-- Identity header --}}
        <header class="flex flex-wrap items-start justify-between gap-x-8 gap-y-4 pb-6 border-b border-gray-200 dark:border-white/10">
            <div class="flex items-start gap-4 min-w-0">
                @if ($user->profile_photo_url)
                    <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" class="w-14 h-14 rounded-full object-cover border border-gray-200 dark:border-white/10 shrink-0" />
                @else
                    <span class="w-14 h-14 rounded-full bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-300 flex items-center justify-center text-lg font-semibold shrink-0 border border-primary-100 dark:border-primary-500/20">
                        {{ $initials ?: '?' }}
                    </span>
                @endif

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="text-xl font-semibold text-gray-950 dark:text-white tracking-tight truncate">{{ $user->name }}</h2>
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $isBanned ? 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $isBanned ? 'bg-danger-500' : 'bg-success-500' }}"></span>
                            {{ $isBanned ? 'Suspended' : 'Active' }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $user->email }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 font-mono truncate">
                        ID {{ $user->id }}
                        @if ($membership?->last_sign_in_at)
                            · Last sign in {{ \Illuminate\Support\Carbon::parse($membership->last_sign_in_at)->diffForHumans() }}
                        @else
                            · Never signed in
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-white/5 text-gray-700 dark:text-gray-200">
                    <x-filament::icon icon="heroicon-m-shield-check" class="w-3.5 h-3.5 text-gray-400" />
                    {{ ucfirst((string) ($membership->role ?? 'member')) }}
                </span>
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-lg {{ $app->environment === 'production' ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' }}">
                    {{ ucfirst($app->environment) }}
                </span>
            </div>
        </header>

        {{-- Identifiers --}}
        <section>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Identifiers</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">How this user is identified when signing in, and whether each identifier is verified.</p>

            <dl class="mt-4 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                @php
                    $identifiers = [
                        ['label' => 'Email address', 'value' => $user->email, 'verified' => $user->hasVerifiedEmail()],
                        ['label' => 'Phone number', 'value' => $user->phone_number, 'verified' => $user->hasVerifiedPhone()],
                        ['label' => 'Username', 'value' => $user->username, 'verified' => null],
                        ['label' => 'Web3 wallet', 'value' => $user->web3_wallet, 'verified' => null],
                    ];
                @endphp

                @foreach ($identifiers as $identifier)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $identifier['label'] }}</dt>
                            <dd class="text-sm text-gray-900 dark:text-gray-100 font-medium truncate">
                                {{ $identifier['value'] ?: '—' }}
                            </dd>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($identifier['verified'] !== null)
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold {{ $identifier['verified'] ? 'text-success-600 dark:text-success-400' : 'text-gray-400' }}">
                                    <x-filament::icon :icon="$identifier['verified'] ? 'heroicon-m-check-badge' : 'heroicon-m-x-mark'" class="w-3.5 h-3.5" />
                                    {{ $identifier['verified'] ? 'Verified' : 'Unverified' }}
                                </span>
                            @endif

                            @if ($identifier['label'] === 'Email address')
                                @if ($user->hasVerifiedEmail())
                                    <button type="button" wire:click="unverifyEmail" class="text-[11px] font-semibold text-gray-500 hover:text-gray-900 dark:hover:text-white">Mark unverified</button>
                                @else
                                    <button type="button" wire:click="verifyEmail" class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 hover:underline">Verify now</button>
                                    <button type="button" wire:click="sendVerificationEmail" class="text-[11px] font-semibold text-gray-500 hover:text-gray-900 dark:hover:text-white">Send email</button>
                                @endif
                            @endif

                            @if ($identifier['label'] === 'Phone number' && $user->phone_number && ! $user->hasVerifiedPhone())
                                <button type="button" wire:click="verifyPhone" class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 hover:underline">Verify now</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Authentication --}}
        <section>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Authentication</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Credentials and connected sign-in methods on this account.</p>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @php
                    $factors = [
                        ['label' => 'Password', 'active' => filled($user->password), 'detail' => filled($user->password) ? 'Set' : 'Not set'],
                        ['label' => 'Two-factor (TOTP)', 'active' => $user->two_factor_confirmed_at !== null, 'detail' => $user->two_factor_confirmed_at ? 'Enabled' : 'Disabled'],
                        ['label' => 'Email codes', 'active' => (bool) $user->has_email_authentication, 'detail' => $user->has_email_authentication ? 'Enabled' : 'Disabled'],
                        ['label' => 'Passkeys', 'active' => $passkeys->isNotEmpty(), 'detail' => $passkeys->count().' registered'],
                    ];
                @endphp

                @foreach ($factors as $factor)
                    <div class="flex items-center gap-3 rounded-xl border border-gray-200 dark:border-white/10 px-4 py-3">
                        <span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 {{ $factor['active'] ? 'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400' : 'bg-gray-100 text-gray-400 dark:bg-white/5' }}">
                            <x-filament::icon :icon="$factor['active'] ? 'heroicon-m-check' : 'heroicon-m-minus-small'" class="w-4 h-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $factor['label'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $factor['detail'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Passkeys --}}
            @if ($passkeys->isNotEmpty())
                <div class="mt-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Registered passkeys</p>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                        @foreach ($passkeys as $passkey)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0 flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-o-finger-print" class="w-4 h-4 text-gray-400 shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $passkey->name }}</p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                            Added {{ $passkey->created_at?->diffForHumans() }}
                                            @if ($passkey->last_used_at)
                                                · Last used {{ $passkey->last_used_at->diffForHumans() }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    wire:click="deletePasskey({{ $passkey->getKey() }})"
                                    wire:confirm="Remove this passkey? The user will no longer be able to sign in with it."
                                    class="text-[11px] font-semibold text-danger-600 dark:text-danger-400 hover:underline shrink-0"
                                >
                                    Remove
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Two-step verification --}}
            <div class="mt-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Two-step verification</p>
                    @if ($user->hasEnrolledSecondFactor())
                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-success-600 dark:text-success-400">
                            <x-filament::icon icon="heroicon-m-shield-check" class="w-3.5 h-3.5" />
                            Enabled
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-400">
                            <x-filament::icon icon="heroicon-m-shield-exclamation" class="w-3.5 h-3.5" />
                            Not enrolled
                        </span>
                    @endif
                </div>

                @php $factors = $user->enrolledSecondFactors(); @endphp

                @if ($factors === [])
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        This user has no second factor enrolled.
                        @if ($this->environment()?->requiresMultiFactorAuthentication())
                            They are required to enrol one before they can sign in.
                        @endif
                    </p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                        @foreach ($factors as $factor)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0 flex items-center gap-2">
                                    <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 text-gray-400 shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $factor->label() }}</p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $factor->description() }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    wire:click="removeSecondFactor('{{ $factor->value }}')"
                                    wire:confirm="Remove this second factor? The user will need to enrol a new one."
                                    class="text-[11px] font-semibold text-danger-600 dark:text-danger-400 hover:underline shrink-0"
                                >
                                    Remove
                                </button>
                            </li>
                        @endforeach
                    </ul>

                    @if ($user->hasRecoveryCodes())
                        <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                            Recovery codes are cleared automatically when the last factor is removed.
                        </p>
                    @endif
                @endif
            </div>

            {{-- Connected accounts --}}
            <div class="mt-5">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Connected accounts</p>

                @if ($socialAccounts->isEmpty())
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">No social accounts are connected.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                        @foreach ($socialAccounts as $account)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0 flex items-center gap-2.5">
                                    <x-filament::icon :icon="'kms-'.$account->provider" class="w-4 h-4 shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ ucfirst($account->provider) }}</p>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">
                                            {{ $account->nickname ?: $account->provider_id }}
                                            @if ($account->expires_at)
                                                · token expires {{ $account->expires_at->diffForHumans() }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    wire:click="disconnectSocialAccount({{ $account->getKey() }})"
                                    wire:confirm="Disconnect this account? The user will no longer be able to sign in with it."
                                    class="text-[11px] font-semibold text-danger-600 dark:text-danger-400 hover:underline shrink-0"
                                >
                                    Disconnect
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- Sessions --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Sessions</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Devices currently signed in to this account.</p>
                </div>
                @if ($sessions->isNotEmpty())
                    <button
                        type="button"
                        wire:click="revokeAllSessions"
                        wire:confirm="Sign this user out of every device?"
                        class="text-xs font-semibold text-danger-600 dark:text-danger-400 hover:underline"
                    >
                        Revoke all sessions
                    </button>
                @endif
            </div>

            @if ($sessions->isEmpty())
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">No active sessions. This user is currently signed out everywhere.</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                    @foreach ($sessions as $session)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0 flex items-center gap-3">
                                <span class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-white/5 flex items-center justify-center shrink-0">
                                    <x-filament::icon icon="heroicon-o-computer-desktop" class="w-4 h-4 text-gray-400" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $session->getDeviceName() }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 font-mono truncate">
                                        {{ $session->ip_address ?: 'unknown IP' }} · active {{ $session->last_active->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="revokeSession('{{ $session->getKey() }}')"
                                wire:confirm="Sign this device out?"
                                class="text-[11px] font-semibold text-danger-600 dark:text-danger-400 hover:underline shrink-0"
                            >
                                Revoke
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Metadata --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Metadata</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Custom attributes stored for this user in the {{ $app->environment }} environment.</p>
                </div>
                <button
                    type="button"
                    wire:click="mountAction('editMetadata')"
                    class="text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                >
                    Edit metadata
                </button>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach (['Public' => $metadata?->public_metadata, 'Private' => $metadata?->private_metadata] as $scope => $values)
                    <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $scope }} metadata</p>
                        @php $entries = (array) $values; @endphp
                        @if ($entries === [])
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">None set.</p>
                        @else
                            <dl class="mt-2 space-y-1.5">
                                @foreach ($entries as $key => $value)
                                    <div class="flex items-start justify-between gap-3">
                                        <dt class="text-[11px] font-mono text-gray-500 dark:text-gray-400">{{ $key }}</dt>
                                        <dd class="text-[11px] font-mono text-gray-900 dark:text-gray-100 truncate max-w-[55%]">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Membership --}}
        <section>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Membership</h3>
            <dl class="mt-4 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Application</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $app->name }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Organization</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $app->tenant->name }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Application role</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ ucfirst((string) ($membership->role ?? 'member')) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Organization role</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $this->tenantRole() ? ucfirst($this->tenantRole()) : '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Enrolled</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $membership?->created_at ? \Illuminate\Support\Carbon::parse($membership->created_at)->format('M j, Y H:i') : '—' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Account created</dt>
                    <dd class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->created_at?->format('M j, Y H:i') }}</dd>
                </div>
            </dl>
        </section>

        {{-- Danger zone --}}
        <section class="pt-2 border-t border-danger-200/70 dark:border-danger-500/20">
            <h3 class="text-base font-semibold text-danger-600 dark:text-danger-400">Danger zone</h3>
            <div class="mt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-1 max-w-xl">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $isBanned ? 'Restore this account' : 'Suspend this account' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                        @if ($isBanned)
                            This account is suspended
                            @if ($user->ban_reason)
                                ({{ $user->ban_reason }})
                            @endif
                            . Restoring allows the user to sign in again.
                        @else
                            Suspending blocks sign-in immediately and revokes every active session and token.
                        @endif
                    </p>
                </div>
                @if ($isBanned)
                    <button type="button" wire:click="mountAction('unban')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold bg-success-600 hover:bg-success-500 text-white shadow-xs transition-colors shrink-0 cursor-pointer">
                        <x-filament::icon icon="heroicon-m-arrow-path" class="w-3.5 h-3.5" />
                        Restore account
                    </button>
                @else
                    <button type="button" wire:click="mountAction('ban')" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold bg-danger-600 hover:bg-danger-500 text-white shadow-xs transition-colors shrink-0 cursor-pointer">
                        <x-filament::icon icon="heroicon-m-no-symbol" class="w-3.5 h-3.5" />
                        Suspend account
                    </button>
                @endif
            </div>
        </section>
    </div>
</x-filament-panels::page>
