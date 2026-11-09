<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import SocialLoginButtons from '@/components/SocialLoginButtons.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { waitlist as waitlistStore } from '@/routes/auth';
import { store as hostedStore } from '@/routes/hosted';
const props = defineProps<{
    application?: {
        name: string;
        organization: string;
        environment: string;
        allowSignups: boolean;
        waitlist?: boolean;
        collectsUsername?: boolean;
        requiresUsername?: boolean;
        collectsPhone?: boolean;
        requiresPhone?: boolean;
    } | null;
    flow?: string | null;
    hosted?: boolean;
    passwordRules?: string | null;
    collectsNameParts?: boolean;
    waitlistJoined?: boolean;
}>();
</script>

<template>
    <AuthBase
        :title="
            application?.waitlist
                ? `Join the ${application.name} waitlist`
                : application
                  ? `Create your ${application.name} account`
                  : 'Create an account'
        "
        :description="
            application?.waitlist
                ? 'Sign-up is by invitation. Leave your address and we will be in touch.'
                : application
                  ? `${application.organization} · ${application.environment}`
                  : 'Enter your details below to create your account'
        "
    >
        <Head
            :title="
                application ? `Register for ${application.name}` : 'Register'
            "
        />

        <div
            v-if="$page.props.errors?.social"
            class="mb-4 text-center text-sm font-medium text-destructive"
        >
            {{ $page.props.errors.social }}
        </div>

        <div
            v-if="application?.waitlist && waitlistJoined"
            class="mb-4 text-center text-sm text-muted-foreground"
        >
            You're on the list. We'll be in touch when your invitation is ready.
        </div>

        <Form
            v-else-if="application?.waitlist"
            v-bind="waitlistStore.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="waitlist-email">Email address</Label>
                    <Input
                        id="waitlist-email"
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        placeholder="you@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Join the waitlist
                </Button>
            </div>
        </Form>

        <Form
            v-if="!application?.waitlist"
            v-bind="store.form()"
            :headers="
                hosted && flow
                    ? {
                          'X-Koamishin-Flow': flow,
                          'X-Koamishin-Hosted': '1',
                          'X-Koamishin-Screen': 'register',
                      }
                    : {}
            "
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <input v-if="flow" type="hidden" name="flow" :value="flow" />
            <input v-if="hosted" type="hidden" name="hosted" value="1" />
            <div class="grid gap-6">
                <div v-if="application?.collectsUsername" class="grid gap-2">
                    <Label for="username">Username</Label>
                    <Input
                        id="username"
                        name="username"
                        :required="application?.requiresUsername"
                        autocomplete="username"
                        placeholder="e.g. octocat"
                    />
                    <InputError :message="errors.username" />
                </div>
                <div v-if="application?.collectsPhone" class="grid gap-2">
                    <Label for="phone_number">Phone number</Label>
                    <Input
                        id="phone_number"
                        type="tel"
                        name="phone_number"
                        :required="application?.requiresPhone"
                        autocomplete="tel"
                        placeholder="+14155550123"
                    />
                    <InputError :message="errors.phone_number" />
                </div>
                <!-- Applications can collect a first and last name instead of one field. -->
                <div v-if="collectsNameParts" class="grid gap-6 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="first_name">First name</Label>
                        <Input
                            id="first_name"
                            type="text"
                            required
                            autofocus
                            :tabindex="1"
                            autocomplete="given-name"
                            name="first_name"
                        />
                        <InputError :message="errors.first_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="last_name">Last name</Label>
                        <Input
                            id="last_name"
                            type="text"
                            :tabindex="2"
                            autocomplete="family-name"
                            name="last_name"
                        />
                        <InputError :message="errors.last_name" />
                    </div>
                </div>

                <div v-else class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        name="name"
                        placeholder="Full name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="5"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <Spinner v-if="processing" />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <button
                    v-if="hosted && flow"
                    type="button"
                    class="underline underline-offset-4"
                    @click="
                        router.post(hostedStore.url(), {
                            flow,
                            screen: 'login',
                            hosted: 1,
                        })
                    "
                >
                    Log in
                </button>
                <TextLink
                    v-else
                    :href="
                        props.flow
                            ? login({ query: { flow: props.flow } })
                            : login()
                    "
                    class="underline underline-offset-4"
                    :tabindex="6"
                    >Log in</TextLink
                >
            </div>
        </Form>

        <SocialLoginButtons
            v-if="!application?.waitlist"
            :flow="flow"
            :hosted="hosted"
        />
    </AuthBase>
</template>
