<script setup lang="ts">
import { computed, onMounted, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import AuthCardLayout from '@/layouts/auth/AuthCardLayout.vue';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { themes } from '@/conf/themes';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
onMounted(() => {
    watchEffect(() => {
        const screens: Record<string, string> = {
            'auth/Login': 'login',
            'auth/Register': 'register',
            'auth/TwoFactorChallenge': 'two-factor',
            'auth/VerifyEmail': 'verify',
            'auth/oauth/Authorize': 'continue',
        };
        const screen = screens[page.component];
        if (
            page.props.hosted &&
            typeof page.props.flow === 'string' &&
            screen
        ) {
            try {
                sessionStorage.setItem(
                    'koamishin.hosted',
                    JSON.stringify({ flow: page.props.flow, screen }),
                );
            } catch {
                // The current form can still be submitted without session storage.
            }
        }
    });
});
const application = computed(
    () =>
        page.props.application as {
            logoUrl?: string | null;
            name?: string;
        } | null,
);

const hostedTheme = computed(() => {
    const theme = page.props.authColorTheme;

    if (!themes.some((config) => config.id === theme)) {
        return null;
    }

    return theme;
});

const hostedBackground = computed(() => {
    const background = (page.props as AppPageProps).authBackground;

    return background === 'gradient' || background === 'spotlight' || background === 'grid' ? background : null;
});

const appearancePreview = computed(() => (page.props as AppPageProps).appearancePreview ?? null);

const layoutComponent = computed(() => {
    const layout = page.props.authLayout ?? 'simple';

    switch (layout) {
        case 'card':
            return AuthCardLayout;
        case 'split':
            return AuthSplitLayout;
        default:
            return AuthSimpleLayout;
    }
});
</script>

<template>
    <div
        :class="[
            hostedTheme
                ? [
                      'hosted-auth-theme',
                      'bg-background',
                      'font-sans',
                      'text-foreground',
                      `theme-${hostedTheme}`,
                  ]
                : undefined,
            hostedBackground ? `auth-bg-${hostedBackground}` : undefined,
        ]"
    >
        <component
            :is="layoutComponent"
            :title="title"
            :description="description"
            :logo-url="application?.logoUrl"
            :application-name="application?.name"
        >
            <slot />
        </component>
        <a
            v-if="appearancePreview"
            :href="appearancePreview.backUrl"
            class="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-md items-center justify-center gap-2 rounded-full border border-white/10 bg-gray-950/90 px-4 py-2 text-xs font-medium text-white shadow-lg backdrop-blur transition-colors hover:bg-gray-900"
        >
            <span class="inline-flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                Previewing <strong>{{ appearancePreview.environment }}</strong> appearance
            </span>
            <span class="text-white/60">·</span>
            <span class="font-semibold">Back to editor</span>
        </a>
    </div>
</template>
