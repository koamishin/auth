<?php

namespace App\Concerns;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null, ?string $identityNamespace = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId, $identityNamespace),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null, ?string $identityNamespace = null): array
    {
        $namespace = $identityNamespace ?? $this->resolveIdentityNamespace($userId);

        $uniqueRule = Rule::unique(User::class)->where('identity_namespace', $namespace);

        if ($userId !== null) {
            $uniqueRule->ignore($userId);
        }

        return [
            'required',
            'string',
            'email',
            'max:255',
            $uniqueRule,
        ];
    }

    /**
     * Get the validation rules used to validate usernames.
     *
     * Latin letters, digits, hyphens and underscores only, between 4 and 64
     * characters, and unique within the identity namespace. The disallowed set
     * (`^$!.#+~`) matches the characters Clerk refuses.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function usernameRules(?int $userId = null, ?string $identityNamespace = null): array
    {
        $namespace = $identityNamespace ?? $this->resolveIdentityNamespace($userId);

        $uniqueRule = Rule::unique(User::class)->where('identity_namespace', $namespace);

        if ($userId !== null) {
            $uniqueRule->ignore($userId);
        }

        return [
            'nullable',
            'string',
            'min:4',
            'max:64',
            'regex:/^[A-Za-z0-9_-]+$/',
            $uniqueRule,
        ];
    }

    protected function resolveIdentityNamespace(?int $userId = null): string
    {
        if ($userId !== null) {
            $existingUser = User::query()->find($userId);
            if ($existingUser && is_string($existingUser->identity_namespace)) {
                return $existingUser->identity_namespace;
            }
        }

        $environment = request()?->attributes?->get('koamishin.application_environment');
        if ($environment instanceof TenantApplicationEnvironment) {
            return $environment->identityNamespace();
        }

        return User::PORTAL_IDENTITY_NAMESPACE;
    }
}
