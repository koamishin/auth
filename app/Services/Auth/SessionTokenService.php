<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SessionTask;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAccess;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use App\Support\ApplicationContext;
use App\Support\FactorVerifications;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

/**
 * Mints the short-lived session tokens backends verify offline.
 *
 * A session token is a JWT signed with the same key the JWKS endpoint
 * publishes, so a backend holding only the discovery document can verify one
 * without asking the portal anything. It is deliberately short-lived — like
 * Clerk's session token, its job is to answer "who is this, and in what
 * session, right now", not to store access.
 *
 * Claims follow Clerk's session token version 2: `sub`, `iss`, `iat`, `nbf`,
 * `exp`, `jti`, `sid`, `azp`, `v`, `fva`, `sts`, the organization claim `o`,
 * the impersonation actor claim `act`, and `tasks` for pending verifications.
 */
class SessionTokenService
{
    /** How long a session token stays valid, in seconds. */
    public const LIFETIME_SECONDS = 60;

    public function __construct(
        private readonly ApplicationAuthentication $authentication,
        private readonly ApplicationAccess $access,
    ) {}

    /**
     * Mint a token for the browser session's signed-in user.
     *
     * @return array{token: string, token_type: string, expires_in: int, expires_at: int}
     */
    public function mintForBrowserSession(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_if($user->isBanned(), 403);

        $environment = ApplicationContext::environment($request);

        if ($environment instanceof TenantApplicationEnvironment) {
            abort_unless($this->access->allows($environment, $user), 403, 'You do not have access to this application.');
        }

        $sid = $request->session()->getId();

        return $this->mint(
            request: $request,
            user: $user,
            environment: $environment,
            tasks: $this->pendingTasks($request, $user, $environment),
            sid: $sid,
            azp: $request->headers->get('Origin') ?? '',
            factorAges: FactorVerifications::ages($request),
            actor: $this->actor($sid),
        );
    }

    /**
     * Mint a token from an OAuth access token, for SDK-driven backends.
     *
     * The access token is what a client application already holds after the
     * authorization-code exchange; minting turns it into a short-lived JWT its
     * own backends can verify offline. Client-credential tokens have no user
     * behind them and are refused.
     *
     * @return array{token: string, token_type: string, expires_in: int, expires_at: int}
     */
    public function mintForAccessToken(Request $request, AccessToken $accessToken): array
    {
        // The guard resolved the token's user; the wrapper itself carries the
        // league attributes rather than the token's relations.
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'Only user tokens can mint session tokens.');
        abort_if($user->isBanned(), 403);

        $sid = (string) ($accessToken->oauth_access_token_id ?? '');
        abort_unless($sid !== '', 403, 'Only user tokens can mint session tokens.');

        $environment = $this->access->environmentForClient((string) ($accessToken->oauth_client_id ?? ''));
        abort_unless($environment instanceof TenantApplicationEnvironment, 403);
        abort_unless($this->access->allows($environment, $user), 403, 'You do not have access to this application.');

        return $this->mint(
            request: $request,
            user: $user,
            environment: $environment,
            // The browser session that authorized this token is gone; the
            // application's gates already passed at authorization time.
            tasks: $this->pendingTasks($request, $user, $environment, sessionKnown: false),
            sid: $sid,
            azp: $request->headers->get('Origin') ?? '',
            // Factor ages live in the session; an API-held token has none.
            factorAges: [-1, -1],
            actor: null,
        );
    }

    /**
     * What the session still owes before it counts as complete.
     *
     * The requirements mirror the application gate exactly, so a token can
     * never report a finished session the gate would refuse.
     *
     * @return list<SessionTask>
     */
    public function pendingTasks(Request $request, User $user, ?TenantApplicationEnvironment $environment, bool $sessionKnown = true): array
    {
        $tasks = [];

        $verificationRequired = $environment instanceof TenantApplicationEnvironment
            ? $environment->requiresEmailVerification()
            : app(ApplicationFeaturesSettings::class)->email_verification_required;

        if ($verificationRequired && ! $user->hasVerifiedEmail()) {
            $tasks[] = SessionTask::EmailVerification;
        }

        if ($sessionKnown
            && $environment instanceof TenantApplicationEnvironment
            && $environment->requiresMultiFactorAuthentication()
            && ! $this->authentication->multiFactorSatisfied($request, $environment, $user)) {
            $tasks[] = SessionTask::MultiFactor;
        }

        return $tasks;
    }

    /**
     * @param  list<SessionTask>  $tasks
     * @param  array{0: int, 1: int}  $factorAges
     * @return array{token: string, token_type: string, expires_in: int, expires_at: int}
     */
    private function mint(
        Request $request,
        User $user,
        ?TenantApplicationEnvironment $environment,
        array $tasks,
        string $sid,
        string $azp,
        array $factorAges,
        ?array $actor,
    ): array {
        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::LIFETIME_SECONDS);

        // An impersonated session's factor verifications belong to the
        // impersonator, never to the subject, so they must not vouch for it.
        $factorAges = is_array($actor) ? [-1, -1] : $factorAges;

        $claims = [
            'sid' => $sid,
            'v' => '2',
            'fva' => $factorAges,
            'sts' => $tasks === [] ? 'active' : 'pending',
            'tasks' => array_map(static fn (SessionTask $task): string => $task->value, $tasks),
        ];

        if ($azp !== '') {
            $claims['azp'] = $azp;
        }

        if ($environment instanceof TenantApplicationEnvironment) {
            $claims['o'] = $this->organizationClaim($environment, $user);
            // The application context the token was minted in, so backends can
            // refuse tokens belonging to another application — the same
            // binding the userinfo endpoint enforces.
            $claims['application_id'] = (string) $environment->application->getKey();
            $claims['environment'] = $environment->environment;
        }

        if (is_array($actor)) {
            $claims['act'] = $actor;
        }

        return [
            'token' => $this->sign($user, $claims, $now->timestamp, $expiresAt->timestamp),
            'token_type' => 'Bearer',
            'expires_in' => self::LIFETIME_SECONDS,
            'expires_at' => $expiresAt->timestamp,
        ];
    }

    /**
     * The organization claim: the application's organization and the user's
     * role in it, in Clerk's compact shape. Only sessions with an application
     * context carry one.
     *
     * @return array{id: string, slg: string, rol: string}
     */
    private function organizationClaim(TenantApplicationEnvironment $environment, User $user): array
    {
        $application = $environment->application;
        $role = DB::table('tenant_application_user')
            ->where('tenant_application_id', $application->getKey())
            ->where('environment', $environment->environment)
            ->where('user_id', $user->getKey())
            ->value('role');

        return [
            'id' => (string) $application->tenant_id,
            'slg' => (string) $application->tenant->slug,
            'rol' => is_string($role) && $role !== '' ? $role : 'member',
        ];
    }

    /**
     * The actor claim, present while an administrator is impersonating: the
     * impersonator's id, so backends can tell a borrowed session apart.
     *
     * @return array{iss: string, sid: string, sub: string}|null
     */
    private function actor(string $sid): ?array
    {
        $manager = app('impersonate');

        if (! $manager->isImpersonating()) {
            return null;
        }

        return [
            'iss' => rtrim((string) config('app.url'), '/'),
            'sid' => $sid,
            'sub' => (string) $manager->getImpersonatorId(),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(User $user, array $claims, int $issuedAt, int $expiresAt): string
    {
        $privateKeyPath = storage_path('oauth-private.key');
        abort_unless(file_exists($privateKeyPath), 503, 'Session tokens are not configured.');

        $builder = (new Builder(new JoseEncoder, ChainedFormatter::withUnixTimestampDates()))
            ->issuedBy(rtrim((string) config('app.url'), '/'))
            ->relatedTo((string) $user->getKey())
            ->identifiedBy((string) Str::ulid())
            ->issuedAt(new DateTimeImmutable('@'.$issuedAt))
            ->canOnlyBeUsedAfter(new DateTimeImmutable('@'.$issuedAt))
            ->expiresAt(new DateTimeImmutable('@'.$expiresAt))
            // The same key identifier the JWKS endpoint publishes.
            ->withHeader('kid', hash('sha256', (string) file_get_contents(storage_path('oauth-public.key'))));

        foreach ($claims as $name => $value) {
            // The builder is immutable; every call returns a new one.
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken(new Sha256, InMemory::file($privateKeyPath))->toString();
    }
}
