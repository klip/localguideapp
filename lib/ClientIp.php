<?php

/**
 * The browser's IP address behind our proxies (docs/auth-cms-plan.md,
 * phase 3). Requests reach the gateway through nginx, and the auth
 * service's `/auth/*` through Vite (development) or nginx — each adds the
 * address it saw to `X-Forwarded-For`.
 *
 * Forwarding headers are only believed from a trusted proxy: the peer that
 * connected to us, and every hop walked back through, must be in
 * `TRUSTED_PROXIES` (comma-separated CIDRs; by default loopback and the
 * private ranges — the docker network). The client is the right-most address
 * that isn't a trusted proxy; anything left of it could be made up by the
 * browser and is ignored.
 */
final class ClientIp
{
    private const DEFAULT_TRUSTED = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7';

    /**
     * @param string $peer          the address that connected (REMOTE_ADDR, the socket's peer)
     * @param string $forwardedFor  the X-Forwarded-For header, if any
     */
    public static function resolve(string $peer, string $forwardedFor = ''): string
    {
        $peer = self::normalize($peer);
        if ($peer === '' || !self::isTrusted($peer)) {
            return $peer;
        }

        $hops = array_values(array_filter(array_map(
            [self::class, 'normalize'],
            explode(',', $forwardedFor)
        )));
        // Walk back from the nearest hop; the first address that isn't one
        // of our proxies is the client.
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            if (!self::isTrusted($hops[$i])) {
                return $hops[$i];
            }
        }

        // Every hop is ours (development: the browser is on a private
        // network too) — the farthest one is the best we know.
        return $hops[0] ?? $peer;
    }

    /** Loopback, private and link-local addresses — no location to look up. */
    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function isTrusted(string $ip): bool
    {
        $ranges = (string) (getenv('TRUSTED_PROXIES') ?: self::DEFAULT_TRUSTED);
        foreach (explode(',', $ranges) as $cidr) {
            if (self::inRange($ip, trim($cidr))) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** A valid IP address (without port or brackets), else ''. */
    private static function normalize(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $value, $m)) {
            $value = $m[1];
        } elseif (substr_count($value, ':') === 1) {
            $value = explode(':', $value)[0]; // IPv4 with a port
        }
        if (stripos($value, '::ffff:') === 0 && filter_var(substr($value, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $value = substr($value, 7);
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }
}
