<?php

// Plain-PHP checks for `Jwt` (there's no PHP test runner yet):
//   docker exec lemp-php php /var/www/localguideapp/lib/tests/JwtTest.php
// Uses throwaway keys of its own, never the configured ones. Exits non-zero
// on any failure.

require_once __DIR__ . '/../Jwt.php';

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL: {$name}\n";
    }
}

function useKeys(string $kid, string $keypair, array $publicKeys): void
{
    putenv('JWT_KID=' . $kid);
    putenv('JWT_PRIVATE_KEY=' . base64_encode(sodium_crypto_sign_secretkey($keypair)));
    putenv('JWT_PUBLIC_KEYS=' . implode(',', array_map(
        static fn(string $id, string $pair): string => $id . ':' . base64_encode(sodium_crypto_sign_publickey($pair)),
        array_keys($publicKeys),
        $publicKeys
    )));
    Jwt::resetKeyCache();
}

$k1 = sodium_crypto_sign_keypair();
$k2 = sodium_crypto_sign_keypair();
$now = time();
$claims = ['aud' => 'app', 'sub' => '42', 'roles' => ['guest'], 'iat' => $now, 'exp' => $now + 900];

useKeys('k1', $k1, ['k1' => $k1]);
$token = Jwt::sign($claims);

$verified = Jwt::verify($token, 'app');
check('a fresh token verifies', $verified !== null && $verified['sub'] === '42' && $verified['roles'] === ['guest']);
check('the issuer is set', ($verified['iss'] ?? null) === Jwt::ISSUER);
check('another audience is refused', Jwt::verify($token, 'cms') === null);
check('expired (past the leeway) is refused', Jwt::verify($token, 'app', $now + 900 + 61) === null);
check('within the leeway is accepted', Jwt::verify($token, 'app', $now + 900 + 30) !== null);
check('issued in the future is refused', Jwt::verify($token, 'app', $now - 120) === null);

[$h, $p, $s] = explode('.', $token);
$forged = Jwt::base64url((string) json_encode(['iss' => Jwt::ISSUER] + array_merge($claims, ['sub' => '1'])));
check('a changed payload is refused', Jwt::verify("$h.$forged.$s", 'app') === null);
check('a changed signature is refused', Jwt::verify("$h.$p." . strrev($s), 'app') === null);
check('garbage is refused', Jwt::verify('not.a.token', 'app') === null && Jwt::verify('', 'app') === null);
$none = Jwt::base64url('{"alg":"none","typ":"JWT","kid":"k1"}');
check('alg "none" is refused', Jwt::verify("$none.$p.", 'app') === null && Jwt::verify("$none.$p.$s", 'app') === null);

// Another key pair, signing under the same kid: the signature doesn't match.
useKeys('k1', $k2, ['k1' => $k1]);
check('a token signed with another key is refused', Jwt::verify(Jwt::sign($claims), 'app') === null);

// Rotation: sign with k2, both public keys known — old and new tokens verify.
useKeys('k2', $k2, ['k1' => $k1, 'k2' => $k2]);
check('rotation: the old key still verifies', Jwt::verify($token, 'app') !== null);
check('rotation: the new key verifies', Jwt::verify(Jwt::sign($claims), 'app') !== null);
useKeys('k2', $k2, ['k2' => $k2]);
check('a retired key is refused', Jwt::verify($token, 'app') === null);

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}
echo "All Jwt checks passed\n";
