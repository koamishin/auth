<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RoleEnums;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\ApplicationAccessPolicy;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $this->assertPasswordCreationAllowed('This application does not allow signing up with a password. Continue with a social provider instead.');

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $environment = request()?->attributes?->get('koamishin.application_environment');

            if ($environment instanceof TenantApplicationEnvironment && ! $environment->allowsEmailSignup()) {
                throw ValidationException::withMessages([
                    'email' => 'This application does not allow signing up with an email address.',
                ]);
            }

            // Allowlists, blocklists, and identifier protections apply to every
            // sign-up, including ones that arrive without a password.
            if ($environment instanceof TenantApplicationEnvironment) {
                $reason = app(ApplicationAccessPolicy::class)->reasonFor($environment, $input['email'] ?? null);

                if ($reason !== null) {
                    throw ValidationException::withMessages(['email' => $reason]);
                }
            }
            $identityNamespace = $environment instanceof TenantApplicationEnvironment
                ? $environment->identityNamespace()
                : User::PORTAL_IDENTITY_NAMESPACE;
            $environmentId = $environment instanceof TenantApplicationEnvironment
                ? $environment->getKey()
                : null;

            $user = User::query()->create([
                'name' => $this->displayNameFrom($input, $input['name'] ?? null) ?? '',
                'first_name' => $input['first_name'] ?? null,
                'last_name' => $input['last_name'] ?? null,
                'username' => $input['username'] ?? null,
                'phone_number' => $input['phone_number'] ?? null,
                'email' => $input['email'],
                'password' => $input['password'],
                'identity_namespace' => $identityNamespace,
                'tenant_application_environment_id' => $environmentId,
            ]);

            $user->assignRole($this->defaultRole());
            app(ApplicationAuthentication::class)->enrollNewUser(request(), $user);

            return $user;
        });
    }

    protected function defaultRole(): Role
    {
        /** @var Role $role */
        $role = Role::query()->firstOrCreate([
            'name' => app(ApplicationFeaturesSettings::class)->default_user_role ?: RoleEnums::USER->value,
            'guard_name' => 'web',
        ]);

        return $role;
    }
}
