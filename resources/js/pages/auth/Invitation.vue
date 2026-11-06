<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import invitation from '@/routes/invitations';

const props = defineProps<{
    state: 'valid' | 'invalid';
    token?: string;
    email?: string;
    role?: string;
    application?: { name: string; organization: string; environment: string } | null;
    expiresAt?: string;
    signedInAs?: string | null;
    matchesSignedInUser?: boolean;
    accountExists?: boolean;
    passwordRules?: string | null;
}>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

function accept() {
    router.post(invitation.accept.url({ token: props.token ?? '' }), {}, { preserveScroll: true });
}

function register() {
    form.post(invitation.register.url({ token: props.token ?? '' }));
}
</script>

<template>
    <AuthLayout
        :title="
            state === 'invalid'
                ? 'Invitation unavailable'
                : `Join ${application?.name ?? 'this application'}`
        "
        :description="
            state === 'invalid'
                ? 'This invitation has expired, was already used, or was revoked. Ask an administrator for a new one.'
                : `${application?.organization} invited ${email} to join the ${application?.environment} environment.`
        "
    >
        <Head title="Application invitation" />

        <div v-if="state === 'invalid'" class="text-center">
            <Button variant="secondary" @click="router.visit(login.url())">
                Go to sign in
            </Button>
        </div>

        <div v-else class="flex flex-col gap-6">
            <!-- Signed in with the invited address: one click to join. -->
            <template v-if="matchesSignedInUser">
                <p class="text-center text-sm text-muted-foreground">
                    You are signed in as
                    <span class="font-medium text-foreground">{{ signedInAs }}</span>, which matches this invitation.
                </p>

                <Button type="button" @click="accept">Accept invitation</Button>
            </template>

            <!-- Signed in as somebody else. -->
            <template v-else-if="signedInAs">
                <p class="text-center text-sm text-muted-foreground">
                    You are signed in as
                    <span class="font-medium text-foreground">{{ signedInAs }}</span>, but this invitation was sent to
                    <span class="font-medium text-foreground">{{ email }}</span>.
                </p>

                <Button variant="secondary" @click="router.visit(login.url())">
                    Sign in with the invited address
                </Button>
            </template>

            <!-- Not signed in and the address has no account: create one. -->
            <form
                v-else-if="!accountExists"
                class="flex flex-col gap-6"
                @submit.prevent="register"
            >
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" :model-value="email" disabled />
                    <p class="text-xs text-muted-foreground">
                        This is the address the invitation was sent to.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="name">Your name</Label>
                    <Input id="name" v-model="form.name" required autocomplete="name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="new-password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                    />
                </div>

                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Create account and join
                </Button>
            </form>

            <!-- Not signed in but the address already has an account. -->
            <template v-else>
                <p class="text-center text-sm text-muted-foreground">
                    An account already exists for
                    <span class="font-medium text-foreground">{{ email }}</span>. Sign in to accept the invitation.
                </p>

                <Button variant="secondary" @click="router.visit(login.url())">
                    Sign in
                </Button>
            </template>
        </div>
    </AuthLayout>
</template>
