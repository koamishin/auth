<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as hostedStore } from '@/routes/hosted';

const props = defineProps<{
    application?: { name: string; environment: string } | null;
    flow?: string | null;
    hosted?: boolean;
    requiresConfirmation?: boolean;
    continueUrl: string;
    enrolled?: boolean;
}>();

const applicationName = computed(
    () => props.application?.name ?? 'this application',
);

// The component toggles enrolment locally, so mirror the prop into state.
const enrolled = ref(props.enrolled ?? false);

function continueToApplication() {
    if (props.hosted && props.flow) {
        router.post(hostedStore.url(), {
            flow: props.flow,
            hosted: 1,
            screen: 'mfa',
        });

        return;
    }

    window.location.href = props.continueUrl;
}
</script>

<template>
    <AuthLayout
        title="Set up two-step verification"
        :description="`${applicationName} requires a second factor before you can continue.`"
    >
        <Head title="Two-step verification" />

        <div class="flex flex-col gap-6">
            <ManageTwoFactor
                v-model:enabled="enrolled"
                :requires-confirmation="requiresConfirmation ?? false"
            />

            <Button type="button" @click="continueToApplication">
                Continue to {{ applicationName }}
            </Button>
        </div>
    </AuthLayout>
</template>
