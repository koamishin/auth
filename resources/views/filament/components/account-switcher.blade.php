@php
    use App\Services\Auth\MultiSession;

    $accounts = app(MultiSession::class)->accounts();

    if ($accounts === []) {
        return;
    }
@endphp

<div class="flex items-center">
    <x-filament::dropdown placement="bottom-end" teleport size="md">
        <x-slot name="trigger">
            <button
                type="button"
                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white/70 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 text-gray-800 dark:text-gray-200 transition-colors shadow-xs"
            >
                <x-filament::icon icon="heroicon-m-users" class="w-3.5 h-3.5 text-primary-500 shrink-0" />
                <span class="font-bold text-xs hidden sm:inline">Accounts</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="w-3 h-3 text-gray-400 shrink-0" />
            </button>
        </x-slot>

        <div class="p-2 text-[11px] text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider border-b border-gray-100 dark:border-white/5">
            Signed in as
        </div>

        @foreach ($accounts as $account)
            <form method="POST" action="{{ route('accounts.switch') }}" class="flex items-stretch gap-1">
                @csrf
                <input type="hidden" name="user_id" value="{{ $account['id'] }}">

                <button
                    type="submit"
                    class="flex-1 flex items-center gap-2 px-2 py-1.5 rounded-lg text-left hover:bg-gray-50 dark:hover:bg-white/10 transition-colors"
                >
                    <x-filament::icon
                        :icon="$account['current'] ? 'heroicon-m-check-circle' : 'heroicon-o-user-circle'"
                        class="w-4 h-4 shrink-0 {{ $account['current'] ? 'text-primary-500' : 'text-gray-400' }}"
                    />
                    <span class="min-w-0">
                        <span class="block truncate text-sm {{ $account['current'] ? 'font-bold text-primary-600 dark:text-primary-400' : 'text-gray-800 dark:text-gray-200' }}">
                            {{ $account['name'] }}
                        </span>
                        <span class="block truncate text-[11px] text-gray-500 dark:text-gray-400">{{ $account['email'] }}</span>
                    </span>
                </button>

                @if (! $account['current'])
                    <button
                        type="submit"
                        formaction="{{ route('accounts.remove') }}"
                        title="Sign out of this account here"
                        class="px-2 rounded-lg text-gray-400 hover:text-danger-600 hover:bg-danger-500/10 transition-colors"
                    >
                        <x-filament::icon icon="heroicon-m-x-mark" class="w-3.5 h-3.5" />
                    </button>
                @endif
            </form>
        @endforeach

        <div class="border-t border-gray-100 dark:border-white/5">
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item
                    :href="url('/login?add=1')"
                    tag="a"
                    icon="heroicon-m-plus"
                    color="primary"
                >
                    Add account
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </div>

        <div class="border-t border-gray-100 dark:border-white/5">
            <form method="POST" action="{{ route('accounts.flush') }}">
                @csrf
                <x-filament::dropdown.list.item
                    tag="button"
                    type="submit"
                    icon="heroicon-m-arrow-left-start-on-rectangle"
                    color="danger"
                >
                    Sign out of all accounts
                </x-filament::dropdown.list.item>
            </form>
        </div>
    </x-filament::dropdown>
</div>
