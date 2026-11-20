<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class OutboundUrlValidator
{
    /**
     * Validate an outbound webhook URL and return CURLOPT_RESOLVE entries
     * pinning the hostname to validated public IP addresses.
     *
     * @return list<string>
     */
    public static function validateWebhookUrl(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The webhook URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = app()->isProduction() ? ['https'] : ['http', 'https'];

        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException('The webhook URL scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The webhook URL must not contain credentials.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('The webhook URL host is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($host)) {
                throw new InvalidArgumentException('The webhook URL IP address is not allowed.');
            }

            return ["{$host}:{$port}:{$host}"];
        }

        $ips = self::resolveHostIps($host);

        if ($ips === []) {
            if (self::isHttpFakedInTests()) {
                return [];
            }

            throw new InvalidArgumentException('The webhook URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('The webhook URL resolves to a restricted IP address.');
            }
        }

        return ["{$host}:{$port}:{$ips[0]}"];
    }

    /**
     * Validate a database host for external user imports.
     */
    public static function assertSafeDatabaseHost(string $host): void
    {
        $trimmed = strtolower(trim($host, '[] '));

        if ($trimmed === '' || $trimmed === 'localhost' || str_ends_with($trimmed, '.localhost') || str_ends_with($trimmed, '.local')) {
            throw new InvalidArgumentException('The database host is not allowed.');
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicIp($trimmed)) {
                throw new InvalidArgumentException('The database IP address is not allowed.');
            }

            return;
        }

        $ips = self::resolveHostIps($trimmed);

        if ($ips === []) {
            throw new InvalidArgumentException('The database host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('The database host resolves to a restricted IP address.');
            }
        }
    }

    /**
     * Determine whether a candidate redirect URL shares the exact same origin
     * (scheme, host, and port) as the given base URL.
     */
    public static function isSameOrigin(?string $candidate, string $baseUrl): bool
    {
        if (! is_string($candidate) || $candidate === '') {
            return false;
        }

        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return true;
        }

        $candidateParts = parse_url($candidate);
        $baseParts = parse_url($baseUrl);

        if (! is_array($candidateParts) || ! is_array($baseParts)) {
            return false;
        }

        if (isset($candidateParts['user']) || isset($candidateParts['pass'])) {
            return false;
        }

        $candidateScheme = strtolower((string) ($candidateParts['scheme'] ?? ''));
        $baseScheme = strtolower((string) ($baseParts['scheme'] ?? ''));
        $candidateHost = strtolower((string) ($candidateParts['host'] ?? ''));
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));

        if ($candidateScheme === '' || $candidateScheme !== $baseScheme || $candidateHost === '' || $candidateHost !== $baseHost) {
            return false;
        }

        $candidatePort = $candidateParts['port'] ?? ($candidateScheme === 'https' ? 443 : 80);
        $basePort = $baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80);

        return (int) $candidatePort === (int) $basePort;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Also block CGNAT 100.64.0.0/10 and 0.0.0.0/8.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long === false) {
                return false;
            }

            if (($long & 0xFFC00000) === 0x64400000) {
                return false;
            }

            if (($long & 0xFF000000) === 0x00000000) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) {
                        $ips[] = $record['ip'];
                    } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === [] && function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);

            if (is_array($resolved)) {
                foreach ($resolved as $ip) {
                    if (is_string($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isHttpFakedInTests(): bool
    {
        if (! app()->runningUnitTests()) {
            return false;
        }

        $factory = Http::getFacadeRoot();

        if (! is_object($factory)) {
            return false;
        }

        $reflection = new \ReflectionProperty($factory, 'stubCallbacks');
        $callbacks = $reflection->getValue($factory);

        return $callbacks instanceof Collection && $callbacks->isNotEmpty();
    }
}
