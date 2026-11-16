<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import ImpersonateBanner from '@/components/ImpersonateBanner.vue';
import KoamishinLogo from '@/components/KoamishinLogo.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
    logoUrl?: string | null;
    applicationName?: string;
}>();
</script>

<template>
    <div
        class="relative grid min-h-svh flex-col items-center justify-center bg-background px-6 text-foreground antialiased sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <ImpersonateBanner class="absolute top-0 right-0 left-0 z-50" />

        <div
            class="relative hidden h-full flex-col justify-between border-r border-border/60 bg-zinc-950 p-10 text-zinc-100 lg:flex"
        >
            <Link
                :href="home()"
                class="relative z-20 flex items-center gap-2.5 text-[15px] font-semibold tracking-[-0.015em]"
            >
                <img
                    v-if="logoUrl"
                    :src="logoUrl"
                    :alt="applicationName || 'Application'"
                    class="max-h-10 max-w-32 object-contain"
                />
                <KoamishinLogo v-else class="size-8" />
                <span>{{ applicationName || name }}</span>
            </Link>

            <div class="relative z-20 max-w-sm space-y-2">
                <p
                    class="text-[22px] leading-snug font-semibold tracking-[-0.02em] text-white"
                >
                    Scoped authentication for every environment.
                </p>
                <p
                    class="font-mono text-[11px] tracking-wider text-zinc-400 uppercase"
                >
                    OAuth 2.0 · PKCE · RS256 JWKS
                </p>
            </div>
        </div>

        <div class="py-12 lg:p-8">
            <div
                class="mx-auto flex w-full max-w-[380px] flex-col justify-center space-y-6"
            >
                <div class="flex flex-col items-center space-y-2 text-center">
                    <Link :href="home()" class="mb-2 lg:hidden">
                        <img
                            v-if="logoUrl"
                            :src="logoUrl"
                            :alt="applicationName || 'Application'"
                            class="mx-auto max-h-12 max-w-32 object-contain"
                        />
                        <KoamishinLogo v-else class="size-10" />
                    </Link>
                    <h1
                        v-if="title"
                        class="text-[24px] leading-[1.15] font-semibold tracking-[-0.025em]"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-[14px] leading-[1.45] text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
