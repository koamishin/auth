<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, ArrowUpRight, Check, Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import ImpersonateBanner from '@/components/ImpersonateBanner.vue';
import KoamishinLogo from '@/components/KoamishinLogo.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

defineProps<{
    canRegister?: boolean;
}>();

const selectedEnv = ref<'development' | 'production'>('development');
const selectedSdk = ref<'laravel' | 'nextjs'>('laravel');
const showPrivateMeta = ref(false);

const envConfig = computed(() =>
    selectedEnv.value === 'development'
        ? {
              label: 'development',
              pk: 'pk_test_9f8a2c4e1b',
              namespace: 'app:env_dev_01',
              policy: 'allow_signups: true',
              orgId: 'org_koamishin',
              appId: selectedSdk.value === 'laravel' ? '1' : '2',
          }
        : {
              label: 'production',
              pk: 'pk_live_3d71e80a4c',
              namespace: 'app:env_prod_01',
              policy: 'allow_signups: false (invite only)',
              orgId: 'org_koamishin',
              appId: selectedSdk.value === 'laravel' ? '1' : '2',
          },
);

const sdkSnippet = computed(() =>
    selectedSdk.value === 'laravel'
        ? `Route::get('/login', fn () => redirect()->away(\n    Koamishin::getLoginUrl('/dashboard')\n));`
        : `const claims = await verifySessionToken(token, {\n    portalUrl: process.env.NEXT_PUBLIC_KOAMISHIN_URL,\n    environment: '${selectedEnv.value}',\n});`,
);

const pillars = [
    {
        num: '01',
        title: 'Passkeys, TOTP & Email Codes',
        body: 'Enforce multi-factor authentication per environment with WebAuthn passkeys, authenticator apps, passwordless email codes, and new-device alerts.',
    },
    {
        num: '02',
        title: 'Scoped Management API',
        body: 'Authenticate server-to-server requests to /api/v1/* with sk_test_ or sk_live_ keys to manage users, metadata, memberships, and active sessions.',
    },
    {
        num: '03',
        title: 'Standard Webhooks',
        body: 'Deliver HMAC-SHA256 signed lifecycle events with Svix-compatible headers, automatic retries, and built-in SSRF URL protection.',
    },
];
</script>

<template>
    <div
        class="flex min-h-svh flex-col bg-background text-foreground antialiased selection:bg-foreground selection:text-background"
    >
        <Head title="Koamishin Auth" />

        <ImpersonateBanner />

        <!-- Translucent Top Bar -->
        <header
            class="sticky top-0 z-50 w-full border-b border-border/50 bg-background/80 backdrop-blur-xl"
        >
            <div
                class="mx-auto flex h-14 max-w-6xl items-center justify-between px-6"
            >
                <div class="flex items-center gap-2.5">
                    <KoamishinLogo class="size-7" />
                    <span class="text-[15px] font-semibold tracking-[-0.02em]">
                        {{ $page.props.name || 'Koamishin' }}
                    </span>
                    <span
                        class="hidden rounded-md border border-border/70 px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground sm:inline-block"
                    >
                        OIDC / OAuth2
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <a
                        href="/.well-known/openid-configuration"
                        class="hidden px-3 py-1.5 font-mono text-[12px] text-muted-foreground transition-colors hover:text-foreground sm:inline-block"
                    >
                        .well-known
                    </a>

                    <template v-if="$page.props.auth.user">
                        <Button
                            as-child
                            size="sm"
                            class="h-8 rounded-full px-4 text-[13px] font-medium transition-transform duration-100 ease-out active:scale-[0.97] motion-reduce:transform-none"
                        >
                            <Link :href="dashboard()">Dashboard</Link>
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            as-child
                            variant="ghost"
                            size="sm"
                            class="h-8 rounded-full px-3.5 text-[13px] font-medium"
                        >
                            <Link :href="login()">Sign in</Link>
                        </Button>
                        <Button
                            v-if="canRegister"
                            as-child
                            size="sm"
                            class="h-8 rounded-full px-4 text-[13px] font-medium transition-transform duration-100 ease-out active:scale-[0.97] motion-reduce:transform-none"
                        >
                            <Link :href="register()">Register</Link>
                        </Button>
                    </template>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <!-- Asymmetric Editorial Hero + Live Identity Inspector -->
            <section class="px-6 pt-16 pb-20 sm:pt-24 sm:pb-28">
                <div
                    class="mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-12 lg:gap-10"
                >
                    <!-- Left: Editorial Copy -->
                    <div class="lg:col-span-6">
                        <p
                            class="font-mono text-[11px] tracking-[0.16em] text-muted-foreground uppercase"
                        >
                            Koamishin Identity Platform
                        </p>

                        <h1
                            class="mt-4 text-4xl leading-[1.04] font-semibold tracking-[-0.035em] sm:text-5xl lg:text-[54px]"
                        >
                            Identity, scoped to every app.
                        </h1>

                        <p
                            class="mt-5 max-w-lg text-[16px] leading-[1.6] text-muted-foreground sm:text-[17px]"
                        >
                            A self-hosted OAuth 2.0 and OpenID Connect portal.
                            Each application environment gets its own keys, user
                            namespace, signup policy, and session lifecycle.
                        </p>

                        <div class="mt-8 flex flex-wrap items-center gap-3">
                            <Button
                                v-if="$page.props.auth.user"
                                as-child
                                size="lg"
                                class="h-11 rounded-full px-6 text-[14px] font-medium transition-transform duration-100 ease-out active:scale-[0.985] motion-reduce:transform-none"
                            >
                                <Link :href="dashboard()" class="gap-2">
                                    <span>Open Dashboard</span>
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <Button
                                v-else
                                as-child
                                size="lg"
                                class="h-11 rounded-full px-6 text-[14px] font-medium transition-transform duration-100 ease-out active:scale-[0.985] motion-reduce:transform-none"
                            >
                                <Link :href="login()" class="gap-2">
                                    <span>Sign in to Portal</span>
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>

                            <Button
                                as-child
                                variant="outline"
                                size="lg"
                                class="h-11 rounded-full border-border/80 px-5 text-[14px] font-medium"
                            >
                                <a
                                    href="https://github.com/koamishin/auth"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="gap-1.5"
                                >
                                    <span>GitHub</span>
                                    <ArrowUpRight class="size-3.5" />
                                </a>
                            </Button>
                        </div>

                        <!-- Direct Protocol Endpoints -->
                        <div
                            class="mt-10 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-border/60 pt-5 font-mono text-[12px] text-muted-foreground"
                        >
                            <a
                                href="/.well-known/openid-configuration"
                                class="transition-colors hover:text-foreground"
                            >
                                /.well-known/openid-configuration
                            </a>
                            <a
                                href="/oauth/jwks"
                                class="transition-colors hover:text-foreground"
                            >
                                /oauth/jwks
                            </a>
                        </div>
                    </div>

                    <!-- Right: Live Koamishin Context Inspector -->
                    <div class="lg:col-span-6">
                        <div
                            class="overflow-hidden rounded-2xl border border-border/80 bg-card/60 shadow-sm"
                        >
                            <!-- Inspector Controls -->
                            <div
                                class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-4 py-3"
                            >
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded-lg px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                        :class="
                                            selectedEnv === 'development'
                                                ? 'bg-foreground text-background'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="selectedEnv = 'development'"
                                    >
                                        development
                                    </button>
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded-lg px-2.5 py-1 font-mono text-[11px] font-medium transition-colors"
                                        :class="
                                            selectedEnv === 'production'
                                                ? 'bg-foreground text-background'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="selectedEnv = 'production'"
                                    >
                                        production
                                    </button>
                                </div>

                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors"
                                        :class="
                                            selectedSdk === 'laravel'
                                                ? 'bg-muted text-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="selectedSdk = 'laravel'"
                                    >
                                        Laravel
                                    </button>
                                    <button
                                        type="button"
                                        class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-medium transition-colors"
                                        :class="
                                            selectedSdk === 'nextjs'
                                                ? 'bg-muted text-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        @click="selectedSdk = 'nextjs'"
                                    >
                                        Next.js
                                    </button>
                                </div>
                            </div>

                            <!-- Environment Metadata Row -->
                            <div
                                class="grid grid-cols-2 gap-px border-b border-border/60 bg-border/60 text-[12px]"
                            >
                                <div class="bg-background/90 px-4 py-3">
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                    >
                                        Publishable Key
                                    </span>
                                    <code
                                        class="mt-0.5 block font-mono text-foreground"
                                    >
                                        {{ envConfig.pk }}
                                    </code>
                                </div>
                                <div class="bg-background/90 px-4 py-3">
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                    >
                                        Identity Namespace
                                    </span>
                                    <code
                                        class="mt-0.5 block font-mono text-foreground"
                                    >
                                        {{ envConfig.namespace }}
                                    </code>
                                </div>
                            </div>

                            <!-- Scoped /api/oauth/userinfo Payload -->
                            <div
                                class="p-4 font-mono text-[12px] leading-relaxed"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between text-[11px] text-muted-foreground"
                                >
                                    <span>GET /api/oauth/userinfo</span>
                                    <span>{{ envConfig.policy }}</span>
                                </div>
                                <pre
                                    class="overflow-x-auto rounded-xl border border-border/60 bg-background/80 p-3.5 text-[12px] text-foreground"
                                ><code>{
  "sub": "42",
  "organization_id": "{{ envConfig.orgId }}",
  "application_id": "{{ envConfig.appId }}",
  "environment": "{{ envConfig.label }}",
  "roles": ["member"],
  "public_metadata": { "plan": "pro" }
}</code></pre>
                            </div>

                            <!-- Matching SDK Call -->
                            <div
                                class="border-t border-border/60 bg-muted/30 px-4 py-3.5"
                            >
                                <pre
                                    class="overflow-x-auto font-mono text-[11.5px] leading-relaxed text-muted-foreground"
                                ><code>{{ sdkSnippet }}</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Asymmetric Bento: Isolation + Zero-Hop Verification -->
            <section class="px-6 pb-20">
                <div class="mx-auto grid max-w-6xl gap-4 lg:grid-cols-12">
                    <!-- Metadata & Isolation Panel -->
                    <div
                        class="flex flex-col justify-between rounded-2xl border border-border/70 bg-card/40 p-6 sm:p-8 lg:col-span-7"
                    >
                        <div>
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <h2
                                    class="text-lg font-semibold tracking-[-0.02em]"
                                >
                                    Environment-scoped metadata
                                </h2>
                                <button
                                    type="button"
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-border/70 bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground"
                                    @click="showPrivateMeta = !showPrivateMeta"
                                >
                                    <Lock class="size-3" />
                                    <span>
                                        {{
                                            showPrivateMeta
                                                ? 'Showing private_metadata'
                                                : 'Inspect private_metadata'
                                        }}
                                    </span>
                                </button>
                            </div>
                            <p
                                class="mt-2 max-w-lg text-[14px] leading-relaxed text-muted-foreground"
                            >
                                Public metadata is exposed in
                                <code class="font-mono text-xs text-foreground">
                                    userinfo
                                </code>
                                and session tokens when
                                <code class="font-mono text-xs text-foreground">
                                    metadata:public
                                </code>
                                is granted. Private metadata stays strictly
                                server-side on
                                <code class="font-mono text-xs text-foreground">
                                    /api/v1/*
                                </code>
                                .
                            </p>
                        </div>

                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            <div
                                class="rounded-xl border border-border/60 bg-background/80 p-4"
                            >
                                <div
                                    class="flex items-center justify-between text-[11px] font-medium text-muted-foreground"
                                >
                                    <span>public_metadata</span>
                                    <span
                                        class="inline-flex items-center gap-1 text-foreground"
                                    >
                                        <Check class="size-3" />
                                        Token &amp; API
                                    </span>
                                </div>
                                <code
                                    class="mt-2 block font-mono text-[12px] text-foreground"
                                >
                                    { "plan": "pro", "theme": "dark" }
                                </code>
                            </div>

                            <div
                                class="rounded-xl border border-border/60 bg-background/80 p-4 transition-opacity"
                                :class="
                                    showPrivateMeta
                                        ? 'opacity-100'
                                        : 'opacity-60'
                                "
                            >
                                <div
                                    class="flex items-center justify-between text-[11px] font-medium text-muted-foreground"
                                >
                                    <span>private_metadata</span>
                                    <span>sk_* / Server only</span>
                                </div>
                                <code
                                    class="mt-2 block font-mono text-[12px] text-foreground"
                                >
                                    {{
                                        showPrivateMeta
                                            ? '{ "stripe_id": "cus_9xK2" }'
                                            : '{ "••••": "••••••••" }'
                                    }}
                                </code>
                            </div>
                        </div>
                    </div>

                    <!-- PKCE & Session Isolation Panel -->
                    <div
                        class="flex flex-col justify-between rounded-2xl border border-border/70 bg-card/40 p-6 sm:p-8 lg:col-span-5"
                    >
                        <div>
                            <h2
                                class="text-lg font-semibold tracking-[-0.02em]"
                            >
                                Isolated auth flows
                            </h2>
                            <p
                                class="mt-2 text-[14px] leading-relaxed text-muted-foreground"
                            >
                                Every OAuth authorization runs under an isolated
                                <code class="font-mono text-xs text-foreground">
                                    application_&lt;flow&gt;
                                </code>
                                guard with mandatory S256 PKCE for public
                                clients, leaving admin and sibling app sessions
                                untouched.
                            </p>
                        </div>

                        <dl
                            class="mt-6 space-y-2.5 border-t border-border/60 pt-5 font-mono text-[12px]"
                        >
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">PKCE</dt>
                                <dd class="text-foreground">S256 enforced</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">Signing</dt>
                                <dd class="text-foreground">
                                    RS256 via /oauth/jwks
                                </dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">Consent</dt>
                                <dd class="text-foreground">
                                    Per-environment toggle
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- Editorial 3-Column Technical Pillars (No Icon Boxes) -->
            <section class="px-6 pb-24">
                <div class="mx-auto grid max-w-6xl gap-8 sm:grid-cols-3">
                    <div
                        v-for="pillar in pillars"
                        :key="pillar.num"
                        class="border-t border-border/70 pt-5"
                    >
                        <span
                            class="font-mono text-[11px] text-muted-foreground"
                        >
                            {{ pillar.num }}
                        </span>
                        <h3
                            class="mt-2 text-[15px] font-semibold tracking-[-0.015em]"
                        >
                            {{ pillar.title }}
                        </h3>
                        <p
                            class="mt-2 text-[13.5px] leading-[1.6] text-muted-foreground"
                        >
                            {{ pillar.body }}
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <!-- Minimal Footer -->
        <footer class="border-t border-border/50">
            <div
                class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-7 text-[13px] text-muted-foreground sm:flex-row"
            >
                <p>
                    &copy; {{ new Date().getFullYear() }}
                    {{ $page.props.name || 'Koamishin Auth' }}
                </p>
                <div class="flex items-center gap-6 font-mono text-[12px]">
                    <a
                        href="/.well-known/openid-configuration"
                        class="transition-colors hover:text-foreground"
                    >
                        openid-configuration
                    </a>
                    <a
                        href="/oauth/jwks"
                        class="transition-colors hover:text-foreground"
                    >
                        jwks
                    </a>
                    <a
                        href="https://github.com/koamishin/auth"
                        target="_blank"
                        rel="noreferrer"
                        class="transition-colors hover:text-foreground"
                    >
                        github
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
