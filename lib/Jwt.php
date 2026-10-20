<?php

/**
 * Signed access tokens (JWT, EdDSA / Ed25519) — see docs/auth-cms-plan.md.
 *
 * The auth service signs with the private key (`JWT_PRIVATE_KEY`, only in its
 * own env file); every service verifies with the public keys
 * (`JWT_PUBLIC_KEYS`, `kid:base64,kid:base64`) without calling anyone. libsodium
 * does the cryptography, so there's nothing to install.
 *
 * Only what's needed is implemented: header `{ alg: EdDSA, typ: JWT, kid }`,
 * and `verify()` checks the signature with the key named by `kid`, then `exp`
 * (with a minute of clock leeway), `nbf`/`iat` not in the future, `iss` and
 * `aud`. Anything off is `null` — callers answer 401, never say why.
 */
final class Jwt
{
    public const ISSUER = 'belikelocal-auth';
    private const LEEWAY_SECONDS = 60;

    /** Signs `$claims` with `JWT_PRIVATE_KEY` under key id `JWT_KID`. */
    public static function sign(array $claims): string
    {
        $secret = self::decodeKey((string) (getenv('JWT_PRIVATE_KEY') ?: ''), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $kid = (string) (getenv('JWT_KID') ?: '');
        if ($secret === null || $kid === '') {
            throw new LogicException('JWT_PRIVATE_KEY / JWT_KID are not configured.');
        }

        $header = self::encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $kid]);
        $payload = self::encode(['iss' => self::ISSUER] + $claims);
        $signature = sodium_crypto_sign_detached($header . '.' . $payload, $secret);

        return $header . '.' . $payload . '.' . self::base64url($signature);
    }

    /**
     * The token's claims if it's genuine, current and meant for `$audience`;
     * otherwise `null`.
     */
    public static function verify(string $token, string $audience, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 4096) {
            return null;
        }
        [$header64, $payload64, $signature64] = $parts;

        $header = self::decodeJson($header64);
        $claims = self::decodeJson($payload64);
        $signature = self::unbase64url($signature64);
        if (!$header || !$claims || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (($header['alg'] ?? '') !== 'EdDSA') {
            return null;
        }
        $key = self::publicKeys()[(string) ($header['kid'] ?? '')] ?? null;
        if ($key === null || !sodium_crypto_sign_verify_detached($signature, $header64 . '.' . $payload64, $key)) {
            return null;
        }

        $now = $now ?? time();
        if (
            ($claims['iss'] ?? null) !== self::ISSUER
            || ($claims['aud'] ?? null) !== $audience
            || !is_int($claims['exp'] ?? null) || $claims['exp'] + self::LEEWAY_SECONDS < $now
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] - self::LEEWAY_SECONDS > $now))
            || (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] - self::LEEWAY_SECONDS > $now))
        ) {
            return null;
        }

        return $claims;
    }

    /** @var array<string, string>|null kid → raw public key, parsed once per process */
    private static $publicKeys = null;

    /** @return array<string, string> */
    private static function publicKeys(): array
    {
        if (self::$publicKeys === null) {
            self::$publicKeys = [];
            foreach (explode(',', (string) (getenv('JWT_PUBLIC_KEYS') ?: '')) as $entry) {
                [$kid, $encoded] = array_pad(explode(':', trim($entry), 2), 2, '');
                $key = self::decodeKey($encoded, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
                if ($kid !== '' && $key !== null) {
                    self::$publicKeys[$kid] = $key;
                }
            }
        }

        return self::$publicKeys;
    }

    /** Re-reads `JWT_PUBLIC_KEYS` on next use — for tests that change the env. */
    public static function resetKeyCache(): void
    {
        self::$publicKeys = null;
    }

    private static function decodeKey(string $encoded, int $length): ?string
    {
        $raw = base64_decode($encoded, true);

        return $raw !== false && strlen($raw) === $length ? $raw : null;
    }

    private static function encode(array $data): string
    {
        return self::base64url((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function decodeJson(string $part): ?array
    {
        $json = self::unbase64url($part);
        $data = $json === null ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unbase64url(string $text): ?string
    {
        if ($text === '' || preg_match('/[^A-Za-z0-9_-]/', $text)) {
            return null;
        }
        $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
