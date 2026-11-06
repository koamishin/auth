<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SignInWithPasskeyButton from '@/components/SignInWithPasskeyButton.vue';
import SocialLoginButtons from '@/components/SocialLoginButtons.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import emailCode from '@/routes/auth/email-code';
import { store as hostedStore } from '@/routes/hosted';
import type { AppPageProps } from '@/types';

const props = defineProps<{
    application?: {
        name: string;
        organization: string;
        environment: string;
        allowSignups: boolean;
        passkeysEnabled: boolean;
        loginTitle?: string | null;
        loginDescription?: string | null;
    } | null;
    flow?: string | null;
    hosted?: boolean;
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    emailCodeSignin?: boolean;
    emailCodeLength?: number;
}>();

const page =
    usePage<AppPageProps<{ settingsFeatures?: { passkeys?: boolean } }>>();
const passkeysEnabled = props.application
    ? props.application.passkeysEnabled
    : (page.props.settingsFeatures?.passkeys ?? true);

// Passwordless sign-in with an emailed code, when the application allows it.
const codeSigninAvailable = computed(() => props.emailCodeSignin === true);
const codeLength = computed(() => props.emailCodeLength ?? 6);
const useCode = ref(false);
const codeRequested = ref(false);
const codeEmail = ref('');
const code = ref('');
const codeProcessing = ref(false);
const codeError = ref<string | null>(null);

const hostedHeaders = computed((): Record<string, string> =>
    props.hosted && props.flow
        ? {
              'X-Koamishin-Flow': props.flow,
              'X-Koamishin-Hosted': '1',
              'X-Koamishin-Screen': 'login',
          }
        : {},
);

function switchToCode() {
    useCode.value = true;
    codeError.value = null;
    codeEmail.value = '';
    code.value = '';
    codeRequested.value = false;
}

function switchToPassword() {
    useCode.value = false;
    codeError.value = null;
}

function editCodeEmail() {
    codeRequested.value = false;
    code.value = '';
    codeError.value = null;
}

function requestCode() {
    codeProcessing.value = true;
    codeError.value = null;

    router.post(
        emailCode.send.url(),
        { email: codeEmail.value, ...(props.flow ? { flow: props.flow } : {}) },
        {
            headers: hostedHeaders.value,
            onSuccess: () => {
                codeRequested.value = true;
            },
            onError: (errors) => {
                codeError.value = errors.email ?? 'Enter a valid email address.';
            },
            onFinish: () => {
                codeProcessing.value = false;
            },
        },
    );
}

function submitCode() {
    codeProcessing.value = true;
    codeError.value = null;

    router.post(
        emailCode.verify.url(),
        {
            email: codeEmail.value,
            code: code.value,
            ...(props.flow ? { flow: props.flow } : {}),
        },
        {
            headers: hostedHeaders.value,
            onError: (errors) => {
                codeError.value = errors.code ?? errors.email ?? 'That code is not correct.';
            },
            onFinish: () => {
                codeProcessing.value = false;
            },
        },
    );
}
</script>

<template>
    <AuthBase
        :title="
            application?.loginTitle ||
            (application
                ? `Sign in to ${application.name}`
                : 'Log in to your account')
        "
        :description="
            application?.loginDescription ||
            (application
                ? `${application.organization} · ${application.environment}`
                : 'Enter your email and password below to log in')
        "
    >
        <Head
            :title="application ? `Sign in to ${application.name}` : 'Log in'"
        />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <div
            v-if="$page.props.errors?.social"
            class="mb-4 text-center text-sm font-medium text-destructive"
        >
            {{ $page.props.errors.social }}
        </div>

        <Form
            v-if="!useCode"
            v-bind="store.form()"
            :headers="
                hosted && flow
                    ? {
                          'X-Koamishin-Flow': flow,
                          'X-Koamishin-Hosted': '1',
                          'X-Koamishin-Screen': 'login',
                      }
                    : {}
            "
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <input v-if="hosted" type="hidden" name="hosted" value="1" />
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label
                        v-if="!flow"
                        for="remember"
                        class="flex items-center space-x-3"
                    >
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>

            <div
                class="text-center text-sm text-muted-foreground"
                v-if="canRegister && (!application || application.allowSignups)"
            >
                Don't have an account?
                <button
                    v-if="hosted && flow"
                    type="button"
                    class="underline underline-offset-4"
                    @click="
                        router.post(hostedStore.url(), {
                            flow,
                            screen: 'register',
                            hosted: 1,
                        })
                    "
                >
                    Sign up
                </button>
                <TextLink
                    v-else
                    :href="
                        props.flow
                            ? register({ query: { flow: props.flow } })
                            : register()
                    "
                    :tabindex="5"
                    >Sign up</TextLink
                >
            </div>
        </Form>

        <!-- Passwordless sign-in with an emailed one-time code. -->
        <div v-if="useCode" class="flex flex-col gap-6">
            <div v-if="!codeRequested" class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="code-email">Email address</Label>
                    <Input
                        id="code-email"
                        v-model="codeEmail"
                        type="email"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder="email@example.com"
                        @keydown.enter.prevent="requestCode"
                    />
                    <InputError :message="codeError ?? undefined" />
                </div>

                <Button
                    type="button"
                    :disabled="codeProcessing || !codeEmail"
                    @click="requestCode"
                >
                    <Spinner v-if="codeProcessing" />
                    Email me a code
                </Button>
            </div>

            <div v-else class="grid gap-6">
                <p class="text-center text-sm text-muted-foreground">
                    We sent a code to
                    <span class="font-medium text-foreground">{{ codeEmail }}</span>.
                </p>

                <div class="grid gap-2">
                    <Label for="code">Sign-in code</Label>
                    <Input
                        id="code"
                        v-model="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        :maxlength="codeLength"
                        autofocus
                        class="text-center font-mono text-lg tracking-[0.4em]"
                        :placeholder="'0'.repeat(codeLength)"
                        @keydown.enter.prevent="submitCode"
                    />
                    <InputError :message="codeError ?? undefined" />
                </div>

                <Button
                    type="button"
                    :disabled="codeProcessing || code.length < codeLength"
                    @click="submitCode"
                >
                    <Spinner v-if="codeProcessing" />
                    Verify and sign in
                </Button>

                <div class="flex items-center justify-center gap-4 text-sm">
                    <button
                        type="button"
                        class="underline underline-offset-4"
                        @click="requestCode"
                    >
                        Send a new code
                    </button>
                    <button
                        type="button"
                        class="underline underline-offset-4"
                        @click="editCodeEmail"
                    >
                        Change email
                    </button>
                </div>
            </div>
        </div>

        <div v-if="codeSigninAvailable" class="text-center text-sm">
            <button
                v-if="!useCode"
                type="button"
                class="underline underline-offset-4"
                @click="switchToCode"
            >
                Sign in with an email code instead
            </button>
            <button
                v-else
                type="button"
                class="underline underline-offset-4"
                @click="switchToPassword"
            >
                Use a password instead
            </button>
        </div>

        <SignInWithPasskeyButton
            v-if="passkeysEnabled"
            :flow="flow"
            :hosted="hosted"
        />

        <SocialLoginButtons :flow="flow" :hosted="hosted" />
    </AuthBase>
</template>
