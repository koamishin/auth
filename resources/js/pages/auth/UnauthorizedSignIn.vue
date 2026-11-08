<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';

const props = defineProps<{
    application?: {
        name: string;
        organization: string;
        environment: string;
    } | null;
    flow?: string | null;
}>();
</script>

<template>
    <AuthBase
        title="Sign-in cancelled"
        :description="
            application
                ? `For your security, ${application.name} stopped this sign-in attempt.`
                : 'For your security, this sign-in attempt was stopped.'
        "
    >
        <Head title="Sign-in cancelled" />

        <div class="space-y-4 text-center text-sm text-muted-foreground">
            <p>
                The link was opened on a different device or browser from the
                one that requested it, so nothing was signed in here.
            </p>
            <p>
                Open the link on the device you started from, or
                <TextLink
                    :href="
                        props.flow
                            ? login({ query: { flow: props.flow } })
                            : login()
                    "
                    data-test="unauthorized-sign-in-restart"
                    >start again</TextLink
                >.
            </p>
        </div>
    </AuthBase>
</template>
