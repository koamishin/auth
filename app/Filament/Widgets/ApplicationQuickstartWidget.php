<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\TenantApplication;
use Filament\Widgets\Widget;

class ApplicationQuickstartWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.application-quickstart-widget';

    public ?TenantApplication $record = null;

    public string $framework = 'laravel';

    public int $activeStep = 1;

    public string $packageManager = 'npm';

    public ?string $clientSecret = null;

    public function mount(?TenantApplication $record = null, ?string $clientSecret = null): void
    {
        $this->record = $record;
        $this->framework = $record?->framework ?? 'laravel';
        $this->clientSecret = $clientSecret ?? session('regenerated_secret.secret');

        if ($this->framework === 'laravel') {
            $this->packageManager = 'composer';
        }
    }

    public function setFramework(string $framework): void
    {
        if (in_array($framework, ['laravel', 'nextjs', 'vue', 'react', 'nodejs', 'vanilla'], true)) {
            $this->framework = $framework;
            $this->packageManager = $framework === 'laravel' ? 'composer' : 'npm';
            $this->activeStep = 1;
        }
    }

    public function setStep(int $step): void
    {
        if ($step >= 1 && $step <= 4) {
            $this->activeStep = $step;
        }
    }

    public function setPackageManager(string $pm): void
    {
        if (in_array($pm, ['npm', 'pnpm', 'yarn', 'bun', 'composer'], true)) {
            $this->packageManager = $pm;
        }
    }

    public function getPortalUrl(): string
    {
        return request()->hasHeader('Host')
            ? request()->getSchemeAndHttpHost()
            : rtrim((string) config('app.url'), '/');
    }

    public function getClientId(): string
    {
        return $this->record?->oauth_client_id ?? 'your-client-id';
    }

    public function getCallbackUrl(): string
    {
        return $this->record?->redirect_uris[0] ?? 'http://localhost:3000/auth/callback';
    }

    public function getApplicationId(): string
    {
        return (string) ($this->record?->id ?? '1');
    }

    public function getEnvironment(): string
    {
        return $this->record?->environment ?? 'development';
    }

    public function getResolvedSecret(): string
    {
        if ($this->clientSecret) {
            return $this->clientSecret;
        }

        $sessionSecret = session('regenerated_secret.secret');
        if ($sessionSecret) {
            return $sessionSecret;
        }

        $newCreds = session('new_app_credentials.'.$this->getEnvironment().'.secret');
        if ($newCreds) {
            return $newCreds;
        }

        return 'YOUR_CLIENT_SECRET_HERE';
    }

    /**
     * @return array<string, array{name: string, badge: string, color: string, icon: string, description: string}>
     */
    public function getAvailableFrameworks(): array
    {
        return [
            'laravel' => [
                'name' => 'Laravel',
                'badge' => 'Lv',
                'color' => 'red',
                'icon' => 'heroicon-o-fire',
                'description' => 'PHP framework with official Koamishin request guard, Eloquent user projection, and PKCE auth.',
            ],
            'nextjs' => [
                'name' => 'Next.js',
                'badge' => '▲',
                'color' => 'gray',
                'icon' => 'heroicon-o-sparkles',
                'description' => 'React App Router & SSR with browser-scoped PKCE auth, token refresh, and user claims.',
            ],
            'vue' => [
                'name' => 'Vue 3 / Nuxt',
                'badge' => 'V',
                'color' => 'emerald',
                'icon' => 'heroicon-o-code-bracket',
                'description' => 'Vue 3 Composition API & Nuxt SPA with @koamishin/auth-js client helpers.',
            ],
            'react' => [
                'name' => 'React',
                'badge' => '⚛',
                'color' => 'cyan',
                'icon' => 'heroicon-o-cube-transparent',
                'description' => 'React Vite / CRA client-side single page app with token exchange and PKCE.',
            ],
            'nodejs' => [
                'name' => 'Node.js',
                'badge' => 'Node',
                'color' => 'amber',
                'icon' => 'heroicon-o-server-stack',
                'description' => 'Express, Fastify, or NestJS backend using confidential client secrets and token exchange.',
            ],
            'vanilla' => [
                'name' => 'Vanilla JS',
                'badge' => 'JS',
                'color' => 'primary',
                'icon' => 'heroicon-o-globe-alt',
                'description' => 'Zero-framework HTML5 and modern ES module client with Web Crypto PKCE.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    public function getSteps(): array
    {
        $portalUrl = $this->getPortalUrl();
        $clientId = $this->getClientId();
        $clientSecret = $this->getResolvedSecret();
        $callback = $this->getCallbackUrl();
        $appId = $this->getApplicationId();
        $env = $this->getEnvironment();

        return match ($this->framework) {
            'nextjs' => $this->getNextJsSteps($portalUrl, $clientId, $callback, $env),
            'vue' => $this->getVueSteps($portalUrl, $clientId, $callback, $env),
            'react' => $this->getReactSteps($portalUrl, $clientId, $callback, $env),
            'nodejs' => $this->getNodeJsSteps($portalUrl, $clientId, $clientSecret, $callback, $env),
            'vanilla' => $this->getVanillaJsSteps($portalUrl, $clientId, $callback),
            default => $this->getLaravelSteps($portalUrl, $clientId, $clientSecret, $callback, $appId, $env),
        };
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getLaravelSteps(string $portalUrl, string $clientId, string $clientSecret, string $callback, string $appId, string $env): array
    {
        return [
            [
                'number' => 1,
                'title' => 'Install Laravel SDK',
                'subtitle' => 'Install the official Koamishin authentication SDK and publish the configuration file.',
                'file' => 'terminal',
                'language' => 'bash',
                'code' => "composer require koamishin/auth-php\nphp artisan vendor:publish --tag=koamishin-config",
                'tip' => 'The SDK automatically registers the service provider and provides the official Koamishin authentication guard.',
            ],
            [
                'number' => 2,
                'title' => 'Configure Environment Variables',
                'subtitle' => 'Add the application credentials and callback endpoint to your .env file.',
                'file' => '.env',
                'language' => 'dotenv',
                'code' => <<<ENV
KOAMISHIN_PORTAL_URL={$portalUrl}
KOAMISHIN_APPLICATION_ID={$appId}
KOAMISHIN_ENVIRONMENT={$env}
KOAMISHIN_CLIENT_ID={$clientId}
KOAMISHIN_CLIENT_SECRET={$clientSecret}
KOAMISHIN_REDIRECT_URI={$callback}
ENV,
                'tip' => 'Client secrets must remain on your server. Never expose this secret to frontend code or client bundles.',
            ],
            [
                'number' => 3,
                'title' => 'Register Koamishin Guard',
                'subtitle' => 'Register the koamishin driver under guards in config/auth.php.',
                'file' => 'config/auth.php',
                'language' => 'php',
                'code' => <<<'PHP'
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'koamishin' => [
        'driver' => 'koamishin',
    ],
],
PHP,
                'tip' => 'The guard validates access tokens on every request and automatically refreshes tokens when needed.',
            ],
            [
                'number' => 4,
                'title' => 'Setup Routes & Protect Endpoints',
                'subtitle' => 'Add sign-in triggers, callback handling, and protected dashboard routes in routes/web.php.',
                'file' => 'routes/web.php',
                'language' => 'php',
                'code' => <<<'PHP'
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Koamishin\Auth\Facades\Koamishin;

// 1. Sign in & Sign up triggers
Route::get('/login', fn () => redirect()->away(Koamishin::getLoginUrl('/dashboard')))->name('login');
Route::get('/register', fn () => redirect()->away(Koamishin::getRegistrationUrl('/dashboard')))->name('register');

// 2. OAuth Callback
Route::get('/koamishin/callback', function (Request $request) {
    if ($request->query('error')) {
        abort(403, 'Sign-in cancelled or failed.');
    }

    $input = $request->validate([
        'code' => ['required', 'string'],
        'state' => ['required', 'string'],
    ]);

    try {
        Koamishin::handleCallback($input['code'], $input['state']);
    } catch (\InvalidArgumentException $e) {
        abort(403, 'Invalid or expired sign-in state.');
    }

    return redirect()->to(Koamishin::consumeReturnTo('/dashboard'));
})->name('koamishin.callback');

// 3. Protected Dashboard route
Route::get('/dashboard', function (Request $request) {
    $user = $request->user(); // KoamishinUser instance

    return response()->json([
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'metadata' => $user->publicMetadata ?? [],
    ]);
})->middleware('auth:koamishin');

// 4. Logout trigger
Route::post('/logout', function () {
    Koamishin::logout();

    return redirect('/');
})->name('logout');
PHP,
                'tip' => 'Koamishin::getRegistrationUrl() passes screen_hint=signup to present your application registration view.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getNextJsSteps(string $portalUrl, string $clientId, string $callback, string $env): array
    {
        $installCmd = match ($this->packageManager) {
            'pnpm' => 'pnpm add @koamishin/auth-js',
            'yarn' => 'yarn add @koamishin/auth-js',
            'bun' => 'bun add @koamishin/auth-js',
            default => 'npm install @koamishin/auth-js',
        };

        return [
            [
                'number' => 1,
                'title' => 'Install JavaScript SDK',
                'subtitle' => 'Install the lightweight browser-oriented TypeScript client from npm.',
                'file' => 'terminal',
                'language' => 'bash',
                'code' => $installCmd,
                'tip' => 'The package uses standard Web Crypto API and PKCE (Proof Key for Code Exchange) without server secrets.',
            ],
            [
                'number' => 2,
                'title' => 'Configure Environment Variables',
                'subtitle' => 'Create or update .env.local with public client credentials.',
                'file' => '.env.local',
                'language' => 'dotenv',
                'code' => <<<ENV
NEXT_PUBLIC_KOAMISHIN_PORTAL_URL={$portalUrl}
NEXT_PUBLIC_KOAMISHIN_ENVIRONMENT={$env}
NEXT_PUBLIC_KOAMISHIN_CLIENT_ID={$clientId}
NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI={$callback}
ENV,
                'tip' => 'Public variables in Next.js require the NEXT_PUBLIC_ prefix to be accessible in browser client components.',
            ],
            [
                'number' => 3,
                'title' => 'Initialize Client Instance',
                'subtitle' => 'Create a singleton auth client in lib/auth.ts to reuse across your application.',
                'file' => 'lib/auth.ts',
                'language' => 'typescript',
                'code' => <<<'TS'
import { createKoamishinClient } from '@koamishin/auth-js';

export const koamishin = createKoamishinClient({
  portalUrl: process.env.NEXT_PUBLIC_KOAMISHIN_PORTAL_URL!,
  clientId: process.env.NEXT_PUBLIC_KOAMISHIN_CLIENT_ID!,
  redirectUri: process.env.NEXT_PUBLIC_KOAMISHIN_REDIRECT_URI!,
  scopes: ['openid', 'profile', 'email'],
});
TS,
                'tip' => 'Scopes control the claims returned by userinfo. Default scopes include openid, profile, and email.',
            ],
            [
                'number' => 4,
                'title' => 'Create Callback Route & Buttons',
                'subtitle' => 'Handle code exchange on redirect and provide sign-in / sign-up buttons.',
                'file' => 'app/auth/callback/page.tsx',
                'language' => 'typescript',
                'code' => <<<'TS'
'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { koamishin } from '@/lib/auth';

export default function CallbackPage() {
  const router = useRouter();

  useEffect(() => {
    koamishin.handleRedirectCallback()
      .then(({ user }) => {
        console.log('Signed in as:', user.name);
        router.push('/dashboard');
      })
      .catch((err) => {
        console.error('Sign-in failed:', err);
        router.push('/login?error=auth_failed');
      });
  }, [router]);

  return (
    <div className="flex h-screen items-center justify-center font-sans">
      <p className="text-sm font-medium text-gray-600 animate-pulse">
        Completing secure sign-in with Koamishin...
      </p>
    </div>
  );
}
TS,
                'tip' => 'Use koamishin.redirectToSignIn() and koamishin.redirectToSignUp() on your buttons to start the flow.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getVueSteps(string $portalUrl, string $clientId, string $callback, string $env): array
    {
        $installCmd = match ($this->packageManager) {
            'pnpm' => 'pnpm add @koamishin/auth-js',
            'yarn' => 'yarn add @koamishin/auth-js',
            'bun' => 'bun add @koamishin/auth-js',
            default => 'npm install @koamishin/auth-js',
        };

        return [
            [
                'number' => 1,
                'title' => 'Install SDK Package',
                'subtitle' => 'Add the official auth package to your Vue or Nuxt project.',
                'file' => 'terminal',
                'language' => 'bash',
                'code' => $installCmd,
                'tip' => 'Works seamlessly with Vue 3 Composition API, Nuxt 3, and Vite.',
            ],
            [
                'number' => 2,
                'title' => 'Configure Environment Variables',
                'subtitle' => 'Set Vite environment variables in your .env file.',
                'file' => '.env',
                'language' => 'dotenv',
                'code' => <<<ENV
VITE_KOAMISHIN_PORTAL_URL={$portalUrl}
VITE_KOAMISHIN_ENVIRONMENT={$env}
VITE_KOAMISHIN_CLIENT_ID={$clientId}
VITE_KOAMISHIN_REDIRECT_URI={$callback}
ENV,
                'tip' => 'Vite exposes environment variables prefixed with VITE_ via import.meta.env.',
            ],
            [
                'number' => 3,
                'title' => 'Setup Client Module',
                'subtitle' => 'Initialize the auth client in src/auth.ts for your Vue components.',
                'file' => 'src/auth.ts',
                'language' => 'typescript',
                'code' => <<<'TS'
import { createKoamishinClient } from '@koamishin/auth-js';

export const auth = createKoamishinClient({
  portalUrl: import.meta.env.VITE_KOAMISHIN_PORTAL_URL,
  clientId: import.meta.env.VITE_KOAMISHIN_CLIENT_ID,
  redirectUri: import.meta.env.VITE_KOAMISHIN_REDIRECT_URI,
});
TS,
                'tip' => 'The client securely stores tokens in browser sessionStorage scoped by client ID.',
            ],
            [
                'number' => 4,
                'title' => 'Create Callback View & Login Button',
                'subtitle' => 'Exchange the returned authorization code and redirect to your app dashboard.',
                'file' => 'src/views/Callback.vue',
                'language' => 'html',
                'code' => <<<'VUE'
<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { auth } from '@/auth';

const router = useRouter();

onMounted(async () => {
  try {
    const { user } = await auth.handleRedirectCallback();
    console.log('Welcome:', user.name);
    router.push('/dashboard');
  } catch (error) {
    console.error('Login failed', error);
  }
});
</script>

<template>
  <div class="callback-container">
    <p>Authenticating with Koamishin Portal...</p>
  </div>
</template>
VUE,
                'tip' => 'In your Login.vue, simply call auth.redirectToSignIn() or auth.redirectToSignUp() on click.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getReactSteps(string $portalUrl, string $clientId, string $callback, string $env): array
    {
        $installCmd = match ($this->packageManager) {
            'pnpm' => 'pnpm add @koamishin/auth-js',
            'yarn' => 'yarn add @koamishin/auth-js',
            'bun' => 'bun add @koamishin/auth-js',
            default => 'npm install @koamishin/auth-js',
        };

        return [
            [
                'number' => 1,
                'title' => 'Install Package',
                'subtitle' => 'Install @koamishin/auth-js into your React application.',
                'file' => 'terminal',
                'language' => 'bash',
                'code' => $installCmd,
                'tip' => 'Supports React 18, React 19, Vite, and Create React App.',
            ],
            [
                'number' => 2,
                'title' => 'Set Environment Variables',
                'subtitle' => 'Add your client configuration to .env.',
                'file' => '.env',
                'language' => 'dotenv',
                'code' => <<<ENV
VITE_KOAMISHIN_PORTAL_URL={$portalUrl}
VITE_KOAMISHIN_ENVIRONMENT={$env}
VITE_KOAMISHIN_CLIENT_ID={$clientId}
VITE_KOAMISHIN_REDIRECT_URI={$callback}
ENV,
                'tip' => 'Restart your development server after editing environment variables.',
            ],
            [
                'number' => 3,
                'title' => 'Initialize Client Instance',
                'subtitle' => 'Create a central auth client in src/lib/auth.ts.',
                'file' => 'src/lib/auth.ts',
                'language' => 'typescript',
                'code' => <<<'TS'
import { createKoamishinClient } from '@koamishin/auth-js';

export const koamishin = createKoamishinClient({
  portalUrl: import.meta.env.VITE_KOAMISHIN_PORTAL_URL,
  clientId: import.meta.env.VITE_KOAMISHIN_CLIENT_ID,
  redirectUri: import.meta.env.VITE_KOAMISHIN_REDIRECT_URI,
});
TS,
                'tip' => 'Export the client to easily trigger sign-in and sign-out from any component.',
            ],
            [
                'number' => 4,
                'title' => 'Handle Callback & State',
                'subtitle' => 'Exchange the authorization code on your callback route.',
                'file' => 'src/pages/Callback.tsx',
                'language' => 'typescript',
                'code' => <<<'TS'
import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { koamishin } from '../lib/auth';

export function Callback() {
  const navigate = useNavigate();

  useEffect(() => {
    koamishin.handleRedirectCallback()
      .then(({ user }) => {
        console.log('Logged in:', user);
        navigate('/dashboard');
      })
      .catch((err) => {
        console.error('Authentication error:', err);
        navigate('/');
      });
  }, [navigate]);

  return <div>Connecting to Koamishin...</div>;
}
TS,
                'tip' => 'Call koamishin.getUser() anytime to check active session and user claims.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getNodeJsSteps(string $portalUrl, string $clientId, string $clientSecret, string $callback, string $env): array
    {
        return [
            [
                'number' => 1,
                'title' => 'Install Dependencies',
                'subtitle' => 'Install Express, Axios, and Dotenv for Node.js backend authentication.',
                'file' => 'terminal',
                'language' => 'bash',
                'code' => 'npm install express axios dotenv',
                'tip' => 'Node.js acts as a confidential OAuth2 client storing your client secret securely on the server.',
            ],
            [
                'number' => 2,
                'title' => 'Environment Variables',
                'subtitle' => 'Save confidential credentials in your server .env file.',
                'file' => '.env',
                'language' => 'dotenv',
                'code' => <<<ENV
KOAMISHIN_PORTAL_URL={$portalUrl}
KOAMISHIN_ENVIRONMENT={$env}
KOAMISHIN_CLIENT_ID={$clientId}
KOAMISHIN_CLIENT_SECRET={$clientSecret}
KOAMISHIN_REDIRECT_URI={$callback}
ENV,
                'tip' => 'Never check .env into version control. Keep client secrets in production secret managers.',
            ],
            [
                'number' => 3,
                'title' => 'Express Server Auth Endpoints',
                'subtitle' => 'Build the authorization redirect and token exchange endpoint in server.js.',
                'file' => 'server.js',
                'language' => 'javascript',
                'code' => <<<'JS'
import express from 'express';
import axios from 'axios';
import 'dotenv/config';

const app = express();

// 1. Redirect to Koamishin Portal authorization
app.get('/login', (req, res) => {
  const params = new URLSearchParams({
    client_id: process.env.KOAMISHIN_CLIENT_ID,
    redirect_uri: process.env.KOAMISHIN_REDIRECT_URI,
    response_type: 'code',
    scope: 'openid profile email',
    state: Math.random().toString(36).substring(7),
  });

  res.redirect(`${process.env.KOAMISHIN_PORTAL_URL}/oauth/authorize?${params}`);
});

// 2. OAuth Callback & Code Exchange
app.get('/callback', async (req, res) => {
  const { code } = req.query;

  if (!code) {
    return res.status(400).send('Missing authorization code.');
  }

  try {
    const tokenResponse = await axios.post(`${process.env.KOAMISHIN_PORTAL_URL}/oauth/token`, {
      grant_type: 'authorization_code',
      client_id: process.env.KOAMISHIN_CLIENT_ID,
      client_secret: process.env.KOAMISHIN_CLIENT_SECRET,
      redirect_uri: process.env.KOAMISHIN_REDIRECT_URI,
      code,
    });

    const { access_token } = tokenResponse.data;

    // Fetch user claims
    const userResponse = await axios.get(`${process.env.KOAMISHIN_PORTAL_URL}/api/oauth/userinfo`, {
      headers: { Authorization: `Bearer ${access_token}` },
    });

    res.json({
      status: 'authenticated',
      user: userResponse.data,
      token: access_token,
    });
  } catch (err) {
    res.status(500).json({ error: err.response?.data || err.message });
  }
});

app.listen(3000, () => console.log('Server running on http://localhost:3000'));
JS,
                'tip' => 'Store access tokens in httpOnly secure cookies or verified server sessions.',
            ],
            [
                'number' => 4,
                'title' => 'Protect API Routes with Bearer Tokens',
                'subtitle' => 'Verify Bearer access tokens against the Portal userinfo endpoint for microservices.',
                'file' => 'middleware/auth.js',
                'language' => 'javascript',
                'code' => <<<'JS'
import axios from 'axios';

export async function requireAuth(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'Unauthorized: Missing token' });
  }

  try {
    const response = await axios.get(`${process.env.KOAMISHIN_PORTAL_URL}/api/oauth/userinfo`, {
      headers: { Authorization: authHeader },
    });

    req.user = response.data;
    next();
  } catch (err) {
    return res.status(401).json({ error: 'Unauthorized: Invalid token' });
  }
}
JS,
                'tip' => 'Userinfo claims are strictly scoped to this application environment.',
            ],
        ];
    }

    /**
     * @return array<int, array{number: int, title: string, subtitle: string, file: string, language: string, code: string, tip: string}>
     */
    private function getVanillaJsSteps(string $portalUrl, string $clientId, string $callback): array
    {
        return [
            [
                'number' => 1,
                'title' => 'Import Client from CDN',
                'subtitle' => 'Import the client module directly using ESM in your HTML header.',
                'file' => 'index.html',
                'language' => 'html',
                'code' => <<<HTML
<script type="module">
  import { createKoamishinClient } from 'https://esm.sh/@koamishin/auth-js';

  window.koamishin = createKoamishinClient({
    portalUrl: '{$portalUrl}',
    clientId: '{$clientId}',
    redirectUri: '{$callback}',
  });
</script>
HTML,
                'tip' => 'Zero bundlers required. Modern browsers natively support ES modules.',
            ],
            [
                'number' => 2,
                'title' => 'Trigger Sign In',
                'subtitle' => 'Add simple button elements to start the hosted authentication flow.',
                'file' => 'index.html',
                'language' => 'html',
                'code' => <<<'HTML'
<button onclick="window.koamishin.redirectToSignIn()">
  Sign In
</button>

<button onclick="window.koamishin.redirectToSignUp()">
  Create Account
</button>
HTML,
                'tip' => 'redirectToSignIn() securely generates code verifiers and stores them in sessionStorage.',
            ],
            [
                'number' => 3,
                'title' => 'Handle Callback',
                'subtitle' => 'Complete the token exchange on your callback page.',
                'file' => 'callback.html',
                'language' => 'html',
                'code' => <<<HTML
<!DOCTYPE html>
<html>
<head>
  <title>Authenticating...</title>
  <script type="module">
    import { createKoamishinClient } from 'https://esm.sh/@koamishin/auth-js';

    const client = createKoamishinClient({
      portalUrl: '{$portalUrl}',
      clientId: '{$clientId}',
      redirectUri: '{$callback}',
    });

    try {
      const { user } = await client.handleRedirectCallback();
      console.log('Welcome', user.name);
      window.location.href = '/dashboard.html';
    } catch (err) {
      document.body.innerText = 'Authentication error: ' + err.message;
    }
  </script>
</head>
<body>
  <p>Authenticating...</p>
</body>
</html>
HTML,
                'tip' => 'After handleRedirectCallback() finishes, tokens are ready and user claims can be inspected.',
            ],
            [
                'number' => 4,
                'title' => 'Read User Profile & Sign Out',
                'subtitle' => 'Inspect the signed in user in your dashboard.',
                'file' => 'dashboard.html',
                'language' => 'html',
                'code' => <<<HTML
<script type="module">
  import { createKoamishinClient } from 'https://esm.sh/@koamishin/auth-js';

  const client = createKoamishinClient({
    portalUrl: '{$portalUrl}',
    clientId: '{$clientId}',
    redirectUri: '{$callback}',
  });

  const user = await client.getUser();
  if (!user) {
    window.location.href = '/index.html';
  } else {
    document.getElementById('welcome').innerText = 'Hello, ' + user.name;
  }

  window.logout = () => {
    client.signOut();
    window.location.href = '/index.html';
  };
</script>

<h1 id="welcome">Loading profile...</h1>
<button onclick="window.logout()">Sign Out</button>
HTML,
                'tip' => 'client.getUser() automatically verifies token expiration and refreshes tokens if expired.',
            ],
        ];
    }
}
