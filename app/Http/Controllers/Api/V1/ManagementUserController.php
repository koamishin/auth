<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleEnums;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagementUserResource;
use App\Models\Role;
use App\Models\Session;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\MultiSession;
use App\Services\Management\ManagementContext;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class ManagementUserController extends Controller
{
    public function __construct(private readonly ManagementContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'email' => ['sometimes', 'string', 'email', 'max:255'],
            'search' => ['sometimes', 'string', 'max:100'],
            'banned' => ['sometimes', 'boolean'],
        ]);

        $environment = $this->context->environment($request);
        $perPage = (int) ($input['per_page'] ?? 15);
        $query = $this->context->users($environment);

        if (isset($input['email'])) {
            $query->where('users.email', strtolower(trim((string) $input['email'])));
        }

        if (isset($input['search']) && trim((string) $input['search']) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $input['search'])).'%';
            $query->where(function ($subQuery) use ($term): void {
                $subQuery->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('users.username', 'like', $term);
            });
        }

        if (array_key_exists('banned', $input)) {
            if ((bool) $input['banned']) {
                $query->whereNotNull('users.banned_at');
            } else {
                $query->whereNull('users.banned_at');
            }
        }

        $users = $query
            ->orderBy('users.id')
            ->paginate($perPage)
            ->appends($request->query());

        return ManagementUserResource::collection($users);
    }

    public function show(Request $request, string $userId): ManagementUserResource
    {
        $environment = $this->context->environment($request);

        return new ManagementUserResource($this->context->user($environment, $userId));
    }

    public function store(Request $request): Response
    {
        $environment = $this->context->environment($request);
        $namespace = $environment->identityNamespace();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where('identity_namespace', $namespace),
            ],
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('users', 'username')->where('identity_namespace', $namespace),
            ],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['sometimes', 'nullable', 'string', Password::defaults() ?? 'min:8'],
            'email_verified' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', 'in:owner,admin,editor,member,viewer'],
        ]);

        /** @var User $user */
        $user = DB::transaction(function () use ($environment, $namespace, $validated): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => strtolower(trim($validated['email'])),
                'username' => isset($validated['username']) ? strtolower(trim($validated['username'])) : null,
                'phone_number' => $validated['phone_number'] ?? null,
                'password' => isset($validated['password']) ? Hash::make($validated['password']) : null,
                'identity_namespace' => $namespace,
                'tenant_application_environment_id' => $environment->getKey(),
            ]);

            if ((bool) ($validated['email_verified'] ?? false)) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->assignRole(Role::query()->firstOrCreate([
                'name' => RoleEnums::USER->value,
                'guard_name' => 'web',
            ]));

            $environment->application->tenant->users()->syncWithoutDetaching([
                $user->getKey() => ['role' => 'member'],
            ]);

            DB::table('tenant_application_user')->insertOrIgnore([
                'tenant_application_id' => $environment->tenant_application_id,
                'user_id' => $user->getKey(),
                'environment' => $environment->environment,
                'role' => $validated['role'] ?? 'member',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $user;
        });

        WebhookDispatcher::dispatchForEnvironment($environment, 'user.created', $user);

        return (new ManagementUserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $userId): ManagementUserResource
    {
        $environment = $this->context->environment($request);
        $namespace = $environment->identityNamespace();
        $user = $this->context->user($environment, $userId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where('identity_namespace', $namespace)->ignore($user->getKey()),
            ],
            'username' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('users', 'username')->where('identity_namespace', $namespace)->ignore($user->getKey()),
            ],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'password' => ['sometimes', 'string', Password::defaults() ?? 'min:8'],
            'email_verified' => ['sometimes', 'boolean'],
        ]);

        $attributes = [];
        foreach (['name', 'first_name', 'last_name', 'phone_number'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }
        if (array_key_exists('email', $validated)) {
            $attributes['email'] = strtolower(trim($validated['email']));
        }
        if (array_key_exists('username', $validated)) {
            $attributes['username'] = $validated['username'] !== null ? strtolower(trim($validated['username'])) : null;
        }
        if (array_key_exists('password', $validated)) {
            $attributes['password'] = Hash::make($validated['password']);
        }
        if (array_key_exists('email_verified', $validated)) {
            $attributes['email_verified_at'] = $validated['email_verified'] ? ($user->email_verified_at ?? now()) : null;
        }

        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }

        return new ManagementUserResource($user->refresh());
    }

    public function destroy(Request $request, string $userId): Response
    {
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);
        $pending = WebhookDispatcher::pendingDeletionForEnvironment($environment, $user);

        DB::transaction(function () use ($environment, $user): void {
            $this->revokeUserTokensInEnvironment($environment, $user);

            DB::table('tenant_application_user')
                ->where('tenant_application_id', $environment->tenant_application_id)
                ->where('environment', $environment->environment)
                ->where('user_id', $user->getKey())
                ->delete();

            if ($user->tenant_application_environment_id === $environment->getKey()) {
                Session::query()->where('user_id', $user->getKey())->delete();
                app(MultiSession::class)->revokeAllFor($user->getKey());
                $user->delete();
            }
        });

        WebhookDispatcher::dispatchPending($pending);

        return response()->noContent();
    }

    public function ban(Request $request, string $userId): ManagementUserResource
    {
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($environment, $user, $validated): void {
            $user->forceFill([
                'banned_at' => now(),
                'ban_reason' => $validated['reason'] ?? null,
            ])->save();

            $this->revokeUserTokensInEnvironment($environment, $user);
            Session::query()->where('user_id', $user->getKey())->delete();
            app(MultiSession::class)->revokeAllFor($user->getKey());
        });

        return new ManagementUserResource($user->refresh());
    }

    public function unban(Request $request, string $userId): ManagementUserResource
    {
        $environment = $this->context->environment($request);
        $user = $this->context->user($environment, $userId);

        $user->forceFill([
            'banned_at' => null,
            'ban_reason' => null,
        ])->save();

        return new ManagementUserResource($user->refresh());
    }

    private function revokeUserTokensInEnvironment(TenantApplicationEnvironment $environment, User $user): void
    {
        $clientId = $environment->oauth_client_id;
        if ($clientId === null) {
            return;
        }

        $count = TenantApplicationEnvironment::query()->where('oauth_client_id', $clientId)->count();
        abort_unless($count === 1, 409, 'The application OAuth client is not uniquely bound.');

        $tokenIds = Token::query()->where('client_id', $clientId)->where('user_id', $user->getKey())->select('id');
        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        Token::query()->where('client_id', $clientId)->where('user_id', $user->getKey())->update(['revoked' => true]);
        DB::table('oauth_grant_families')
            ->where('client_id', $clientId)
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
