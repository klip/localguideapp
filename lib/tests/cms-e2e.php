<?php

// End-to-end check of staff sign-in and the CMS API (docs/auth-cms-plan.md,
// phase 6) against the running services, through nginx (:8085 sign-in,
// :8084 API) as the admin app's dev server would:
//
//   docker stop lemp-rg-mailer
//   docker exec lemp-rg-auth php /var/www/localguideapp/lib/tests/cms-e2e.php
//   docker start lemp-rg-mailer
//
// The mailer must be stopped: the test reads the emailed codes and links from
// the queued outbox. It creates two staff accounts (@example.test) and
// removes them, their audit rows and their mail at the end.

require_once __DIR__ . '/../../auth/bootstrap.php';

const ORIGIN = 'http://localhost:5174';
const ADMIN = 'cms.e2e.admin@example.test';
const EDITOR = 'cms.e2e.editor@example.test';
const CONTENT_EDITOR = 'cms.e2e.content@example.test';
const TEST_KEY = 'discover.deck.e2e_probe';
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

/** POST JSON; returns [status, body, setCookie (name => value)]. */
function post(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        // An `Origin:` in $headers replaces the CMS's.
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json'],
            preg_grep('/^Origin:/i', $headers) ? [] : ['Origin: ' . ORIGIN],
            $headers
        ),
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (stripos($line, 'Set-Cookie:') === 0) {
                [$pair] = explode(';', trim(substr($line, 11)), 2);
                [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                $responseHeaders[$name] = $value;
            }
            return strlen($line);
        },
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($raw, true) ?: [], $responseHeaders];
}

function auth(string $action, array $body, string $cookie = ''): array
{
    return post('http://nginx:8085/auth/' . $action, $body, $cookie !== '' ? ['Cookie: __Host-rg_cms_refresh=' . $cookie] : []);
}

function api(string $action, array $body = [], string $token = ''): array
{
    return post('http://nginx:8084/api', ['action' => $action] + $body, $token !== '' ? ['Authorization: Bearer ' . $token] : []);
}

$db = new db();
$redis = new RedisClient();
$staff = new Staff($db, new MailQueue($db, $redis));

function latestMail(db $db, string $to): array
{
    return $db->db_GetRow('SELECT `subject`, `body` FROM `email_outbox` WHERE `recipient` = "' . $db->db_Escape($to) . '" ORDER BY `id` DESC LIMIT 1') ?: [];
}

function signIn(db $db, string $email): array
{
    [$status, $body] = auth('login', ['email' => $email, 'password' => PASSWORD]);
    if ($status !== 200 || empty($body['codeRequired'])) {
        return [$status, $body, ''];
    }
    preg_match('/(\d{6})/', (string) (latestMail($db, $email)['subject'] ?? ''), $m);
    [$status, $body, $cookies] = auth('verifyCode', ['challengeToken' => $body['challengeToken'], 'code' => $m[1] ?? '']);

    return [$status, $body, $cookies['__Host-rg_cms_refresh'] ?? ''];
}

function cleanup(db $db): void
{
    // Content: the probe key, and the history of the test's edits.
    $db->db_Execute('DELETE FROM `translations` WHERE `key` = "' . TEST_KEY . '"');
    $db->db_Execute('DELETE FROM `translations_history` WHERE `staff_email` LIKE "cms.e2e.%@example.test"');
    foreach ([ADMIN, EDITOR, CONTENT_EDITOR] as $email) {
        $row = $db->db_GetRow('SELECT `id` FROM `cms_users` WHERE `email` = "' . $email . '"');
        if ($row) {
            $db->db_Execute('DELETE FROM `staff_audit` WHERE `staff_id` = ' . (int) $row['id'] . ' OR (`target_type` = "staff" AND `target_id` = "' . (int) $row['id'] . '")');
            $db->db_Execute('DELETE FROM `cms_users` WHERE `id` = ' . (int) $row['id']);
        }
        $db->db_Execute('DELETE FROM `email_outbox` WHERE `recipient` = "' . $email . '" AND `status` = "queued"');
    }
}

cleanup($db);
try {
    // 1. An admin, created like cms/bin/create-admin.php does, sets a password from the link.
    $adminId = $staff->create(ADMIN, 'E2E Admin', 'admin', null);
    $link = $staff->createSetupLink($adminId, Staff::INVITE_TTL_SECONDS, 'invite');
    $token = substr($link, strpos($link, 'token=') + 6);
    check('the invite link is emailed', str_contains((string) (latestMail($db, ADMIN)['body'] ?? ''), $token));
    [$s] = auth('setPassword', ['token' => $token, 'password' => 'short']);
    check('a short password is refused', $s === 400, (string) $s);
    [$s] = auth('setPassword', ['token' => $token, 'password' => PASSWORD]);
    check('the link sets the password', $s === 200, (string) $s);
    [$s] = auth('setPassword', ['token' => $token, 'password' => PASSWORD]);
    check('…once', $s === 400, (string) $s);

    // 2. Sign-in needs the password and then the emailed code.
    [$s, $b] = auth('login', ['email' => ADMIN, 'password' => 'wrong password!!']);
    check('a wrong password → 400', $s === 400, (string) $s);
    [$s, $b] = auth('login', ['email' => ADMIN, 'password' => PASSWORD]);
    check('the right password → a code, no token yet', $s === 200 && !empty($b['codeRequired']) && empty($b['accessToken']));
    [$s] = auth('verifyCode', ['challengeToken' => $b['challengeToken'], 'code' => '000000']);
    check('a wrong code → 400', $s === 400, (string) $s);
    [$s, $b, $adminCookie] = signIn($db, ADMIN);
    $adminToken = (string) ($b['accessToken'] ?? '');
    check('the code signs in, with the CMS cookie', $s === 200 && $adminToken !== '' && $adminCookie !== '', (string) $s);
    check('…as an admin', ($b['staff']['role'] ?? '') === 'admin');
    [$s, $b, $c] = post('http://nginx:8085/auth/login', ['email' => ADMIN, 'password' => PASSWORD], ['Origin: http://localhost:5173']);
    check("the app's origin can't sign staff in → 403", $s === 403, (string) $s);

    // 3. The API: token, origin, permissions.
    [$s, $b] = api('me', [], $adminToken);
    check('me → the admin and their capabilities', $s === 200 && in_array('staff.manage', $b['capabilities'] ?? [], true), (string) $s);
    check('no token → 401', api('me')[0] === 401);
    check('a garbage token → 401', api('me', [], 'x.y.z')[0] === 401);
    $appToken = Jwt::sign(['aud' => 'app', 'sub' => (string) $adminId, 'sid' => 'x', 'iat' => time(), 'exp' => time() + 60]);
    check("an app token (aud app) → 401", api('me', [], $appToken)[0] === 401);

    // 4. Inviting an editor; the editor's limits.
    [$s, $b] = api('staffInvite', ['email' => EDITOR, 'name' => 'E2E Editor', 'role' => 'editor'], $adminToken);
    $editorId = (int) ($b['staff']['id'] ?? 0);
    check('the admin invites an editor', $s === 200 && $editorId > 0, (string) $s);
    preg_match('/token=([0-9a-f]{64})/', (string) (latestMail($db, EDITOR)['body'] ?? ''), $m);
    auth('setPassword', ['token' => $m[1] ?? '', 'password' => PASSWORD]);
    [$s, $b] = signIn($db, EDITOR);
    $editorToken = (string) ($b['accessToken'] ?? '');
    check('the editor signs in', $s === 200 && $editorToken !== '', (string) $s);
    check('an editor can’t list staff → 403', api('staffList', [], $editorToken)[0] === 403);
    [$s, $b] = api('auditLog', [], $editorToken);
    check("an editor's audit log is their own", $s === 200 && ($b['scope'] ?? '') === 'own', (string) $s);

    // 5. Changing a role ends that member's sign-ins at once.
    [$s, $b] = api('staffUpdate', ['id' => $editorId, 'role' => 'manager'], $adminToken);
    check('the admin makes the editor a manager', $s === 200 && ($b['staff']['role'] ?? '') === 'manager', (string) $s);
    check("…and the old token stops working", api('me', [], $editorToken)[0] === 401);
    check('the admin can’t disable themselves', api('staffUpdate', ['id' => $adminId, 'disabled' => true], $adminToken)[0] === 400);
    [$s, $b] = api('staffUpdate', ['id' => $editorId, 'disabled' => true], $adminToken);
    check('the admin disables the manager', $s === 200 && !empty($b['staff']['disabled']), (string) $s);
    [$s] = auth('login', ['email' => EDITOR, 'password' => PASSWORD]);
    check('a disabled member can’t sign in', $s === 400, (string) $s);

    // 6. The audit log records it all.
    [$s, $b] = api('auditLog', [], $adminToken);
    $actions = array_column($b['entries'] ?? [], 'action');
    check('the audit log has the invite, role change and disabling',
        in_array('staff.invite', $actions, true) && in_array('staff.role', $actions, true) && in_array('staff.disable', $actions, true),
        implode(',', array_slice($actions, 0, 5)));

    // 7. The content editor (phase 7), as an editor.
    $contentId = $staff->create(CONTENT_EDITOR, 'E2E Content', 'editor', $adminId);
    $link = $staff->createSetupLink($contentId, Staff::INVITE_TTL_SECONDS, 'invite');
    auth('setPassword', ['token' => substr($link, strpos($link, 'token=') + 6), 'password' => PASSWORD]);
    [$s, $b] = signIn($db, CONTENT_EDITOR);
    $editorToken = (string) ($b['accessToken'] ?? '');
    [$s, $b] = api('contentLayout', [], $editorToken);
    $routes = array_column($b['pages'] ?? [], 'route');
    check('the map lists the app\'s pages and 15 languages', $s === 200 && in_array('discover', $routes, true) && count($b['languages'] ?? []) === 15, (string) $s);
    [$s, $b] = api('contentBlock', ['prefix' => 'common.active_filters', 'language' => 'es'], $editorToken);
    // "Near {place}" — a stable key with a placeholder.
    $picked = array_values(array_filter($b['entries'] ?? [], static fn($e) => $e['key'] === 'common.active_filters.near'))[0] ?? [];
    check('a block shows each key\'s last part, the Spanish and the English', $s === 200 && ($picked['name'] ?? '') === 'near' && str_contains((string) ($picked['english'] ?? ''), '{place}'));
    $original = (string) ($picked['value'] ?? '');
    [$s, $b] = api('contentSave', ['prefix' => 'common.active_filters', 'language' => 'es', 'values' => ['common.active_filters.near' => 'Cerca']], $editorToken);
    check('dropping a {placeholder} is refused, pinned to the field', $s === 400 && ($b['field'] ?? '') === 'common.active_filters.near' && str_contains((string) ($b['error'] ?? ''), '{place}'), (string) $s);
    [$s, $b] = api('contentSave', ['prefix' => 'common.active_filters', 'language' => 'es', 'values' => ['common.active_filters.near' => '<b>{place}</b>']], $editorToken);
    check('HTML is refused', $s === 400, (string) $s);
    [$s, $b] = api('contentSave', ['prefix' => 'common.active_filters', 'language' => 'es', 'values' => ['common.active_filters.near' => 'Cerca de {place} (prueba)']], $editorToken);
    check('a valid edit saves', $s === 200 && ($b['changed'] ?? 0) === 1, (string) $s);
    $live = $db->db_GetRow('SELECT `value` FROM `translations` WHERE `language` = "es" AND `key` = "common.active_filters.near"')['value'] ?? '';
    check('…straight into the app\'s translations', $live === 'Cerca de {place} (prueba)');
    [$s, $b] = api('contentHistory', ['prefix' => 'common.active_filters'], $editorToken);
    $entry = $b['history'][0] ?? [];
    check('the history has the old and new value', ($entry['oldValue'] ?? '') === $original && ($entry['newValue'] ?? '') === 'Cerca de {place} (prueba)');
    [$s, $b] = api('contentRevert', ['historyId' => (int) ($entry['id'] ?? 0)], $editorToken);
    $live = $db->db_GetRow('SELECT `value` FROM `translations` WHERE `language` = "es" AND `key` = "common.active_filters.near"')['value'] ?? '';
    check('revert puts the old value back', $s === 200 && $live === $original, (string) $s);
    [$s, $b] = api('contentSave', ['prefix' => 'common.active_filters', 'language' => 'en', 'values' => ['common.active_filters.near' => '']], $editorToken);
    check('English can\'t be emptied', $s === 400, (string) $s);
    [$s, $b] = api('contentAddKey', ['prefix' => 'discover.deck', 'name' => 'Bad Name', 'english' => 'x'], $editorToken);
    check('a key name must be lowercase_with_underscores', $s === 400 && ($b['field'] ?? '') === 'name', (string) $s);
    [$s, $b] = api('contentAddKey', ['prefix' => 'discover.deck', 'name' => 'e2e_probe', 'english' => 'Probe for {count}', 'language' => 'es'], $editorToken);
    $probe = array_values(array_filter($b['entries'] ?? [], static fn($e) => $e['key'] === TEST_KEY))[0] ?? [];
    check('a new key is added in English, missing in Spanish', $s === 200 && !empty($probe['missing']), (string) $s);
    [$s, $b] = api('auditLog', ['filter' => 'content.'], $adminToken);
    check('content changes are in the audit log', in_array('content.save', array_column($b['entries'] ?? [], 'action'), true) && in_array('content.add_key', array_column($b['entries'] ?? [], 'action'), true));

    // 8. Refresh and logout.
    [$s, $b, $cookies] = auth('refresh', [], $adminCookie);
    $rotated = $cookies['__Host-rg_cms_refresh'] ?? '';
    check('refresh → a new token, a rotated cookie', $s === 200 && !empty($b['accessToken']) && $rotated !== '' && $rotated !== $adminCookie, (string) $s);
    [$appStatus] = post('http://nginx:8083/auth/refresh', [], ['Cookie: __Host-rg_refresh=' . $rotated, 'Origin: http://localhost:5173']);
check('the app cookie endpoint knows nothing of staff', $appStatus === 401, (string) $appStatus);
    [$s] = auth('logout', [], $rotated);
    check('logout', $s === 200);
    check('…then refresh → 401', auth('refresh', [], $rotated)[0] === 401);
    usleep(200000);
    check('…and its access token → 401', api('me', [], (string) $b['accessToken'])[0] === 401);
} finally {
    cleanup($db);
}

echo $failures ? "{$failures} FAILED\n" : "ALL PASSED\n";
exit($failures ? 1 : 0);
