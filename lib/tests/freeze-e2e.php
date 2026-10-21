<?php

// End-to-end check of freezing an account (docs/auth-cms-plan.md, phase 8)
// against the running services: real sign-ins through auth (nginx :8083, as
// the app's dev server would), the API over internal HTTP, the CMS classes
// directly.
//
//   docker stop lemp-rg-mailer
//   docker exec lemp-rg-cms php /var/www/localguideapp/lib/tests/freeze-e2e.php
//   docker start lemp-rg-mailer
//
// The mailer is stopped so the booking emails to the throwaway accounts
// (@example.test) aren't sent; the test deletes them, their accounts and
// everything they left at the end.

require_once __DIR__ . '/../../cms/bootstrap.php';

const ORIGIN = 'http://localhost:5173';
const GUEST = 'freeze.e2e.guest@example.test';
const GUIDE = 'freeze.e2e.guide@example.test';
const OTHER = 'freeze.e2e.other@example.test';
const PASSWORD = 'correct horse battery';

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** POST to the app's sign-in endpoint; returns [status, body, cookies set]. */
function auth(string $action, array $body, array $cookies = []): array
{
    $ch = curl_init('http://nginx:8083/auth/' . $action);
    $set = [];
    $cookie = implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body + ['device' => ['timeZone' => 'UTC']]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter(['Content-Type: application/json', 'Origin: ' . ORIGIN, $cookie !== '' ? 'Cookie: ' . $cookie : null]),
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$set): int {
            if (stripos($line, 'Set-Cookie:') === 0) {
                [$pair] = explode(';', trim(substr($line, 11)), 2);
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                $set[$name] = $value;
            }
            return strlen($line);
        },
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($raw, true) ?: [], $set];
}

/** An API action as user `$userId` acting as `$role`, the way the gateway forwards it. */
function api(string $action, array $payload, ?int $userId = null, string $role = ''): array
{
    $ch = curl_init('http://nginx:8081/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            'X-Internal-Token: ' . getenv('INTERNAL_TOKEN'),
            $userId ? 'X-User-Id: ' . $userId : null,
            $role !== '' ? 'X-Acting-Role: ' . $role : null,
        ]),
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($raw, true) ?: []];
}

function register(string $email, string $role, array $cookies = []): array
{
    $legal = new LegalDocuments(new db());
    $slug = LegalDocuments::ROLE_AGREEMENTS[$role];

    return auth('register', [
        'email' => $email,
        'password' => PASSWORD,
        'role' => $role,
        'name' => ucfirst($role) . ' Probe',
        'termsVersion' => $legal->currentVersion(LegalDocuments::TERMS),
        'agreementSlug' => $slug,
        'agreementVersion' => $legal->currentVersion($slug),
        'language' => 'en',
    ], $cookies);
}

$db = new db();
$redis = new RedisClient();
$staff = ['id' => 0, 'email' => 'freeze.e2e.staff@example.test'];

function cleanup(db $db): void
{
    $ids = array_column((array) $db->db_GetArray('SELECT `id` FROM `users` WHERE `email` LIKE "freeze.e2e.%@example.test"'), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (['refresh_tokens', 'devices', 'login_events', 'notifications', 'user_legal_acceptances', 'user_freezes'] as $table) {
            $db->db_Execute("DELETE FROM `{$table}` WHERE `user_id` IN ({$in})");
        }
        $db->db_Execute("DELETE FROM `account_links` WHERE `user_a` IN ({$in}) OR `user_b` IN ({$in})");
        $db->db_Execute("DELETE FROM `bookings` WHERE `guest_id` IN ({$in}) OR `guide_id` IN ({$in})");
        $db->db_Execute("DELETE FROM `users` WHERE `id` IN ({$in})");
    }
    $db->db_Execute('DELETE FROM `email_outbox` WHERE `recipient` LIKE "freeze.e2e.%@example.test" AND `status` = "queued"');
    $db->db_Execute('DELETE FROM `staff_audit` WHERE `staff_email` = "freeze.e2e.staff@example.test"');
}

cleanup($db);
try {
    // 1. Two real accounts, signed in, with a paid booking waiting on the guide.
    [$s, $guest, $guestCookies] = register(GUEST, 'guest');
    check('guest registered', $s === 200 && !empty($guest['accessToken']), (string) $s);
    [$s, $guide, $guideCookies] = register(GUIDE, 'guide');
    check('guide registered', $s === 200 && !empty($guideCookies['__Host-rg_refresh']), (string) $s);
    $guestId = (int) $guest['id'];
    $guideId = (int) $guide['id'];
    $db->db_Execute("INSERT INTO `user_profiles` (`user_id`, `price_amount`) VALUES ({$guideId}, 40)");
    $db->db_Execute("INSERT INTO `selections` (`guest_id`, `guide_id`, `guest_decision`, `guide_decision`, `active`, `matched_at`)
                     VALUES ({$guestId}, {$guideId}, 'interested', 'interested', 1, UTC_TIMESTAMP())");
    $selectionId = (int) $db->db_GetLastId();
    $db->db_Execute("INSERT INTO `bookings` (`selection_id`, `guest_id`, `guide_id`, `amount`, `meeting_at`, `status`, `payment_status`)
                     VALUES ({$selectionId}, {$guestId}, {$guideId}, 40, UTC_TIMESTAMP() + INTERVAL 3 DAY, 'pending', 'paid')");
    $bookingId = (int) $db->db_GetLastId();

    // The guest already picked them, so the anonymous deck is the fair check.
    [$s, $b] = api('guides', []);
    check('the guide is on the deck before', in_array($guideId, array_map('intval', array_column($b['guides'] ?? [], 'id')), true));
    [$s, $b] = api('publicProfile', ['userId' => $guideId, 'role' => 'guide']);
    check('their profile opens before', $s === 200, (string) $s);

    // 2. A freeze must decide about the open booking.
    $freezes = new UserFreezes($db, $redis);
    $refused = null;
    try {
        $freezes->freeze($guideId, ['days' => 7, 'reason' => 'probe'], $staff);
    } catch (RuntimeException $e) {
        $refused = json_decode($e->getMessage(), true)['field'] ?? null;
    }
    check('a freeze without a decision on each open booking is refused', $refused === 'bookings');

    [, $after] = $freezes->freeze($guideId, [
        'days' => 7,
        'reason' => 'Repeated no-shows (probe)',
        'bookings' => [['id' => $bookingId, 'decision' => 'cancel', 'refund' => 30]],
    ], $staff);
    check('frozen, booking cancelled through the API', ($after['bookings'][$bookingId] ?? '') === 'cancelled', json_encode($after['bookings']));
    $booking = $db->db_GetRow("SELECT * FROM `bookings` WHERE `id` = {$bookingId}");
    check('…with the partial refund', $booking['status'] === 'cancelled' && $booking['payment_status'] === 'refunded' && (float) $booking['refund_amount'] === 30.0);
    check('…the match dissolved', (int) $db->db_GetFieldFromQuery("SELECT `active` AS a FROM `selections` WHERE `id` = {$selectionId}", 'a') === 0);
    $told = (int) $db->db_GetFieldFromQuery("SELECT COUNT(*) AS n FROM `notifications` WHERE `kind` = 'booking_cancelled' AND `user_id` IN ({$guestId}, {$guideId})", 'n');
    check('…both sides told', $told === 2, (string) $told);

    // 3. Signed out, and kept out.
    [$s] = auth('refresh', [], ['__Host-rg_refresh' => $guideCookies['__Host-rg_refresh'], '__Host-rg_device' => $guideCookies['__Host-rg_device'] ?? '']);
    check('their refresh token stops working', $s === 401, (string) $s);
    [$s, $b] = auth('login', ['email' => GUIDE, 'password' => PASSWORD], ['__Host-rg_device' => $guideCookies['__Host-rg_device'] ?? '']);
    check('signing in is refused, with the date', $s === 400 && strpos((string) ($b['error'] ?? ''), 'suspended until') !== false, $s . ' ' . ($b['error'] ?? ''));
    [$s, $b] = auth('login', ['email' => GUIDE, 'password' => 'wrong password!']);
    check('a wrong password doesn’t reveal the freeze', $s === 400 && strpos((string) ($b['error'] ?? ''), 'suspended') === false);
    $revoked = $redis->command('GET', 'revoked:user:' . $guideId);
    check('gateways told to drop their tokens', $revoked !== null && $revoked !== false);
    [$s, $b] = register(OTHER, 'guide', ['__Host-rg_device' => $guideCookies['__Host-rg_device'] ?? '']);
    check('a new account from their browser is refused', $s === 400 && strpos((string) ($b['error'] ?? ''), 'browser') !== false, $s . ' ' . ($b['error'] ?? ''));

    // 4. Gone from the app for everyone else.
    [, $b] = api('guides', []);
    check('not on any deck', !in_array($guideId, array_map('intval', array_column($b['guides'] ?? [], 'id')), true));
    [$s] = api('publicProfile', ['userId' => $guideId, 'role' => 'guide']);
    check('their profile reads as not found', $s === 400, (string) $s);
    $db->db_Execute("UPDATE `selections` SET `active` = 1, `guest_decision` = 'interested', `guide_decision` = 'interested' WHERE `id` = {$selectionId}");
    [$s, $b] = api('sendMessage', ['targetId' => $guideId, 'body' => 'Hello?'], $guestId, 'guest');
    check('the chat is read-only', $s === 400 && strpos((string) ($b['error'] ?? ''), 'suspended') !== false, $s . ' ' . ($b['error'] ?? ''));
    [, $b] = api('messages', ['targetId' => $guideId], $guestId, 'guest');
    check('…and says so (can_send false)', ($b['can_send'] ?? null) === false);
    [$s] = api('decide', ['targetId' => $guideId, 'decision' => 'interested'], $guestId, 'guest');
    check('can’t pick them', $s === 400, (string) $s);

    // 5. The CMS shows it; lifting it lets them back in.
    $detail = (new UserAdmin($db))->detail($guideId, true);
    check('the user page shows the freeze', $detail['freeze'] !== null && $detail['freeze']['reason'] === 'Repeated no-shows (probe)');
    $list = (new UserAdmin($db))->search(['q' => GUIDE, 'frozen' => 'yes']);
    check('the list finds them as frozen', $list['total'] === 1 && $list['users'][0]['frozen'] && $list['users'][0]['frozenUntil'] !== null);
    $freezes->lift($guideId, $detail['freeze']['id'], 'Appeal accepted (probe)', $staff);
    sleep(1); // a token issued in the freeze's second would still count as revoked
    [$s, $b] = auth('login', ['email' => GUIDE, 'password' => PASSWORD], ['__Host-rg_device' => $guideCookies['__Host-rg_device'] ?? '']);
    check('after lifting, they can sign in again', $s === 200 && !empty($b['accessToken']), $s . ' ' . ($b['error'] ?? ''));
    [$s] = api('publicProfile', ['userId' => $guideId, 'role' => 'guide']);
    check('…and their profile is back', $s === 200, (string) $s);

    // 6. A permanent freeze reads as such.
    $freezes->freeze($guideId, ['permanent' => true, 'reason' => 'probe', 'bookings' => []], $staff);
    [$s, $b] = auth('login', ['email' => GUIDE, 'password' => PASSWORD], ['__Host-rg_device' => $guideCookies['__Host-rg_device'] ?? '']);
    check('a permanent freeze says “suspended.” without a date', $s === 400 && strpos((string) ($b['error'] ?? ''), 'until') === false);
} finally {
    cleanup($db);
}

echo $failures ? "{$failures} FAILED\n" : "ALL PASSED\n";
exit($failures ? 1 : 0);
