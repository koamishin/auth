<?php

namespace App\Concerns;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Support\ApplicationContext;
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
            ...$this->namePartRules(),
            ...$this->usernameFieldRules($userId, $identityNamespace),
            ...$this->phoneFieldRules(),
        ];
    }

    /**
     * Rules for the phone number, only when the application in context
     * collects one — required of every user or optional. Numbers are E.164;
     * SMS verification is not built yet, so nothing here proves ownership.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function phoneFieldRules(): array
    {
        $environment = ApplicationContext::environment();

        if (! $environment instanceof TenantApplicationEnvironment || ! $environment->collectsPhone()) {
            return [];
        }

        return [
            'phone_number' => [
                $environment->requiresPhone() ? 'required' : 'nullable',
                'string',
                'max:32',
                'regex:/^\+[1-9]\d{6,14}$/',
            ],
        ];
    }

    /**
     * Rules for the username, only when the application in context collects
     * one — required of every user or optional, as the application demands.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function usernameFieldRules(?int $userId = null, ?string $identityNamespace = null): array
    {
        $environment = ApplicationContext::environment();

        if (! $environment instanceof TenantApplicationEnvironment || ! $environment->collectsUsername()) {
            return [];
        }

        return [
            'username' => [
                $environment->requiresUsername() ? 'required' : 'nullable',
                ...$this->usernameFormatRules($userId, $identityNamespace),
            ],
        ];
    }

    /**
     * Rules for the optional first and last name, only when the application in
     * context collects them.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function namePartRules(): array
    {
        $environment = ApplicationContext::environment();

        if (! $environment instanceof TenantApplicationEnvironment || ! $environment->collectsNameParts()) {
            return [];
        }

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Combine the name parts into the display name, leaving it alone when the
     * application does not collect them.
     */
    public function displayNameFrom(array $input, ?string $fallback = null): ?string
    {
        $environment = ApplicationContext::environment();

        // Only applications that collect name parts derive the display name from
        // them; anywhere else a stray value must not override the given name.
        if (! $environment instanceof TenantApplicationEnvironment || ! $environment->collectsNameParts()) {
            return $fallback;
        }

        $parts = array_filter([
            is_string($input['first_name'] ?? null) ? trim($input['first_name']) : null,
            is_string($input['last_name'] ?? null) ? trim($input['last_name']) : null,
        ], filled(...));

        if ($parts === []) {
            return $fallback;
        }

        return implode(' ', $parts);
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        // Applications that collect a first and last name derive the display name
        // from them, so a single name field is not required.
        $environment = ApplicationContext::environment();

        if ($environment instanceof TenantApplicationEnvironment && $environment->collectsNameParts()) {
            return ['nullable', 'string', 'max:255'];
        }

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
        return [
            'nullable',
            ...$this->usernameFormatRules($userId, $identityNamespace),
        ];
    }

    /**
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function usernameFormatRules(?int $userId = null, ?string $identityNamespace = null): array
    {
        $namespace = $identityNamespace ?? $this->resolveIdentityNamespace($userId);

        $uniqueRule = Rule::unique(User::class)->where('identity_namespace', $namespace);

        if ($userId !== null) {
            $uniqueRule->ignore($userId);
        }

        return [
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
