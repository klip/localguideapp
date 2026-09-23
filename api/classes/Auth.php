<?php

/**
 * Password hashing for the `creds` table.
 *
 * A registration/verify pass combines, per attempt:
 *   - the password the user typed
 *   - a random per-user salt (`creds.salt`)
 *   - a server-wide pepper, read from $_SERVER['PEPPER'] and never stored in
 *     the database
 *
 * The three are mixed with an HMAC (pepper as the HMAC key) before the
 * result is run through password_hash()/password_verify(). Keying the HMAC
 * with the pepper — rather than just concatenating it into the password
 * string — is what makes the pepper actually add security beyond the salt;
 * it also sidesteps bcrypt's 72-byte input truncation.
 */
class Auth
{
    private const HASH_ALGO = PASSWORD_BCRYPT;

    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function hashPassword(string $password, string $salt): string
    {
        return password_hash(self::pepper($password, $salt), self::HASH_ALGO);
    }

    public static function verifyPassword(string $password, string $salt, string $hash): bool
    {
        return password_verify(self::pepper($password, $salt), $hash);
    }

    private static function pepper(string $password, string $salt): string
    {
        // FastCGI param first (see the nginx vhost), then the process
        // environment so CLI scripts work. `getenv()` returns false when
        // unset, which `??` would not catch — `?:` collapses it to '' so the
        // guard below still fires.
        $pepper = (string) ($_SERVER['PEPPER'] ?? getenv('PEPPER') ?: '');
        if ($pepper === '') {
            // Fail loudly rather than silently hashing without a pepper —
            // that would produce hashes that look valid but are weaker than
            // intended, and would break verification once PEPPER is set.
            throw new RuntimeException('PEPPER is not configured on the server.');
        }

        return hash_hmac('sha256', $password . $salt, $pepper);
    }
}
