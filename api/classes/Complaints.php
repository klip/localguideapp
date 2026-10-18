<?php

/**
 * Complaints — one user reporting another (`complaints`, see its migration):
 * the "Report" button on both public profile pages files one here.
 *
 *   - Signed in, about a guest or guide who isn't yourself.
 *   - A title (≤ `MAX_TITLE`) and a description (≤ `MAX_DESCRIPTION`), both
 *     required. Not run through `ContactFilter`: only moderators read it, and
 *     a phone number someone was pestered from is evidence.
 *   - At most one open complaint per reporter about the same person (a second
 *     one is refused with a friendly message, not stored twice), and at most
 *     `DAILY_LIMIT` per reporter a day, so the table can't be flooded.
 *   - `COMPLAINTS_EMAIL` (env, optional) gets an email per complaint — the
 *     moderators' inbox until there's an admin screen. Without it, the
 *     complaint is only stored and logged.
 *
 * The person reported isn't told. The outcome is recorded by moderators in
 * `status`/`resolution` (SQL for now).
 */
class Complaints
{
    public const MAX_TITLE = 120;
    public const MAX_DESCRIPTION = 2000;
    private const DAILY_LIMIT = 5;

    /** @var db */
    private $db;
    /** @var MailQueue */
    private $mail;

    public function __construct(db $db, MailQueue $mail)
    {
        $this->db = $db;
        $this->mail = $mail;
    }

    /** Files `$reporterId`'s complaint about `$subjectId`; returns `{ id, status, created_at }`. */
    public function file(int $reporterId, int $subjectId, $title, $description): array
    {
        if (!is_string($title) || !is_string($description)) {
            throw new RuntimeException('A title and a description are required.');
        }
        if (!mb_check_encoding($title . $description, 'UTF-8')) {
            throw new RuntimeException('The report must be valid text.');
        }
        // Control and formatting characters out (the description keeps its
        // line breaks and tabs); the title is one line.
        $title = trim((string) preg_replace(['/[\p{Cc}\p{Cf}]/u', '/\s+/u'], [' ', ' '], $title));
        $description = trim((string) preg_replace('/(?![\n\t])[\p{Cc}\p{Cf}]/u', '', str_replace("\r\n", "\n", $description)));
        if ($title === '' || $description === '') {
            throw new RuntimeException('A title and a description are required.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new RuntimeException('Keep the title to ' . self::MAX_TITLE . ' characters.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new RuntimeException('Keep the description to ' . self::MAX_DESCRIPTION . ' characters.');
        }
        if ($subjectId === $reporterId) {
            throw new RuntimeException('You can’t report yourself.');
        }

        $subject = $this->db->db_GetRow(
            'SELECT u.`id`, u.`name`, ' . Roles::nameSql('u.`id`') . ' AS `role` FROM `users` u
             WHERE u.`id` = ' . $subjectId . ' HAVING `role` IS NOT NULL'
        );
        if (!$subject) {
            throw new RuntimeException('Profile not found.');
        }

        // One reporter at a time, so a double submit can't slip two
        // complaints past the checks below.
        $lock = 'complaint:' . $reporterId;
        if (!(int) $this->db->db_GetFieldFromQuery('SELECT GET_LOCK("' . $lock . '", 5) AS `ok`', 'ok')) {
            throw new RuntimeException('Could not send the report. Please try again.');
        }
        try {
            $id = $this->insertChecked($reporterId, $subjectId, $title, $description);
        } finally {
            $this->db->db_GetFieldFromQuery('SELECT RELEASE_LOCK("' . $lock . '") AS `ok`', 'ok');
        }

        Log::info('Complaint filed', ['complaint_id' => $id, 'subject_id' => $subjectId]);
        $this->emailModerators($id, $reporterId, $subject, $title, $description);

        $row = $this->db->db_GetRow(
            'SELECT `id`, `status`, UNIX_TIMESTAMP(`created_at`) AS `ts` FROM `complaints` WHERE `id` = ' . $id
        );

        return [
            'id' => (int) $row['id'],
            'status' => $row['status'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['ts']),
        ];
    }

    /** The duplicate and daily-limit checks and the insert — run under the reporter's lock. */
    private function insertChecked(int $reporterId, int $subjectId, string $title, string $description): int
    {
        $open = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS `n` FROM `complaints`
             WHERE `reporter_id` = ' . $reporterId . ' AND `subject_id` = ' . $subjectId . '
               AND `status` IN ("open", "reviewing")',
            'n'
        );
        if ($open > 0) {
            throw new RuntimeException('You’ve already reported this person — we’re looking into it.');
        }
        $today = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS `n` FROM `complaints`
             WHERE `reporter_id` = ' . $reporterId . ' AND `created_at` > UTC_TIMESTAMP() - INTERVAL 1 DAY',
            'n'
        );
        if ($today >= self::DAILY_LIMIT) {
            throw new RuntimeException('You’ve sent several reports today. Please try again tomorrow.');
        }

        $id = $this->db->db_Insert('complaints', [
            'reporter_id' => $reporterId,
            'subject_id' => $subjectId,
            'title' => $title,
            'description' => $description,
        ]);
        if (!$id) {
            throw new RuntimeException('Could not send the report. Please try again.');
        }

        return (int) $id;
    }

    private function emailModerators(int $id, int $reporterId, array $subject, string $title, string $description): void
    {
        $to = trim((string) (getenv('COMPLAINTS_EMAIL') ?: ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        // At most one email per reported person every 6 hours, counted from
        // the emails actually sent (`notified_at`): a pile-on from many
        // accounts is stored in full but doesn't flood the inbox. Claimed
        // under a per-subject lock, so two reports at once send one email.
        $subjectId = (int) $subject['id'];
        $lock = 'complaint-subject:' . $subjectId;
        if (!(int) $this->db->db_GetFieldFromQuery('SELECT GET_LOCK("' . $lock . '", 5) AS `ok`', 'ok')) {
            return;
        }
        try {
            $recent = (int) $this->db->db_GetFieldFromQuery(
                'SELECT COUNT(*) AS `n` FROM `complaints`
                 WHERE `subject_id` = ' . $subjectId . ' AND `notified_at` > UTC_TIMESTAMP() - INTERVAL 6 HOUR',
                'n'
            );
            if ($recent > 0) {
                return;
            }
            $this->db->db_Execute('UPDATE `complaints` SET `notified_at` = UTC_TIMESTAMP() WHERE `id` = ' . $id);
        } finally {
            $this->db->db_GetFieldFromQuery('SELECT RELEASE_LOCK("' . $lock . '") AS `ok`', 'ok');
        }
        $this->mail->enqueue(
            $to,
            "Complaint #{$id}: {$title}",
            "Complaint #{$id}\n"
            . "Reported: user #{$subject['id']} ({$subject['role']}" . ($subject['name'] ? ", {$subject['name']}" : '') . ")\n"
            . "By: user #{$reporterId}\n\n"
            . "{$title}\n\n{$description}\n\n"
            . "Resolve it in the complaints table (status, resolution, resolved_at).",
            'complaint'
        );
    }
}
