<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', PasswordPolicy::forCurrentRequest(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }

    /**
     * The password rule for the application environment in context.
     */
    protected function passwordRule(): Password
    {
        return PasswordPolicy::forCurrentRequest();
    }

    /**
     * Refuse to set a new password when the application has disabled password
     * authentication. Existing passwords keep working; this only stops new ones
     * from being created.
     *
     * @throws ValidationException
     */
    protected function assertPasswordCreationAllowed(string $message = 'This application does not allow signing in with a password. Use another sign-in method.'): void
    {
        if (PasswordPolicy::allowsPasswordCreation()) {
            return;
        }

        throw ValidationException::withMessages(['password' => __($message)]);
    }
}
