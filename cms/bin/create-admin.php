<?php

/**
 * Creates a CMS admin and emails them a set-password link (72 hours) — how
 * the first admin comes to exist; later ones are invited from the CMS.
 *
 *   docker exec lemp-rg-cms php /var/www/localguideapp/cms/bin/create-admin.php you@example.com "Your Name"
 *
 * `--print-link` also prints the link: for development and tests only (it's
 * a credential). Role: `--role=manager|editor` for a non-admin.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => strncmp($a, '--', 2) !== 0));
$flags = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => strncmp($a, '--', 2) === 0));
$role = 'admin';
foreach ($flags as $flag) {
    if (strncmp($flag, '--role=', 7) === 0) {
        $role = substr($flag, 7);
    }
}
if (count($args) < 1) {
    fwrite(STDERR, "Usage: create-admin.php <email> [\"Name\"] [--role=admin|manager|editor] [--print-link]\n");
    exit(1);
}

$db = new db();
$staff = new Staff($db, new MailQueue($db, new RedisClient()));
try {
    $id = $staff->create($args[0], $args[1] ?? '', $role, null);
    $link = $staff->createSetupLink($id, Staff::INVITE_TTL_SECONDS, 'invite');
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Created {$role} #{$id} ({$args[0]}); a set-password link was emailed (72 hours).\n";
if (in_array('--print-link', $flags, true)) {
    echo $link . "\n";
}
