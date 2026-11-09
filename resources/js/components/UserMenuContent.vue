<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, LogOut, Settings, UserPlus, X } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { flush, remove, switchMethod } from '@/routes/accounts';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { AppPageProps, SwitchableAccount, User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    router.flushAll();
};

const page = usePage<AppPageProps>();
const accounts = computed<SwitchableAccount[]>(
    () => page.props.auth?.accounts ?? [],
);

const switchAccount = (id: number) =>
    router.post(switchMethod.url(), { user_id: id });
const removeAccount = (id: number) =>
    router.post(remove.url(), { user_id: id });
const signOutAll = () => router.post(flush.url());

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <template v-if="accounts.length > 0">
        <DropdownMenuSeparator />
        <DropdownMenuLabel
            class="px-1 py-1 text-xs font-medium text-muted-foreground"
        >
            Signed in as
        </DropdownMenuLabel>
        <DropdownMenuGroup>
            <DropdownMenuItem
                v-for="account in accounts"
                :key="account.id"
                :as-child="true"
            >
                <div
                    class="flex w-full cursor-pointer items-center gap-2"
                    @click="switchAccount(account.id)"
                >
                    <Check
                        v-if="account.current"
                        class="h-4 w-4 shrink-0 text-primary"
                    />
                    <span v-else class="h-4 w-4 shrink-0" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm">{{
                            account.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{ account.email }}
                        </span>
                    </span>
                    <button
                        v-if="!account.current"
                        type="button"
                        class="rounded p-0.5 text-muted-foreground hover:text-destructive"
                        title="Sign out of this account here"
                        @click.stop="removeAccount(account.id)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
            </DropdownMenuItem>
        </DropdownMenuGroup>
        <DropdownMenuGroup>
            <DropdownMenuItem :as-child="true">
                <Link class="block w-full cursor-pointer" href="/login?add=1">
                    <UserPlus class="mr-2 h-4 w-4" />
                    Add account
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>

    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
    <DropdownMenuItem v-if="accounts.length > 1" @select="signOutAll">
        <LogOut class="mr-2 h-4 w-4" />
        Sign out of all accounts
    </DropdownMenuItem>
</template>
