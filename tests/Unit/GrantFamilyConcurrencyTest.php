<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;
use Tests\TestCase;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

pest()->extend(TestCase::class);

/**
 * These tests deliberately do not use RefreshDatabase: the workers must see
 * committed fixtures. Run only against the separately provisioned disposable
 * PostgreSQL database, migrated before starting Pest.
 */
beforeEach(function (): void {
    if (getenv('RUN_GRANT_FAMILY_PG_RACE') !== '1') {
        $this->markTestSkipped('Set RUN_GRANT_FAMILY_PG_RACE=1 for the disposable PostgreSQL race test.');
    }

    $connection = DB::connection();
    $passport = (new Token)->getConnection();
    $config = $connection->getConfig();
    expect(app()->configurationIsCached())->toBeFalse()
        ->and($connection->getDriverName())->toBe('pgsql')
        ->and($config['host'])->toBeIn(['127.0.0.1', 'localhost', '::1'])
        ->and($config['url'] ?? null)->toBeIn([null, ''])
        ->and($connection->selectOne('select current_database() as name')->name)->toBe('koamishin_auth_signout_test')
        ->and($passport->getName())->toBe($connection->getName());
});

function grantFamilyRaceFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Race test', 'slug' => 'race-'.bin2hex(random_bytes(8))]);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Race application']);
    $environment = app(ApplicationProvisioner::class)->connectEnvironment($application, 'development', [
        'framework' => 'react',
        'client_type' => 'public',
        'redirect_uris' => ['http://localhost:3000/callback'],
    ]);
    $environment->update(['settings' => array_merge($environment->settings ?? [], ['oauth_consent_enabled' => true])]);
    $user = User::factory()->create(['identity_namespace' => $environment->identityNamespace()]);
    $tenant->users()->attach($user);
    $application->users()->attach($user, ['environment' => 'development', 'role' => 'member']);
    $verifier = str_repeat('a', 64);
    $start = get('/oauth/authorize?'.http_build_query([
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/callback',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]))->assertOk();
    $flow = $start->viewData('flow');
    $login = post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'password'])->assertRedirect();
    $page = get((string) $login->headers->get('Location'))->assertOk();
    $approval = post('/oauth/authorize', [
        'flow' => $flow,
        'state' => 'state',
        'client_id' => $environment->oauth_client_id,
        'auth_token' => $page->original->getData()['page']['props']['authToken'],
    ])->assertRedirect();
    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $callback);
    $tokens = postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/callback',
        'code_verifier' => $verifier,
        'code' => $callback['code'],
    ])->assertOk();
    $credential = $tokens->json('revocation_token');
    expect($credential)->toBeString()->toStartWith('kg1.');

    return [
        'family_id' => explode('.', $credential)[1],
        'credential' => $credential,
        'client_id' => $environment->publishable_key,
        'refresh_token' => $tokens->json('refresh_token'),
    ];
}

test('family logout serializes with a concurrent refresh', function (string $first): void {
    $connection = DB::connection();
    $config = $connection->getConfig();
    $fixture = grantFamilyRaceFixture();
    $familyId = $fixture['family_id'];

    $jobs = [
        'refresh' => ['/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $fixture['client_id'],
            'refresh_token' => $fixture['refresh_token'],
        ]],
        'logout' => ['/api/oauth/revoke-family', ['revocation_token' => $fixture['credential']]],
    ];
    $second = $first === 'refresh' ? 'logout' : 'refresh';
    $workers = [];
    $connection->beginTransaction();

    try {
        // Hold the same row lock used by both operations, then establish their
        // actual PostgreSQL lock queue before releasing it.
        $family = $connection->table('oauth_grant_families')->where('id', $familyId)->lockForUpdate()->first();
        expect($family)->not->toBeNull();
        $parentPid = $connection->selectOne('select pg_backend_pid() as pid')->pid;
        $workers[$first] = grantFamilyRaceStart($jobs[$first], $config);
        grantFamilyRaceWaitForBlock($connection, $workers[$first]['pid'], (int) $parentPid);
        $workers[$second] = grantFamilyRaceStart($jobs[$second], $config);
        grantFamilyRaceWaitForBlock($connection, $workers[$second]['pid'], $workers[$first]['pid']);
        $connection->commit();

        $results = [];
        foreach ($workers as $name => $worker) {
            $results[$name] = grantFamilyRaceFinish($worker);
        }
        expect($results['logout']['status'])->toBe(204);
        if ($first === 'refresh') {
            // Response serialization can recheck the family after refresh commits,
            // in which case a concurrent logout may invalidate that response.
            expect($results['refresh']['status'])->toBeIn([200, 400]);
            if ($results['refresh']['status'] === 400) {
                expect($results['refresh']['body']['error'] ?? null)->toBe('invalid_grant');
            }
        } else {
            expect($results['refresh']['status'])->toBe(400)
                ->and($results['refresh']['body']['error'] ?? null)->toBe('invalid_grant');
        }

        expect($connection->table('oauth_grant_families')->where('id', $familyId)->value('revoked_at'))->not->toBeNull();
        $ids = $connection->table('oauth_grant_family_tokens')->where('family_id', $familyId)->pluck('access_token_id');
        expect($ids)->toHaveCount($first === 'refresh' ? 2 : 1)
            ->and($connection->table('oauth_access_tokens')->whereIn('id', $ids)->where('revoked', false)->count())->toBe(0)
            ->and($connection->table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->where('revoked', false)->count())->toBe(0);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            grantFamilyRaceStop($worker);
        }
    }
})->with(['refresh', 'logout']);

test('concurrent refreshes of an untracked legacy grant create only one live successor', function (): void {
    $connection = DB::connection();
    $config = $connection->getConfig();
    $fixture = grantFamilyRaceFixture();
    $family = $connection->table('oauth_grant_families')->where('id', $fixture['family_id'])->first();
    $originalId = $connection->table('oauth_grant_family_tokens')
        ->where('family_id', $fixture['family_id'])->sole()->access_token_id;
    $connection->table('oauth_grant_family_tokens')->where('access_token_id', $originalId)->delete();
    expect($connection->table('oauth_grant_family_tokens')->where('access_token_id', $originalId)->exists())->toBeFalse();

    $job = ['/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $fixture['client_id'],
        'refresh_token' => $fixture['refresh_token'],
    ]];
    $workers = [];
    $connection->beginTransaction();

    try {
        $original = $connection->table('oauth_access_tokens')->where('id', $originalId)->lockForUpdate()->first();
        expect($original)->not->toBeNull();
        $parentPid = (int) $connection->selectOne('select pg_backend_pid() as pid')->pid;
        $workers[] = grantFamilyRaceStart($job, $config);
        grantFamilyRaceWaitForBlock($connection, $workers[0]['pid'], $parentPid);
        $workers[] = grantFamilyRaceStart($job, $config);
        grantFamilyRaceWaitForBlock($connection, $workers[1]['pid'], $workers[0]['pid']);
        $connection->commit();

        $winner = grantFamilyRaceFinish($workers[0]);
        $loser = grantFamilyRaceFinish($workers[1]);
        expect($winner['status'])->toBe(200)
            ->and($loser['status'])->toBe(400)
            ->and($loser['body']['error'] ?? null)->toBe('invalid_grant');

        $newFamilyId = $connection->table('oauth_grant_family_tokens')->where('access_token_id', $originalId)->sole()->family_id;
        $ids = $connection->table('oauth_grant_family_tokens')->where('family_id', $newFamilyId)->pluck('access_token_id');
        $allClientIds = $connection->table('oauth_access_tokens')->where('client_id', $family->client_id)->pluck('id');
        expect($newFamilyId)->not->toBe($fixture['family_id'])
            ->and($ids)->toHaveCount(2)
            ->and($allClientIds)->toHaveCount(2)
            ->and($connection->table('oauth_access_tokens')->where('id', $originalId)->value('revoked'))->toBeTrue()
            ->and($connection->table('oauth_access_tokens')->whereIn('id', $allClientIds)->where('revoked', false)->count())->toBe(1)
            ->and($connection->table('oauth_refresh_tokens')->whereIn('access_token_id', $allClientIds)->where('revoked', false)->count())->toBe(1);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $worker) {
            grantFamilyRaceStop($worker);
        }
    }
});

/** @return array{process: resource, pipes: array, pid: int} */
function grantFamilyRaceStart(array $job, array $config): array
{
    $code = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('select pg_backend_pid() as pid')->pid;
fwrite(STDOUT, $pid."\n");
$job = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$request = Illuminate\Http\Request::create($job[0], 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
], json_encode($job[1], JSON_THROW_ON_ERROR));
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
fwrite(STDOUT, base64_encode(json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR))."\n");
PHP;
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => '',
        'DB_HOST' => $config['host'],
        'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => 'koamishin_auth_signout_test',
        'DB_USERNAME' => $config['username'],
        'DB_PASSWORD' => $config['password'] ?? '',
        'PASSPORT_CONNECTION' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ]);
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start race worker.');
    }
    stream_set_timeout($pipes[1], 15);
    $pid = trim((string) fgets($pipes[1]));
    if (! ctype_digit($pid)) {
        proc_terminate($process);
        throw new RuntimeException('Race worker failed to announce its PostgreSQL backend PID.');
    }
    fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR)."\n");
    fflush($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'pid' => (int) $pid];
}

function grantFamilyRaceWaitForBlock($connection, int $pid, int $blocker): void
{
    $deadline = microtime(true) + 15;
    do {
        $blocked = $connection->selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$blocker, $pid])->blocked;
        if ($blocked) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Backend {$pid} did not block behind {$blocker} within 15 seconds.");
}

function grantFamilyRaceFinish(array $worker): array
{
    stream_set_timeout($worker['pipes'][1], 20);
    $line = fgets($worker['pipes'][1]);
    if ($line === false) {
        throw new RuntimeException('Race worker did not return a response within 20 seconds.');
    }

    return json_decode(base64_decode(trim($line), true), true, 512, JSON_THROW_ON_ERROR);
}

function grantFamilyRaceStop(array $worker): void
{
    $status = proc_get_status($worker['process']);
    if ($status['running']) {
        proc_terminate($worker['process']);
    }
    foreach ($worker['pipes'] as $pipe) {
        fclose($pipe);
    }
    proc_close($worker['process']);
}
