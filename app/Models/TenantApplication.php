<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string|null $oauth_client_id
 * @property string $name
 * @property string|null $framework
 * @property string $environment
 * @property array<string>|null $redirect_uris
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Client|null $oauthClient
 */
class TenantApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'oauth_client_id',
        'name',
        'framework',
        'environment',
        'redirect_uris',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(?string $environment = null): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'tenant_application_user')
            ->withPivot(['role', 'environment', 'last_sign_in_at'])
            ->withTimestamps();

        if ($environment !== null) {
            $relation->wherePivot('environment', $environment);
        }

        return $relation;
    }

    /**
     * @return HasMany<TenantApplicationEnvironment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(TenantApplicationEnvironment::class);
    }

    /**
     * Get or create the configuration for a specific environment.
     */
    public function getEnvironmentConfig(?string $environment = null): TenantApplicationEnvironment
    {
        $env = $environment ?? $this->environment;

        /** @var TenantApplicationEnvironment|null $existing */
        $existing = $this->environments()->where('environment', $env)->first();

        if ($existing instanceof TenantApplicationEnvironment) {
            if (! filled($existing->publishable_key)) {
                $existing->forceFill([
                    'publishable_key' => TenantApplicationEnvironment::generatePublishableKey($env),
                ])->save();
            }

            return $existing;
        }

        $legacyClient = $env === $this->environment ? $this->oauthClient : null;
        $clientType = $legacyClient instanceof Client
            ? ($legacyClient->confidential() ? 'confidential' : 'public')
            : null;

        /** @var TenantApplicationEnvironment $config */
        $config = $this->environments()->firstOrCreate([
            'environment' => $env,
        ], [
            'oauth_client_id' => $env === $this->environment ? $this->oauth_client_id : null,
            'publishable_key' => TenantApplicationEnvironment::generatePublishableKey($env),
            'client_type' => $clientType,
            'redirect_uris' => $env === $this->environment ? ($this->redirect_uris ?? []) : [],
            'settings' => [
                'allow_signups' => false,
                // Must match what the environment reads, or it would be ignored.
                ...($env === 'production' ? ['email_verification_required' => true] : []),
            ],
        ]);

        return $config;
    }

    public function getPublishableKey(?string $environment = null): string
    {
        $env = $environment ?? $this->environment;
        $config = $this->getEnvironmentConfig($env);

        return $config->publishable_key ?? ($env === 'production' ? 'pk_live_your_key' : 'pk_test_your_key');
    }

    public function getEnvSnippet(?string $environment = null, ?string $clientSecret = null): string
    {
        $env = $environment ?? $this->environment;
        $portalUrl = request()->hasHeader('Host')
            ? request()->getSchemeAndHttpHost()
            : rtrim((string) config('app.url'), '/');
        $config = $this->getEnvironmentConfig($env);
        $publishableKey = $config->publishable_key ?? ($env === 'production' ? 'pk_live_your_key' : 'pk_test_your_key');
        $callback = $config->redirect_uris[0] ?? 'configure-your-callback';

        $secret = $clientSecret ?? 'YOUR_SECRET_KEY_HERE';

        if ($this->framework === 'laravel') {
            return <<<ENV
KOAMISHIN_PORTAL_URL={$portalUrl}
KOAMISHIN_APPLICATION_ID={$this->id}
KOAMISHIN_ENVIRONMENT={$env}
KOAMISHIN_PUBLISHABLE_KEY={$publishableKey}
KOAMISHIN_SECRET_KEY={$secret}
KOAMISHIN_REDIRECT_URI={$callback}
ENV;
        }

        if ($this->framework === 'nodejs') {
            return <<<ENV
KOAMISHIN_PORTAL_URL={$portalUrl}
KOAMISHIN_ENVIRONMENT={$env}
KOAMISHIN_PUBLISHABLE_KEY={$publishableKey}
KOAMISHIN_SECRET_KEY={$secret}
KOAMISHIN_REDIRECT_URI={$callback}
ENV;
        }

        if ($this->framework === 'nextjs') {
            return <<<ENV
NEXT_PUBLIC_KOAMISHIN_PORTAL_URL={$portalUrl}
NEXT_PUBLIC_KOAMISHIN_PUBLISHABLE_KEY={$publishableKey}
NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI={$callback}
KOAMISHIN_SECRET_KEY={$secret}
ENV;
        }

        return <<<ENV
VITE_KOAMISHIN_PORTAL_URL={$portalUrl}
VITE_KOAMISHIN_PUBLISHABLE_KEY={$publishableKey}
VITE_KOAMISHIN_REDIRECT_URI={$callback}
ENV;
    }

    /**
     * Get the active application for the given (or current) tenant.
     */
    public static function current(?Tenant $tenant = null): ?self
    {
        $tenant ??= Filament::getTenant();

        if (! $tenant) {
            return null;
        }

        $appId = session('current_application_id');

        $tenantKey = $tenant->getKey();

        if ($appId) {
            /** @var self|null $app */
            $app = self::query()->where('tenant_id', $tenantKey)->find($appId);
            if ($app) {
                return $app;
            }
        }

        /** @var self|null $default */
        $default = self::query()->where('tenant_id', $tenantKey)->first();
        if ($default) {
            session(['current_application_id' => $default->id]);

            return $default;
        }

        return null;
    }

    public function getFrameworkLabel(): string
    {
        return match ($this->framework) {
            null, '' => 'Not connected',
            'laravel' => 'Laravel (PHP)',
            'nextjs' => 'Next.js (React)',
            'vue' => 'Vue 3 / Nuxt',
            'react' => 'React (Vite / SPA)',
            'nodejs' => 'Node.js (Express / Fastify)',
            'mobile' => 'Mobile / Native (PKCE)',
            default => 'Standard Web (Vanilla JS)',
        };
    }

    public function getInstallCommand(): string
    {
        return match ($this->framework) {
            'laravel' => 'composer require koamishin/auth-php',
            'nodejs' => 'npm install express axios dotenv',
            default => 'npm install @koamishin/auth-js',
        };
    }

    public function getSetupGuideCode(): string
    {
        $portalUrl = request()->hasHeader('Host')
            ? request()->getSchemeAndHttpHost()
            : rtrim((string) config('app.url'), '/');
        $publishableKey = $this->getPublishableKey();
        $callback = $this->redirect_uris[0] ?? 'http://localhost:3000/auth/callback';

        return match ($this->framework) {
            'laravel' => <<<'PHP'
// 1. Add ['driver' => 'koamishin'] under 'guards.koamishin' in config/auth.php.
// 2. In routes/web.php:
use Koamishin\Auth\Facades\Koamishin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/register', fn () => redirect()->away(Koamishin::getRegistrationUrl('/dashboard')))->name('register');
Route::get('/login', fn () => redirect()->away(Koamishin::getLoginUrl('/dashboard')))->name('login');

Route::get('/koamishin/callback', function (Request $request) {
    if ($request->query('error')) {
        abort(403, 'Sign-in was not completed.');
    }

    $input = $request->validate([
        'code' => ['required', 'string'],
        'state' => ['required', 'string'],
    ]);

    try {
        Koamishin::handleCallback($input['code'], $input['state']);
    } catch (InvalidArgumentException $exception) {
        abort(403, 'Invalid or expired sign-in request.');
    }

    return redirect()->to(Koamishin::consumeReturnTo('/dashboard'));
});

// 3. Protect routes with the guard. The user is a KoamishinUser, not an Eloquent model.
Route::get('/dashboard', fn (Request $request) => response()->json([
    'id' => $request->user()->id,
    'name' => $request->user()->name,
]))->middleware('auth:koamishin');
PHP,
            'nextjs' => <<<'TS'
// 1. Initialize client in lib/auth.ts:
import { createKoamishinClient } from '@koamishin/auth-js';

export const koamishin = createKoamishinClient({
  portalUrl: process.env.NEXT_PUBLIC_KOAMISHIN_PORTAL_URL!,
  publishableKey: process.env.NEXT_PUBLIC_KOAMISHIN_PUBLISHABLE_KEY!,
  redirectUri: process.env.NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI!,
});

// 2. Add Login Button in app/page.tsx:
'use client';
import { koamishin } from '@/lib/auth';

export default function LoginPage() {
  return (
    <button onClick={() => koamishin.redirectToSignIn()}>
      Sign In with Koamishin
    </button>
  );
}

// 3. Callback page in app/auth/callback/page.tsx:
'use client';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { koamishin } from '@/lib/auth';

export default function CallbackPage() {
  const router = useRouter();

  useEffect(() => {
    koamishin.handleRedirectCallback()
      .then(({ user }) => {
        console.log('Logged in:', user);
        router.push('/dashboard');
      })
      .catch((err) => console.error(err));
  }, [router]);

  return <div>Authenticating with Koamishin...</div>;
}
TS,
            'vue' => <<<'VUE'
<!-- In your Vue Component (e.g. src/views/Login.vue) -->
<script setup lang="ts">
import { createKoamishinClient } from '@koamishin/auth-js';

const koamishin = createKoamishinClient({
  portalUrl: import.meta.env.VITE_KOAMISHIN_PORTAL_URL,
  publishableKey: import.meta.env.VITE_KOAMISHIN_PUBLISHABLE_KEY,
  redirectUri: import.meta.env.VITE_KOAMISHIN_REDIRECT_URI,
});

async function login() {
  await koamishin.redirectToSignIn();
}
</script>

<template>
  <button @click="login">Sign In with Koamishin</button>
</template>
VUE,
            default => <<<JS
// Standard JavaScript / TypeScript with PKCE
import { createKoamishinClient } from '@koamishin/auth-js';

const koamishin = createKoamishinClient({
  portalUrl: '{$portalUrl}',
  publishableKey: '{$publishableKey}',
  redirectUri: '{$callback}',
});

// Trigger login:
await koamishin.redirectToSignIn();

// Handle callback on redirect URL:
const { user, tokens } = await koamishin.handleRedirectCallback();
console.log('User signed in:', user.name, user.email);
JS,
        };
    }
}
