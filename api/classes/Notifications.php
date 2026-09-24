<?php

/**
 * Tells a user something happened while they weren't looking: an in-app
 * notification (`notifications`, shown as a toast next time the client asks —
 * see `App.vue`) plus a message on their contact channel.
 *
 * The contact channel is email for now. When SMS/WhatsApp delivery exists,
 * `deliver()` is the one place to choose by the user's preferred channel.
 */
class Notifications
{
    /** @var db */
    private $db;
    /** @var Mailer */
    private $mailer;

    public function __construct(db $db, Mailer $mailer)
    {
        $this->db = $db;
        $this->mailer = $mailer;
    }

    /**
     * Records the in-app notification and sends the email. `$inApp` is the
     * short line the toast shows; `$subject`/`$body` are the email.
     */
    public function notify(int $userId, string $kind, string $inApp, string $subject, string $body): void
    {
        $this->db->db_Insert('notifications', [
            'user_id' => $userId,
            'kind' => $kind,
            'message' => mb_substr($inApp, 0, 512),
        ]);

        $this->deliver($userId, $subject, $body);
    }

    /**
     * Unread notifications for a user, oldest first, marked read in the same
     * call — the client shows each one once, as a toast.
     *
     * @return array<int, array{id: int, kind: string, message: string, created_at: string}>
     */
    public function takeUnread(int $userId): array
    {
        $rows = $this->db->db_GetArray(
            'SELECT `id`, `kind`, `message`, `created_at` FROM `notifications`
             WHERE `user_id` = ' . $userId . ' AND `read_at` IS NULL
             ORDER BY `id` ASC LIMIT 20'
        );

        if ($rows) {
            $ids = array_map(static function ($row) {
                return (int) $row['id'];
            }, $rows);
            $this->db->db_Execute(
                'UPDATE `notifications` SET `read_at` = UTC_TIMESTAMP()
                 WHERE `user_id` = ' . $userId . ' AND `id` IN (' . implode(',', $ids) . ')'
            );
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return $rows;
    }

    private function deliver(int $userId, string $subject, string $body): void
    {
        $email = $this->db->db_GetFieldFromQuery('SELECT `email` FROM `users` WHERE `id` = ' . $userId, 'email');
        if ($email) {
            $this->mailer->send((string) $email, $subject, $body);
        }
    }
}
