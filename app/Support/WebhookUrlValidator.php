<?php

declare(strict_types=1);

namespace App\Support;

final class WebhookUrlValidator
{
    /**
     * Validate that a webhook destination URL is safe to request (SSRF protection).
     */
    public static function validate(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return 'The webhook URL is not a valid URL.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowedSchemes = app()->environment('local', 'testing') ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            return 'The webhook URL must use HTTPS.';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return 'The webhook URL must include a valid host.';
        }

        $host = trim($host, '[]');

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || (! app()->runningUnitTests() && str_ends_with($host, '.internal'))
        ) {
            return 'The webhook URL host is not permitted.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($host)) {
                return 'The webhook URL must not target private or reserved IP ranges.';
            }

            return null;
        }

        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            foreach ($resolved as $ip) {
                if (! self::isPublicIp($ip)) {
                    return 'The webhook URL resolves to a private or reserved IP address.';
                }
            }
        }

        return null;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
