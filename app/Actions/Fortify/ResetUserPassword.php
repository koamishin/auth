<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * The reset link carries no application context, so the environment is
     * resolved from the account being reset. That keeps an application's password
     * rules — and a disabled password sign-in — in force on this path too.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        $environment = $user->tenantApplicationEnvironment;
        $environment = $environment instanceof TenantApplicationEnvironment ? $environment : null;

        $this->assertPasswordCreationAllowed(
            'This application does not allow passwords, so they cannot be reset. Use another sign-in method.',
            $environment,
        );

        Validator::make($input, [
            'password' => $this->passwordRules($environment),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
    }
}
