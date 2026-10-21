<?php

// End-to-end check of the CMS's Moderation section (docs/auth-cms-plan.md,
// phase 9) against the running database and API: the complaints queue and
// its decisions (with the reporter told through the API, once), and the
// linked-accounts review. Uses throwaway rows between two seeded users.
//
//   docker stop lemp-rg-mailer
//   docker exec lemp-rg-cms php /var/www/localguideapp/lib/tests/moderation-e2e.php
//   docker start lemp-rg-mailer
//
// The mailer is stopped so the "we reviewed your report" email isn't sent;
// everything the test adds is removed at the end.

require_once __DIR__ . '/../../cms/bootstrap.php';

const REPORTER = 217; // seed.guest.50
const SUBJECT = 156;  // seed.guide.39
const STAFF = ['id' => 0, 'email' => 'moderation.e2e@example.test'];

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** The field a refused call pins its error to, or its message. */
function refused(callable $call): ?string
{
    try {
        $call();
    } catch (RuntimeException $e) {
        return json_decode($e->getMessage(), true)['field'] ?? $e->getMessage();
    }

    return null;
}

$db = new db();
$moderation = new Moderation($db, new UserEditor($db, new RedisClient()));
$levels = new Levels($db);
$told = static fn(): int => (int) $db->db_GetFieldFromQuery(
    'SELECT COUNT(*) AS `n` FROM `notifications` WHERE `user_id` = ' . REPORTER . ' AND `kind` = "complaint_reviewed"',
    'n'
);
$toldBefore = $told();

$db->db_Execute('INSERT INTO `complaints` (`reporter_id`, `subject_id`, `title`, `description`)
                 VALUES (' . REPORTER . ', ' . SUBJECT . ', "E2E probe: rude", "E2E probe description")');
$complaintId = (int) $db->db_GetLastId();
$db->db_Execute('INSERT INTO `account_links` (`user_a`, `user_b`, `signal`, `strength`, `hits`, `first_seen_at`, `last_seen_at`)
                 VALUES (' . REPORTER . ', ' . SUBJECT . ', "fingerprint", 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
$linkId = (int) $db->db_GetLastId();

try {
    // The queue.
    $queue = $moderation->complaints([]);
    check('new complaint is in the to-do queue', in_array($complaintId, array_column($queue['complaints'], 'id'), true));
    check('…with counts per status', $queue['counts']['open'] >= 1);
    check('closed ones aren’t in it', !in_array($complaintId, array_column($moderation->complaints(['status' => 'resolved'])['complaints'], 'id'), true));

    // One complaint.
    $c = $moderation->complaint($complaintId);
    check('shows both people', $c['reporter']['id'] === REPORTER && $c['subject']['id'] === SUBJECT);
    check('…and what’s between them', is_array($c['between']['matches']) && is_int($c['between']['messageCount']));
    check('the chat reads both directions', is_array($moderation->messages($complaintId)['messages']));

    $moderation->take($complaintId, STAFF);
    $c = $moderation->complaint($complaintId);
    check('taking it puts it under review, assigned', $c['status'] === 'reviewing' && $c['assignedTo'] === STAFF['email']);

    check('an empty note is refused', refused(fn() => $moderation->note($complaintId, '   ', STAFF)) === 'note');
    $moderation->note($complaintId, 'Checked their chat', STAFF);
    check('a note is kept', count($moderation->complaint($complaintId)['notes']) === 1);

    // Resolving, with a sanction tied to it.
    check('resolving needs a resolution', refused(fn() => $moderation->resolve($complaintId, [], STAFF)) === 'resolution');
    $before = $levels->effectiveLevel(SUBJECT, 'guide');
    $moderation->resolve($complaintId, ['resolution' => 'Warned', 'sanction' => ['levels' => 1, 'days' => 7]], STAFF);
    $c = $moderation->complaint($complaintId);
    check('resolved, by whom', $c['status'] === 'resolved' && $c['resolvedBy'] === STAFF['email']);
    check('the sanction is tied to the complaint', count($c['sanctions']) === 1 && $c['sanctions'][0]['levels'] === 1);
    check('…and lowers their level', $levels->effectiveLevel(SUBJECT, 'guide') === max(0, $before - 1));
    check('the reporter was told, through the API', $c['reporterToldAt'] !== null && $told() === $toldBefore + 1);
    check('closing twice is refused', is_string(refused(fn() => $moderation->dismiss($complaintId, 'x', STAFF))));

    // Reopen, then dismiss — the reporter isn't told again.
    $moderation->reopen($complaintId, STAFF);
    check('reopened, under review again', $moderation->complaint($complaintId)['status'] === 'reviewing');
    check('dismissing needs a reason', refused(fn() => $moderation->dismiss($complaintId, '', STAFF)) === 'resolution');
    $moderation->dismiss($complaintId, 'Not enough evidence after all', STAFF);
    check('dismissed', $moderation->complaint($complaintId)['status'] === 'dismissed');
    check('the reporter isn’t told twice', $told() === $toldBefore + 1);

    // Linked accounts.
    $links = $moderation->links([]);
    check('the link is waiting for review', in_array($linkId, array_column($links['links'], 'id'), true) && $links['unreviewed'] >= 1);
    check('a made-up verdict is refused', refused(fn() => $moderation->verdict($linkId, 'maybe', '', STAFF)) === 'verdict');
    $moderation->verdict($linkId, 'different_people', 'Siblings (probe)', STAFF);
    $reviewed = array_column($moderation->links(['status' => 'reviewed'])['links'], null, 'id')[$linkId] ?? null;
    check('verdict kept, with who and the note', $reviewed !== null && $reviewed['verdict'] === 'different_people'
        && $reviewed['reviewedBy'] === STAFF['email'] && $reviewed['note'] === 'Siblings (probe)');
    check('…and it left the to-review list', !in_array($linkId, array_column($moderation->links([])['links'], 'id'), true));
} finally {
    $db->db_Execute('DELETE FROM `user_sanctions` WHERE `complaint_id` = ' . $complaintId);
    $db->db_Execute('DELETE FROM `complaints` WHERE `id` = ' . $complaintId);
    $db->db_Execute('DELETE FROM `account_links` WHERE `id` = ' . $linkId);
    $db->db_Execute('DELETE FROM `notifications` WHERE `user_id` = ' . REPORTER . ' AND `kind` = "complaint_reviewed" AND `created_at` > UTC_TIMESTAMP() - INTERVAL 10 MINUTE');
    $db->db_Execute('DELETE FROM `email_outbox` WHERE `status` = "queued" AND `recipient` LIKE "%@example.test"');
}

echo $failures ? "{$failures} FAILED\n" : "ALL PASSED\n";
exit($failures ? 1 : 0);
