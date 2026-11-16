<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ImpersonateBanner from '@/components/ImpersonateBanner.vue';
import KoamishinLogo from '@/components/KoamishinLogo.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
        class="relative flex min-h-svh flex-col items-center justify-center bg-muted/40 px-6 py-12 text-foreground antialiased"
    >
        <ImpersonateBanner class="absolute top-0 left-0 w-full" />

        <div class="flex w-full max-w-[420px] flex-col gap-6">
            <Link
                :href="home()"
                class="flex items-center justify-center self-center transition-transform duration-100 ease-out active:scale-[0.96] motion-reduce:transform-none"
            >
                <img
                    v-if="logoUrl"
                    :src="logoUrl"
                    :alt="applicationName || 'Application'"
                    class="max-h-12 max-w-32 object-contain"
                />
                <KoamishinLogo v-else class="size-10" />
            </Link>

            <Card
                class="rounded-2xl border-border/70 bg-card/95 shadow-sm backdrop-blur-sm"
            >
                <CardHeader class="px-8 pt-8 pb-0 text-center">
                    <CardTitle
                        class="text-[22px] leading-[1.2] font-semibold tracking-[-0.022em]"
                    >
                        {{ title }}
                    </CardTitle>
                    <CardDescription
                        v-if="description"
                        class="mt-1.5 text-[13.5px] leading-relaxed"
                    >
                        {{ description }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="px-8 py-7">
                    <slot />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
