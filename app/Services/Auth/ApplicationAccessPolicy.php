<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\TenantApplicationEnvironment;

/**
 * Decides whether an identifier is allowed to sign up for, or sign in to, an
 * application.
 *
 * Block rules always win, an allowlist narrows access to the addresses it
 * lists, and optional protections reject subaddressed and disposable domains.
 */
class ApplicationAccessPolicy
{
    /**
     * Why the address is refused, or null when it is allowed.
     */
    public function reasonFor(TenantApplicationEnvironment $environment, ?string $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        $email = mb_strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return null;
        }

        [$localPart, $domain] = explode('@', $email, 2);

        if ($this->isBlocked($environment, $email, $domain)) {
            return 'This email address is not allowed for this application.';
        }

        if ($environment->blocksEmailSubaddresses() && str_contains($localPart, '+')) {
            return 'Email subaddresses (such as name+tag@example.com) are not allowed for this application.';
        }

        if ($environment->blocksDisposableEmails() && $this->isDisposable($domain)) {
            return 'Disposable email addresses are not allowed for this application.';
        }

        if ($this->failsAllowlist($environment, $email, $domain)) {
            return 'This email address is not on the allowlist for this application.';
        }

        return null;
    }

    public function allows(TenantApplicationEnvironment $environment, ?string $email): bool
    {
        return $this->reasonFor($environment, $email) === null;
    }

    private function isBlocked(TenantApplicationEnvironment $environment, string $email, string $domain): bool
    {
        foreach ($environment->accessRules() as $rule) {
            if ($rule['type'] !== 'block') {
                continue;
            }

            if ($rule['kind'] === 'email' && $rule['value'] === $email) {
                return true;
            }

            if ($rule['kind'] === 'domain' && $this->domainMatches($domain, $rule['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * When an allowlist exists, the address must match it. An empty allowlist
     * means no restriction.
     */
    private function failsAllowlist(TenantApplicationEnvironment $environment, string $email, string $domain): bool
    {
        $allowed = array_values(array_filter(
            $environment->accessRules(),
            fn (array $rule): bool => $rule['type'] === 'allow',
        ));

        if ($allowed === []) {
            return false;
        }

        foreach ($allowed as $rule) {
            if ($rule['kind'] === 'email' && $rule['value'] === $email) {
                return false;
            }

            if ($rule['kind'] === 'domain' && $this->domainMatches($domain, $rule['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match the domain itself or any subdomain of it.
     */
    private function domainMatches(string $domain, string $rule): bool
    {
        return $domain === $rule || str_ends_with($domain, '.'.$rule);
    }

    private function isDisposable(string $domain): bool
    {
        /** @var array<int, string> $domains */
        $domains = config('disposable_email_domains.domains', []);

        foreach ($domains as $disposable) {
            if ($this->domainMatches($domain, mb_strtolower($disposable))) {
                return true;
            }
        }

        return false;
    }
}
