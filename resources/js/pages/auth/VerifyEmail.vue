<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import verificationCode from '@/routes/verification/code';
import { store as hostedStore } from '@/routes/hosted';

const props = defineProps<{
    status?: string;
    flow?: string | null;
    hosted?: boolean;
    verificationMethod?: 'link' | 'code';
    emailCodeLength?: number;
}>();

const usingCode = computed(() => props.verificationMethod === 'code');
const codeLength = computed(() => props.emailCodeLength ?? 6);

const code = ref('');
const submitting = ref(false);
const resent = ref(false);
const codeError = ref<string | null>(null);

const hostedHeaders = computed((): Record<string, string> =>
    props.hosted && props.flow
        ? {
              'X-Koamishin-Flow': props.flow,
              'X-Koamishin-Hosted': '1',
              'X-Koamishin-Screen': 'verify',
          }
        : {},
);

function submitCode() {
    submitting.value = true;
    codeError.value = null;

    router.post(
        verificationCode.store.url(),
        { code: code.value, ...(props.flow ? { flow: props.flow } : {}) },
        {
            headers: hostedHeaders.value,
            onError: (errors) => {
                codeError.value = errors.code ?? 'That code is not correct.';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function resendVerificationCode() {
    resent.value = false;

    router.post(
        verificationCode.resend.url(),
        { ...(props.flow ? { flow: props.flow } : {}) },
        {
            headers: hostedHeaders.value,
            onSuccess: () => {
                resent.value = true;
                code.value = '';
            },
        },
    );
}
</script>

<template>
    <AuthLayout
        title="Verify email"
        :description="
            usingCode
                ? 'Enter the verification code we emailed you to confirm your address.'
                : 'Please verify your email address by clicking on the link we just emailed to you.'
        "
    >
        <Head title="Email verification" />

        <div
            v-if="status === 'verification-link-sent' || resent"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{
                usingCode
                    ? 'A new verification code has been sent to your email address.'
                    : 'A new verification link has been sent to the email address you provided during registration.'
            }}
        </div>

        <div
            v-if="usingCode"
            class="flex flex-col gap-4"
        >
            <div class="grid gap-2">
                <Label for="code">Verification code</Label>
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
                :disabled="submitting || code.length < codeLength"
                @click="submitCode"
            >
                <Spinner v-if="submitting" />
                Verify email
            </Button>

            <Button
                type="button"
                variant="ghost"
                :disabled="submitting"
                @click="resendVerificationCode"
            >
                Send a new code
            </Button>
        </div>

        <Form
            v-else
            v-bind="send.form()"
            :headers="hostedHeaders"
            class="space-y-6 text-center"
            v-slot="{ processing }"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <input v-if="hosted" type="hidden" name="hosted" value="1" />
            <Button :disabled="processing" variant="secondary">
                <Spinner v-if="processing" />
                Resend verification email
            </Button>
        </Form>

        <Button
            v-if="hosted && flow"
            type="button"
            variant="ghost"
            @click="
                router.post(hostedStore.url(), {
                    flow,
                    hosted: 1,
                    screen: 'continue',
                })
            "
        >
            Continue
        </Button>

        <TextLink
            v-if="!flow"
            :href="logout()"
            as="button"
            class="mx-auto block text-sm"
        >
            Log out
        </TextLink>
    </AuthLayout>
</template>
