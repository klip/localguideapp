<?php

/**
 * The CMS's Moderation section (docs/auth-cms-plan.md, phase 9): the
 * complaints queue and linked-accounts review, for managers and admins
 * (`disputes.resolve`).
 *
 * Complaints (`complaints`, filed by the app's Report button, 037):
 *   open → reviewing (someone took it) → resolved | dismissed, reopenable.
 *   Resolving needs a resolution and may sanction the person reported, tied
 *   to the complaint (`user_sanctions.complaint_id`). Closing one tells the
 *   reporter it was reviewed — never the outcome. Staff notes
 *   (`complaint_notes`) are staff-only. The pair's chat is shown only on
 *   request, and every look is in the audit log (`CmsApi`).
 *
 * Linked accounts (`account_links`, 074 — same browser, same address and
 * fingerprint, or same fingerprint): reviewed strongest first, each given a
 * verdict — same person, different people, unsure — with an optional note.
 *
 * Every method that changes something returns `[$before, $after]` for the
 * audit log; field errors are `{ field, error }` JSON.
 */
final class Moderation
{
    public const PAGE_SIZE = 50;
    private const STATUSES = ['open', 'reviewing', 'resolved', 'dismissed'];
    private const VERDICTS = ['same_person', 'different_people', 'unsure'];

    /** @var db */
    private $db;
    /** @var UserEditor */
    private $editor;
    /** @var string */
    private $apiUrl;

    public function __construct(db $db, UserEditor $editor, ?string $apiUrl = null)
    {
        $this->db = $db;
        $this->editor = $editor;
        $this->apiUrl = $apiUrl ?? (getenv('API_URL') ?: 'http://nginx:8081');
    }

    // ------------------------------------------------------------- complaints

    /**
     * One page of complaints. `status`: active (open + reviewing, the
     * default — oldest first, so nothing waits forever) | open | reviewing |
     * resolved | dismissed | all (newest first); `subjectId` narrows to one
     * person; `page` from 1. Also the count per status.
     */
    public function complaints(array $input): array
    {
        $status = (string) ($input['status'] ?? 'active');
        $where = ['1'];
        if ($status === 'active') {
            $where[] = 'c.`status` IN ("open", "reviewing")';
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'c.`status` = "' . $status . '"';
        } elseif ($status !== 'all') {
            throw new RuntimeException('Unknown status.');
        }
        if (!empty($input['subjectId'])) {
            $where[] = 'c.`subject_id` = ' . (int) $input['subjectId'];
        }
        $order = in_array($status, ['active', 'open', 'reviewing'], true) ? 'c.`id` ASC' : 'c.`id` DESC';
        $page = max(1, (int) ($input['page'] ?? 1));
        $from = ' FROM `complaints` c
            LEFT JOIN `users` r ON r.`id` = c.`reporter_id`
            LEFT JOIN `users` s ON s.`id` = c.`subject_id`
            WHERE ' . implode(' AND ', $where);

        $rows = (array) $this->db->db_GetArray(
            'SELECT c.*, r.`name` AS `reporter_name`, s.`name` AS `subject_name`,
                    (SELECT COUNT(*) FROM `complaints` c2 WHERE c2.`subject_id` = c.`subject_id`) AS `about_subject`,
                    (SELECT COUNT(*) FROM `complaint_notes` n WHERE n.`complaint_id` = c.`id`) AS `notes`,
                    ' . Freezes::isFrozenSql('c.`subject_id`') . ' AS `subject_frozen`'
            . $from . ' ORDER BY ' . $order . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ((array) $this->db->db_GetArray('SELECT `status`, COUNT(*) AS `n` FROM `complaints` GROUP BY `status`') as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return [
            'complaints' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'status' => (string) $r['status'],
                'createdAt' => self::iso($r['created_at']),
                'reporter' => self::person($r['reporter_id'], $r['reporter_name']),
                'subject' => self::person($r['subject_id'], $r['subject_name']) + ['frozen' => (bool) $r['subject_frozen']],
                'aboutSubject' => (int) $r['about_subject'],
                'notes' => (int) $r['notes'],
                'assignedTo' => $r['assigned_email'],
            ], $rows),
            'total' => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n`' . $from, 'n'),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'counts' => $counts,
        ];
    }

    /** One complaint with both people, what's between them, its notes and sanctions. */
    public function complaint(int $id): array
    {
        $c = $this->find($id);
        $reporter = $c['reporter_id'] !== null ? (int) $c['reporter_id'] : null;
        $subject = $c['subject_id'] !== null ? (int) $c['subject_id'] : null;

        return [
            'id' => $id,
            'title' => (string) $c['title'],
            'description' => (string) $c['description'],
            'status' => (string) $c['status'],
            'resolution' => $c['resolution'],
            'createdAt' => self::iso($c['created_at']),
            'assignedTo' => $c['assigned_email'],
            'assignedAt' => self::iso($c['assigned_at']),
            'resolvedAt' => self::iso($c['resolved_at']),
            'resolvedBy' => $c['resolved_by'],
            'reporterToldAt' => self::iso($c['reporter_told_at']),
            'reporter' => $reporter ? $this->brief($reporter) : null,
            'subject' => $subject ? $this->brief($subject) : null,
            'between' => $reporter && $subject ? $this->between($reporter, $subject) : null,
            'dispute' => $this->dispute($c),
            'notes' => array_map(fn(array $n): array => [
                'id' => (int) $n['id'],
                'body' => (string) $n['body'],
                'by' => (string) $n['staff_email'],
                'createdAt' => self::iso($n['created_at']),
            ], (array) $this->db->db_GetArray('SELECT * FROM `complaint_notes` WHERE `complaint_id` = ' . $id . ' ORDER BY `id`')),
            'sanctions' => array_map(fn(array $s): array => [
                'id' => (int) $s['id'],
                'levels' => (int) $s['levels'],
                'reason' => (string) $s['reason'],
                'expiresAt' => self::iso($s['expires_at']),
                'liftedAt' => self::iso($s['lifted_at']),
                'by' => (string) $s['staff_email'],
            ], (array) $this->db->db_GetArray('SELECT * FROM `user_sanctions` WHERE `complaint_id` = ' . $id . ' ORDER BY `id`')),
        ];
    }

    /** The last 100 chat messages between the complaint's two people (both directions), oldest first. */
    public function messages(int $id): array
    {
        $c = $this->find($id);
        if ($c['reporter_id'] === null || $c['subject_id'] === null) {
            return ['messages' => []];
        }
        [$a, $b] = [(int) $c['reporter_id'], (int) $c['subject_id']];
        $rows = (array) $this->db->db_GetArray(
            'SELECT * FROM (
                SELECT `id`, `sender_id`, `kind`, `body`, `latitude`, `longitude`, `created_at` FROM `messages`
                WHERE (`sender_id` = ' . $a . ' AND `recipient_id` = ' . $b . ') OR (`sender_id` = ' . $b . ' AND `recipient_id` = ' . $a . ')
                ORDER BY `id` DESC LIMIT 100
             ) m ORDER BY `id`'
        );

        return ['messages' => array_map(static fn(array $m): array => [
            'id' => (int) $m['id'],
            'from' => (int) $m['sender_id'] === $a ? 'reporter' : 'subject',
            'kind' => (string) $m['kind'],
            'body' => $m['body'],
            'location' => $m['kind'] === 'location' ? ['latitude' => (float) $m['latitude'], 'longitude' => (float) $m['longitude']] : null,
            'createdAt' => self::iso($m['created_at']),
        ], $rows)];
    }

    /** "I'll look at this": open → reviewing, taken by `$staff`. */
    public function take(int $id, array $staff): array
    {
        $c = $this->find($id);
        if (!in_array($c['status'], ['open', 'reviewing'], true)) {
            throw new RuntimeException('This complaint is closed — reopen it first.');
        }
        $this->exec(
            'UPDATE `complaints` SET `status` = "reviewing", `assigned_staff_id` = ' . (int) $staff['id']
            . ', `assigned_email` = ' . $this->quote((string) $staff['email']) . ', `assigned_at` = UTC_TIMESTAMP() WHERE `id` = ' . $id
        );

        return [['status' => $c['status'], 'assignedTo' => $c['assigned_email']], ['status' => 'reviewing', 'assignedTo' => $staff['email']]];
    }

    public function note(int $id, string $body, array $staff): array
    {
        $this->find($id);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 2000) {
            throw self::fieldError('note', 'A note of up to 2,000 characters.');
        }
        $this->exec(
            'INSERT INTO `complaint_notes` (`complaint_id`, `staff_id`, `staff_email`, `body`) VALUES ('
            . $id . ', ' . (int) $staff['id'] . ', ' . $this->quote((string) $staff['email']) . ', ' . $this->quote($body) . ')'
        );

        return [null, ['note' => (int) $this->db->db_GetLastId()]];
    }

    /**
     * Closes the complaint as resolved with `resolution` (required), and
     * optionally sanctions the person reported (`sanction: { levels, days? }`),
     * tied to this complaint. The reporter is told it was reviewed.
     */
    public function resolve(int $id, array $input, array $staff): array
    {
        $c = $this->find($id);
        $resolution = $this->closingText($input['resolution'] ?? '', 'resolution', 'What was done — kept with the complaint.');
        $dispute = $this->dispute($c);
        $refund = null;
        if ($dispute && $dispute['open']) {
            // A trip dispute: the refund is decided here, by the API (it owns bookings).
            $raw = $input['refund'] ?? null;
            if (!is_int($raw) && !is_float($raw) || $raw < 0 || $raw > $dispute['amount'] + 0.001) {
                throw self::fieldError('refund', 'Decide the refund: from 0 (the trip stands) to £' . number_format($dispute['amount'], 2) . '.');
            }
            $refund = round((float) $raw, 2);
            [$status, $body] = ServiceHttp::call($this->apiUrl, [
                'action' => 'staffSettleDispute',
                'bookingId' => $dispute['bookingId'],
                'refund' => $refund,
                'note' => $resolution,
            ]);
            if ($status !== 200) {
                throw new RuntimeException((string) ($body['error'] ?? 'The app refused (' . $status . ').'));
            }
        }
        $sanction = $input['sanction'] ?? null;
        if (is_array($sanction) && !empty($sanction['levels'])) {
            if ($c['subject_id'] === null) {
                throw self::fieldError('sanction', 'Their account is gone; there’s no one to sanction.');
            }
            $this->editor->addSanction((int) $c['subject_id'], [
                'levels' => $sanction['levels'],
                'days' => $sanction['days'] ?? null,
                'reason' => mb_substr('Complaint #' . $id . ': ' . $c['title'], 0, 255),
                'complaintId' => $id,
            ], $staff);
        }
        $this->close($c, 'resolved', $resolution, $staff);

        return [['status' => $c['status']], ['status' => 'resolved', 'resolution' => $resolution, 'sanction' => $sanction, 'refund' => $refund]];
    }

    /** Closes it as not needing action, with a reason. The reporter is told it was reviewed. */
    public function dismiss(int $id, string $reason, array $staff): array
    {
        $c = $this->find($id);
        $dispute = $this->dispute($c);
        if ($dispute && $dispute['open']) {
            throw new RuntimeException('A trip dispute needs a decision — resolve it with a refund (0 if the trip stands).');
        }
        $reason = $this->closingText($reason, 'resolution', 'Why — kept with the complaint.');
        $this->close($c, 'dismissed', $reason, $staff);

        return [['status' => $c['status']], ['status' => 'dismissed', 'resolution' => $reason]];
    }

    /** Back to "reviewing" (taken by `$staff`) — new evidence, a wrong call. */
    public function reopen(int $id, array $staff): array
    {
        $c = $this->find($id);
        if (!in_array($c['status'], ['resolved', 'dismissed'], true)) {
            throw new RuntimeException('It isn’t closed.');
        }
        $this->exec(
            'UPDATE `complaints` SET `status` = "reviewing", `resolved_at` = NULL, `resolved_by` = NULL,
                    `assigned_staff_id` = ' . (int) $staff['id'] . ', `assigned_email` = ' . $this->quote((string) $staff['email']) . ',
                    `assigned_at` = UTC_TIMESTAMP() WHERE `id` = ' . $id
        );

        return [['status' => $c['status'], 'resolution' => $c['resolution']], ['status' => 'reviewing']];
    }

    // ------------------------------------------------------------- linked accounts

    /**
     * Linked-account pairs. `status`: unreviewed (default — strongest and
     * most seen first) | reviewed | all; `page` from 1.
     */
    public function links(array $input): array
    {
        $status = (string) ($input['status'] ?? 'unreviewed');
        $where = $status === 'unreviewed' ? 'l.`reviewed_at` IS NULL'
            : ($status === 'reviewed' ? 'l.`reviewed_at` IS NOT NULL' : '1');
        $page = max(1, (int) ($input['page'] ?? 1));
        $order = $status === 'reviewed' ? 'l.`reviewed_at` DESC' : 'l.`strength` DESC, l.`hits` DESC, l.`last_seen_at` DESC';
        $rows = (array) $this->db->db_GetArray(
            'SELECT l.* FROM `account_links` l WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );

        return [
            'links' => array_map(fn(array $l): array => [
                'id' => (int) $l['id'],
                'a' => $this->brief((int) $l['user_a']),
                'b' => $this->brief((int) $l['user_b']),
                'signal' => (string) $l['signal'],
                'strength' => (int) $l['strength'],
                'hits' => (int) $l['hits'],
                'firstSeenAt' => self::iso($l['first_seen_at']),
                'lastSeenAt' => self::iso($l['last_seen_at']),
                'verdict' => $l['verdict'],
                'reviewedAt' => self::iso($l['reviewed_at']),
                'reviewedBy' => $l['reviewed_by'],
                'note' => $l['review_note'],
            ], $rows),
            'total' => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n` FROM `account_links` l WHERE ' . $where, 'n'),
            'unreviewed' => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n` FROM `account_links` WHERE `reviewed_at` IS NULL', 'n'),
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    public function verdict(int $linkId, string $verdict, string $note, array $staff): array
    {
        $link = $this->db->db_GetRow('SELECT * FROM `account_links` WHERE `id` = ' . $linkId);
        if (!$link) {
            throw new RuntimeException('No such link.');
        }
        if (!in_array($verdict, self::VERDICTS, true)) {
            throw self::fieldError('verdict', 'Same person, different people, or unsure.');
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw self::fieldError('note', 'At most 500 characters.');
        }
        $this->exec(
            'UPDATE `account_links` SET `verdict` = "' . $verdict . '", `reviewed_at` = UTC_TIMESTAMP(), `reviewed_by` = '
            . $this->quote((string) $staff['email']) . ', `review_note` = ' . $this->quote($note === '' ? null : $note) . ' WHERE `id` = ' . $linkId
        );

        return [
            ['link' => $linkId, 'verdict' => $link['verdict']],
            ['link' => $linkId, 'users' => [(int) $link['user_a'], (int) $link['user_b']], 'verdict' => $verdict, 'note' => $note ?: null],
        ];
    }

    // ------------------------------------------------------------- internals

    private function find(int $id): array
    {
        $c = $id > 0 ? $this->db->db_GetRow('SELECT * FROM `complaints` WHERE `id` = ' . $id) : null;
        if (!$c) {
            throw new RuntimeException('No such complaint.');
        }

        return $c;
    }

    private function close(array $c, string $status, string $text, array $staff): void
    {
        if (!in_array($c['status'], ['open', 'reviewing'], true)) {
            throw new RuntimeException('This complaint is closed already.');
        }
        $this->exec(
            'UPDATE `complaints` SET `status` = "' . $status . '", `resolution` = ' . $this->quote($text)
            . ', `resolved_at` = UTC_TIMESTAMP(), `resolved_by` = ' . $this->quote((string) $staff['email'])
            . ', `assigned_staff_id` = COALESCE(`assigned_staff_id`, ' . (int) $staff['id'] . ')'
            . ', `assigned_email` = COALESCE(`assigned_email`, ' . $this->quote((string) $staff['email']) . ')'
            . ' WHERE `id` = ' . (int) $c['id']
        );
        $this->tellReporter((int) $c['id']);
    }

    /** Asks the API to tell the reporter it was reviewed (it owns notifications). Best-effort, once. */
    private function tellReporter(int $id): void
    {
        try {
            [$status, $body] = ServiceHttp::call($this->apiUrl, ['action' => 'staffComplaintReviewed', 'complaintId' => $id]);
            if ($status !== 200) {
                Log::warning('Reporter not told', ['complaint_id' => $id, 'status' => $status, 'error' => $body['error'] ?? null]);
            }
        } catch (RuntimeException $e) {
            Log::warning('Reporter not told', ['complaint_id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** A person in a complaint or link: who, and what matters for moderating them. */
    private function brief(int $userId): ?array
    {
        $u = $this->db->db_GetRow('SELECT `id`, `name`, `email`, `image`, `created_at`, `verified_at` FROM `users` WHERE `id` = ' . $userId);
        if (!$u) {
            return null;
        }

        return [
            'id' => $userId,
            'name' => (string) ($u['name'] ?? ''),
            'email' => (string) $u['email'],
            'image' => $u['image'] ?: null,
            'roles' => Roles::of($userId, $this->db),
            'createdAt' => self::iso($u['created_at']),
            'verified' => $u['verified_at'] !== null,
            'freeze' => Freezes::activeFor($this->db, $userId),
            'complaintsAbout' => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n` FROM `complaints` WHERE `subject_id` = ' . $userId, 'n'),
            'complaintsBy' => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n` FROM `complaints` WHERE `reporter_id` = ' . $userId, 'n'),
            'activeSanctions' => (int) $this->db->db_GetFieldFromQuery(
                'SELECT COALESCE(SUM(`levels`), 0) AS `n` FROM `user_sanctions` WHERE `user_id` = ' . $userId
                . ' AND `lifted_at` IS NULL AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())',
                'n'
            ),
        ];
    }

    /**
     * A trip dispute (`complaints.booking_id`, opened by the app when a
     * started trip's cancellation wasn't agreed): the booking, and whether
     * it still waits for a refund decision.
     */
    private function dispute(array $c): ?array
    {
        if (empty($c['booking_id'])) {
            return null;
        }
        $b = $this->db->db_GetRowById((int) $c['booking_id'], 'bookings');
        if (!$b) {
            return null;
        }

        return [
            'bookingId' => (int) $b['id'],
            'open' => $b['disputed_at'] !== null && $b['status'] === 'confirmed',
            'amount' => (float) $b['amount'],
            'paymentStatus' => (string) $b['payment_status'],
            'status' => (string) $b['status'],
            'refund' => $b['refund_amount'] !== null ? (float) $b['refund_amount'] : null,
            'meetingAt' => self::iso($b['meeting_at']),
            'startedAt' => self::iso($b['started_at']),
            'guestReason' => $b['cancel_request_reason'],
            'disputedAt' => self::iso($b['disputed_at']),
        ];
    }

    /** What's between two people: their pairs (either way round), bookings, and how much they've written. */
    private function between(int $a, int $b): array
    {
        $pairs = '((s.`guest_id` = ' . $a . ' AND s.`guide_id` = ' . $b . ') OR (s.`guest_id` = ' . $b . ' AND s.`guide_id` = ' . $a . '))';

        return [
            'matches' => array_map(static fn(array $s): array => [
                'guestId' => (int) $s['guest_id'],
                'guideId' => (int) $s['guide_id'],
                'active' => (bool) $s['active'],
                'guestDecision' => $s['guest_decision'],
                'guideDecision' => $s['guide_decision'],
                'matchedAt' => self::iso($s['matched_at']),
            ], (array) $this->db->db_GetArray('SELECT s.* FROM `selections` s WHERE ' . $pairs)),
            'bookings' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'],
                'guestId' => (int) $r['guest_id'],
                'status' => (string) $r['status'],
                'tripStatus' => (string) $r['trip_status'],
                'paymentStatus' => (string) $r['payment_status'],
                'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
                'refund' => $r['refund_amount'] !== null ? (float) $r['refund_amount'] : null,
                'meetingAt' => self::iso($r['meeting_at']),
                'cancelReason' => $r['cancel_reason'],
            ], (array) $this->db->db_GetArray(
                'SELECT b.* FROM `bookings` b JOIN `selections` s ON s.`id` = b.`selection_id` WHERE ' . $pairs . ' ORDER BY b.`id` DESC'
            )),
            'messageCount' => (int) $this->db->db_GetFieldFromQuery(
                'SELECT COUNT(*) AS `n` FROM `messages` WHERE (`sender_id` = ' . $a . ' AND `recipient_id` = ' . $b . ') OR (`sender_id` = ' . $b . ' AND `recipient_id` = ' . $a . ')',
                'n'
            ),
        ];
    }

    private function closingText($text, string $field, string $message): string
    {
        $text = trim((string) $text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw self::fieldError($field, $message . ' Up to 2,000 characters.');
        }

        return $text;
    }

    private static function person($id, $name): ?array
    {
        return $id === null ? null : ['id' => (int) $id, 'name' => (string) ($name ?? '')];
    }

    private static function iso($datetime): ?string
    {
        return $datetime === null || $datetime === '' ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($datetime . ' UTC'));
    }

    private function exec(string $sql): void
    {
        if (!$this->db->db_Execute($sql)) {
            throw new RuntimeException('Could not save that.');
        }
    }

    /** @param mixed $value */
    private function quote($value): string
    {
        return $value === null ? 'NULL' : '"' . $this->db->db_Escape((string) $value) . '"';
    }

    private static function fieldError(string $field, string $error): RuntimeException
    {
        return new RuntimeException((string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
