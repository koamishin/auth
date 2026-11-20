<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ImpersonateBanner from '@/components/ImpersonateBanner.vue';
import KoamishinLogo from '@/components/KoamishinLogo.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
    logoUrl?: string | null;
    applicationName?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center bg-background px-6 py-12 text-foreground antialiased"
    >
        <ImpersonateBanner class="absolute top-0 left-0 w-full" />

        <div class="w-full max-w-[380px]">
            <div class="flex flex-col gap-7">
                <div class="flex flex-col items-center text-center">
                    <Link
                        :href="home()"
                        class="mb-5 flex items-center justify-center transition-transform duration-100 ease-out active:scale-[0.96] motion-reduce:transform-none"
                    >
                        <img
                            v-if="logoUrl"
                            :src="logoUrl"
                            :alt="applicationName || 'Application'"
                            class="max-h-12 max-w-32 object-contain"
                        />
                        <KoamishinLogo v-else class="size-10" />
                        <span class="sr-only">{{ title }}</span>
                    </Link>

                    <div class="space-y-1.5">
                        <h1
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
                </div>

                <slot />
            </div>
        </div>
    </div>
</template>
