<?php

/**
 * Tells a user something happened: an in-app notification (`notifications`)
 * plus, for most kinds, a message on their contact channel. The row is
 * pushed at once through Redis (`Push`) and marked read if some gateway took
 * it; a user who isn't connected gets their unread rows when they next sign
 * in on a socket (`pushState`). The client shows each as a toast (`App.vue`).
 *
 * The contact channel is email for now, queued for the mail worker
 * (`MailQueue`). When SMS/WhatsApp delivery exists, `deliver()` is the one
 * place to choose by the user's preferred channel.
 */
class Notifications
{
    /** @var db */
    private $db;
    /** @var MailQueue */
    private $mail;
    /** @var Push */
    private $push;

    public function __construct(db $db, MailQueue $mail, Push $push)
    {
        $this->db = $db;
        $this->mail = $mail;
        $this->push = $push;
    }

    /**
     * Records the in-app notification and sends the email. `$inApp` is the
     * short line the toast shows; `$subject`/`$body` are the email.
     * `$aboutUserId` is the person it's about, if one — the toast links there.
     * `$role` is the recipient's side in it (an account can hold both): the
     * link opens the app in that mode.
     */
    public function notify(int $userId, string $kind, string $inApp, string $subject, string $body, ?int $aboutUserId = null, ?string $role = null): void
    {
        $this->record($userId, $kind, mb_substr($inApp, 0, 512), $aboutUserId, $role);
        $this->deliver($userId, $kind, $subject, $body);
    }

    /**
     * Email only, no in-app row — for something the app already shows its
     * own way (a chat message: the Messages counter and the live push).
     */
    public function emailOnly(int $userId, string $kind, string $subject, string $body): void
    {
        $this->deliver($userId, $kind, $subject, $body);
    }

    /**
     * In-app only, no email — for things worth a toast but not an inbox
     * (a match updating their profile). An identical message that's still
     * unread, or went out in the last 10 minutes, isn't added again — a few
     * saves in a row leave the other person one line, not several.
     */
    public function notifyInApp(int $userId, string $kind, string $inApp, ?int $aboutUserId = null, ?string $role = null): void
    {
        $message = mb_substr($inApp, 0, 512);
        $waiting = $this->db->db_GetRow(
            'SELECT `id` FROM `notifications`
             WHERE `user_id` = ' . $userId . '
               AND (`read_at` IS NULL OR `created_at` >= NOW() - INTERVAL 10 MINUTE)
               AND `kind` = "' . $this->db->db_Escape($kind) . '"
               AND `message` = "' . $this->db->db_Escape($message) . '"'
        );
        if ($waiting) {
            return;
        }

        $this->record($userId, $kind, $message, $aboutUserId, $role);
    }

    /**
     * Unread notifications for several users, oldest first, marked read in
     * the same call — the backlog a gateway sends when a user signs in.
     *
     * @param int[] $userIds
     * @return array<int, array<int, array{id: int, kind: string, about_user_id: ?int, role: ?string, message: string, created_at: string}>> by user id
     */
    public function takeUnreadFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) {
            return [];
        }

        $rows = $this->db->db_GetArray(
            'SELECT `id`, `user_id`, `kind`, `about_user_id`, `role`, `message`, `created_at` FROM `notifications`
             WHERE `user_id` IN (' . implode(',', $ids) . ') AND `read_at` IS NULL
             ORDER BY `id` ASC LIMIT 200'
        );
        if (!$rows) {
            return [];
        }

        $this->db->db_Execute(
            'UPDATE `notifications` SET `read_at` = UTC_TIMESTAMP()
             WHERE `id` IN (' . implode(',', array_map(static function ($row) {
                return (int) $row['id'];
            }, $rows)) . ')'
        );

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['user_id']][] = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'about_user_id' => $row['about_user_id'] === null ? null : (int) $row['about_user_id'],
                'role' => $row['role'] ?: null,
                'message' => $row['message'],
                'created_at' => $row['created_at'],
            ];
        }

        return $byUser;
    }

    /**
     * Stores the row and pushes it; if a gateway took it, it's read (the
     * user has it on screen), otherwise it waits for their next sign-in.
     * Their counters are refreshed at the end of the request either way.
     */
    private function record(int $userId, string $kind, string $message, ?int $aboutUserId = null, ?string $role = null): void
    {
        $role = in_array($role, Roles::APP_ROLES, true) ? $role : null;
        $fields = ['user_id' => $userId, 'kind' => $kind, 'message' => $message];
        if ($aboutUserId) {
            $fields['about_user_id'] = $aboutUserId;
        }
        if ($role) {
            $fields['role'] = $role;
        }
        $id = (int) $this->db->db_Insert('notifications', $fields);
        $this->push->touch($userId);
        if (!$id) {
            return;
        }

        $delivered = $this->push->toUser($userId, ['type' => 'notification', 'notification' => [
            'id' => $id,
            'kind' => $kind,
            'about_user_id' => $aboutUserId ?: null,
            'role' => $role,
            'message' => $message,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]]);
        if ($delivered > 0) {
            $this->db->db_Execute('UPDATE `notifications` SET `read_at` = UTC_TIMESTAMP() WHERE `id` = ' . $id);
        }
    }

    private function deliver(int $userId, string $kind, string $subject, string $body): void
    {
        $email = $this->db->db_GetFieldFromQuery('SELECT `email` FROM `users` WHERE `id` = ' . $userId, 'email');
        if ($email) {
            $this->mail->enqueue((string) $email, $subject, $body, $kind);
        }
    }
}
