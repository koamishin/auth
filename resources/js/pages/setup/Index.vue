<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Eye, EyeOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/setup';

const form = useForm({
    instance_name: 'Koamishin Auth',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const step = ref<1 | 2>(1);
const showPassword = ref(false);

const steps = [
    { id: 1, label: 'Instance' },
    { id: 2, label: 'Admin' },
    { id: 3, label: 'Organization' },
] as const;

const displayInstanceName = computed(
    () => form.instance_name.trim() || 'Koamishin Auth',
);

const passwordHint = computed(() => {
    if (!form.password && !form.password_confirmation) {
        return 'Use at least 8 characters.';
    }

    if (form.password.length > 0 && form.password.length < 8) {
        return 'Password must be at least 8 characters.';
    }

    if (
        form.password_confirmation.length > 0 &&
        form.password !== form.password_confirmation
    ) {
        return 'Passwords do not match.';
    }

    return null;
});

watch(
    () => form.errors.instance_name,
    (error) => {
        if (error) {
            step.value = 1;
        }
    },
);

function nextStep(): void {
    if (!form.instance_name.trim()) {
        form.setError('instance_name', 'Please enter an instance name.');

        return;
    }

    form.clearErrors('instance_name');
    step.value = 2;
}

function submit(): void {
    form.post(store.url(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background px-6 py-12 text-foreground antialiased"
    >
        <Head title="Instance Setup" />

        <main class="w-full max-w-[400px]">
            <!-- Minimalist 3-Step Onboarding Indicator -->
            <nav aria-label="Setup progress" class="mb-8">
                <ol class="grid grid-cols-3 gap-2">
                    <li
                        v-for="item in steps"
                        :key="item.id"
                        class="flex flex-col gap-2"
                    >
                        <div
                            class="h-[3px] w-full rounded-full transition-colors duration-200"
                            :class="
                                item.id < step
                                    ? 'bg-foreground'
                                    : item.id === step
                                      ? 'bg-foreground'
                                      : 'bg-border'
                            "
                        />
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-4 shrink-0 items-center justify-center rounded-full text-[10px] font-medium transition-colors"
                                :class="
                                    item.id < step
                                        ? 'bg-foreground text-background'
                                        : item.id === step
                                          ? 'bg-foreground text-background'
                                          : 'bg-muted text-muted-foreground'
                                "
                            >
                                <Check v-if="item.id < step" class="size-2.5" />
                                <span v-else>{{ item.id }}</span>
                            </span>
                            <span
                                class="truncate text-[12px] font-medium transition-colors"
                                :class="
                                    item.id <= step
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ item.label }}
                            </span>
                        </div>
                    </li>
                </ol>
            </nav>

            <header class="mb-7 flex flex-col items-center text-center">
                <div
                    class="mb-5 flex size-11 items-center justify-center rounded-2xl border border-border/70 bg-card shadow-xs"
                >
                    <AppLogoIcon class="size-6 text-foreground" />
                </div>

                <h1
                    class="text-[26px] leading-[1.15] font-semibold tracking-[-0.022em]"
                >
                    {{
                        step === 1
                            ? `Set up ${displayInstanceName}`
                            : 'Create administrator'
                    }}
                </h1>
                <p
                    class="mt-2 max-w-[320px] text-[14px] leading-[1.45] text-muted-foreground"
                >
                    {{
                        step === 1
                            ? 'Choose the name shown across your portal and sign-in screens.'
                            : `Create the primary administrator for ${displayInstanceName}.`
                    }}
                </p>
            </header>

            <!-- Step 1: Instance Name -->
            <form
                v-if="step === 1"
                class="space-y-5"
                @submit.prevent="nextStep"
            >
                <div class="space-y-1.5">
                    <Label
                        for="instance_name"
                        class="text-[13px] font-medium text-foreground/90"
                    >
                        Instance Name
                    </Label>
                    <Input
                        id="instance_name"
                        v-model="form.instance_name"
                        type="text"
                        required
                        autofocus
                        autocomplete="organization"
                        placeholder="Koamishin Auth"
                        class="h-10 rounded-xl border-border/80 bg-card/60 px-3.5 text-[15px] shadow-none transition-colors focus-visible:bg-background"
                    />
                    <InputError :message="form.errors.instance_name" />
                </div>

                <Button
                    type="submit"
                    class="h-10 w-full cursor-pointer rounded-xl text-[14px] font-medium transition-transform duration-100 ease-out active:scale-[0.985] motion-reduce:transform-none"
                >
                    Continue
                </Button>
            </form>

            <!-- Step 2: Administrator Account -->
            <form v-else class="space-y-5" @submit.prevent="submit">
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <Label
                            for="name"
                            class="text-[13px] font-medium text-foreground/90"
                        >
                            Administrator Name
                        </Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Alex Morgan"
                            class="h-10 rounded-xl border-border/80 bg-card/60 px-3.5 text-[15px] shadow-none transition-colors focus-visible:bg-background"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="space-y-1.5">
                        <Label
                            for="email"
                            class="text-[13px] font-medium text-foreground/90"
                        >
                            Email
                        </Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="admin@example.com"
                            class="h-10 rounded-xl border-border/80 bg-card/60 px-3.5 text-[15px] shadow-none transition-colors focus-visible:bg-background"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <Label
                                for="password"
                                class="text-[13px] font-medium text-foreground/90"
                            >
                                Password
                            </Label>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-[12px] text-muted-foreground transition-colors hover:text-foreground"
                                :aria-label="
                                    showPassword
                                        ? 'Hide passwords'
                                        : 'Show passwords'
                                "
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" class="size-3.5" />
                                <Eye v-else class="size-3.5" />
                                <span>{{
                                    showPassword ? 'Hide' : 'Show'
                                }}</span>
                            </button>
                        </div>
                        <Input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Create a password"
                            class="h-10 rounded-xl border-border/80 bg-card/60 px-3.5 text-[15px] shadow-none transition-colors focus-visible:bg-background"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="space-y-1.5">
                        <Label
                            for="password_confirmation"
                            class="text-[13px] font-medium text-foreground/90"
                        >
                            Confirm Password
                        </Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Re-enter password"
                            class="h-10 rounded-xl border-border/80 bg-card/60 px-3.5 text-[15px] shadow-none transition-colors focus-visible:bg-background"
                        />
                        <InputError
                            :message="form.errors.password_confirmation"
                        />
                    </div>

                    <p
                        v-if="passwordHint"
                        class="text-[12px] leading-relaxed text-muted-foreground"
                    >
                        {{ passwordHint }}
                    </p>
                </div>

                <div class="flex items-center gap-2.5">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 cursor-pointer rounded-xl px-4 text-[14px] font-medium transition-transform duration-100 ease-out active:scale-[0.985] motion-reduce:transform-none"
                        :disabled="form.processing"
                        @click="step = 1"
                    >
                        Back
                    </Button>
                    <Button
                        type="submit"
                        class="h-10 flex-1 cursor-pointer rounded-xl text-[14px] font-medium transition-transform duration-100 ease-out active:scale-[0.985] motion-reduce:transform-none"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" class="mr-2 size-4" />
                        <span>Continue</span>
                    </Button>
                </div>
            </form>
        </main>
    </div>
</template>
