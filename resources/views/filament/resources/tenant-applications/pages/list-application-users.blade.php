@php
    $invitations = $this->pendingInvitations();
@endphp

<x-filament-panels::page>
    {{ $this->table }}

    @if ($invitations->isNotEmpty())
        <section class="mt-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Pending invitations</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        These addresses have been invited but have not joined yet.
                    </p>
                </div>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ $invitations->count() }} waiting
                </span>
            </div>

            <ul class="mt-4 divide-y divide-gray-100 dark:divide-white/5 border-y border-gray-100 dark:border-white/5">
                @foreach ($invitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0 flex items-center gap-3">
                            <span class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-white/5 flex items-center justify-center shrink-0">
                                <x-filament::icon icon="heroicon-o-envelope" class="w-4 h-4 text-gray-400" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $invitation->email }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ ucfirst($invitation->role) }}
                                    · invited {{ $invitation->created_at?->diffForHumans() }}
                                    · expires {{ $invitation->expires_at->diffForHumans() }}
                                    @if ($invitation->inviter)
                                        by {{ $invitation->inviter->name }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 shrink-0">
                            <button
                                type="button"
                                wire:click="resendInvitation({{ $invitation->getKey() }})"
                                class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 hover:underline"
                            >
                                Resend
                            </button>
                            <button
                                type="button"
                                wire:click="revokeInvitation({{ $invitation->getKey() }})"
                                wire:confirm="Revoke this invitation? The link will stop working."
                                class="text-[11px] font-semibold text-danger-600 dark:text-danger-400 hover:underline"
                            >
                                Revoke
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-filament-panels::page>
