<?php

/**
 * Direct chat between a matched guest and guide (`messages`, 030) — the
 * channel that replaces swapping phone numbers, so a trip can be arranged
 * without leaving RockGuide.
 *
 *   - A pair can write to each other while their match is active
 *     (`selections.active = 1`). Once it dissolves (unmatch, a cancelled
 *     trip) the history stays readable but nobody can send.
 *   - Every message goes through `ContactFilter::mask()`: phone numbers,
 *     emails, links and handles become [•••] — whatever stage the booking
 *     is at, the conversation stays here. Map links are kept.
 *   - A message is `text` or `location` (032): a point the sender's device
 *     reported, stored as coordinates so each viewer's app links it to
 *     their own device's maps (`shareLocation()`).
 *   - Delivery: published to both people's `user:<id>` channels (`Push`) as
 *     `{ type: "message", message }`, so the recipient sees it live and the
 *     sender's other tabs stay in step. Someone who isn't looking gets the
 *     red counter on "Messages" (`Badges`) and, for the first unread
 *     message from that person, an email without the text.
 *
 * Times are UTC; the API returns them as ISO 8601 (`...Z`).
 */
class Messages
{
    public const MAX_LENGTH = 2000;
    public const PAGE_SIZE = 50;

    /** @var db */
    private $db;
    /** @var Push */
    private $push;
    /** @var Notifications */
    private $notifications;

    public function __construct(db $db, Push $push, Notifications $notifications)
    {
        $this->db = $db;
        $this->push = $push;
        $this->notifications = $notifications;
    }

    /**
     * Sends `$body` from `$senderId` to `$recipientId`, who must be actively
     * matched with them. Returns the stored message as the sender sees it.
     */
    public function send(int $senderId, string $senderRole, int $recipientId, string $body): array
    {
        $selection = $this->activePair($senderId, $senderRole, $recipientId);

        $body = trim((string) preg_replace('/\R{3,}/u', "\n\n", $body));
        if ($body === '' || !mb_check_encoding($body, 'UTF-8')) {
            throw new RuntimeException('Write a message first.');
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_LENGTH) {
            throw new RuntimeException('Messages can be ' . self::MAX_LENGTH . ' characters at most.');
        }

        return $this->deliver($selection, $senderId, $recipientId, [
            'body' => ContactFilter::mask($body, 'message'),
        ]);
    }

    /**
     * Shares a point — what the sender's device reported — as a `location`
     * message. Coordinates are stored, not a link, so each viewer gets a link
     * to their own device's maps app (client/src/utils/mapsLink.ts).
     * `$accuracy` is the device's estimate in metres, if it gave one.
     */
    public function shareLocation(int $senderId, string $senderRole, int $recipientId, $latitude, $longitude, $accuracy = null): array
    {
        $selection = $this->activePair($senderId, $senderRole, $recipientId);

        $lat = self::coordinate($latitude, 90);
        $lng = self::coordinate($longitude, 180);
        if ($lat === null || $lng === null) {
            throw new RuntimeException('That location isn’t valid.');
        }
        $fields = [
            'kind' => 'location',
            'body' => '',
            'latitude' => number_format($lat, 6, '.', ''),
            'longitude' => number_format($lng, 6, '.', ''),
        ];
        if (is_int($accuracy) || is_float($accuracy)) {
            $fields['accuracy_m'] = (string) max(0, min(100000, (int) round($accuracy)));
        }

        return $this->deliver($selection, $senderId, $recipientId, $fields);
    }

    /** The pair's selection, refusing unless it's an active match. */
    private function activePair(int $senderId, string $senderRole, int $recipientId): array
    {
        $selection = $this->pairOf($senderId, $senderRole, $recipientId);
        if (!$selection || (int) $selection['active'] !== 1) {
            throw new RuntimeException('You can only message someone you’re matched with.');
        }

        return $selection;
    }

    /**
     * A finite number within ±`$limit` (JSON `true` and numeric strings
     * refused), else null — a latitude (90) or longitude (180). Shared with
     * `Bookings`' meeting pin.
     */
    public static function coordinate($value, int $limit): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && abs($value) <= $limit ? $value : null;
    }

    /** Stores a message, pushes it to both people, and emails an absent recipient. */
    private function deliver(array $selection, int $senderId, int $recipientId, array $fields): array
    {
        $hadUnread = $this->unreadFrom($senderId, $recipientId) > 0;

        $id = $this->db->db_Insert('messages', [
            'selection_id' => (int) $selection['id'],
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
        ] + $fields);
        if (!$id) {
            throw new RuntimeException('Could not send the message. Please try again.');
        }

        $row = $this->find((int) $id);
        $senderName = $this->db->db_GetFieldFromQuery('SELECT `name` FROM `users` WHERE `id` = ' . $senderId, 'name');
        // `sender_name` for the recipient's "New message from …" toast.
        $reached = $this->push->toUser($recipientId, ['type' => 'message', 'message' => self::view($row, $recipientId) + ['sender_name' => $senderName ?: null]]);
        $this->push->toUser($senderId, ['type' => 'message', 'message' => self::view($row, $senderId)]);
        $this->push->touch($recipientId);

        // An email only for someone who isn't in the app (no gateway holds a
        // socket for them), and only for the first message they haven't read
        // — a live chat, where each message is read as it lands, sends none.
        if ($reached === 0 && !$hadUnread) {
            $this->emailFirstUnread($senderId, $recipientId);
        }

        return self::view($row, $senderId);
    }

    /**
     * Everyone `$userId` can chat with or has chatted with: active matches
     * (even before the first message) and any pair with history. Newest
     * activity first.
     */
    public function conversationsFor(int $userId, string $role): array
    {
        // Only the pairs where the user is on their acting side; the other
        // person's role is the pair's other side, not something about them.
        $their = Roles::opposite($role);
        $rows = $this->db->db_GetArray(
            'SELECT s.`id` AS `selection_id`, s.`active`, s.`matched_at`,
                    u.`id` AS `counterpart_id`, u.`name`, u.`image`,
                    m.`id` AS `last_id`, m.`sender_id` AS `last_sender_id`, m.`kind` AS `last_kind`, m.`body` AS `last_body`,
                    UNIX_TIMESTAMP(m.`created_at`) AS `last_ts`,
                    (SELECT COUNT(*) FROM `messages` um
                      WHERE um.`selection_id` = s.`id` AND um.`recipient_id` = ' . $userId . ' AND um.`read_at` IS NULL) AS `unread`
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $their . '_id`
             LEFT JOIN `messages` m ON m.`id` = (SELECT MAX(m2.`id`) FROM `messages` m2 WHERE m2.`selection_id` = s.`id`)
             WHERE s.`' . $role . '_id` = ' . $userId . '
               AND (s.`active` = 1 OR m.`id` IS NOT NULL)
             ORDER BY COALESCE(m.`created_at`, s.`matched_at`, s.`updated_at`) DESC'
        ) ?: [];

        return array_map(static fn(array $row): array => [
            'counterpart_id' => (int) $row['counterpart_id'],
            'name' => $row['name'],
            'image' => $row['image'] ?: null,
            'role' => $their,
            'can_send' => (int) $row['active'] === 1,
            'unread' => (int) $row['unread'],
            'last_message' => $row['last_id'] === null ? null : [
                'id' => (int) $row['last_id'],
                'mine' => (int) $row['last_sender_id'] === $userId,
                'kind' => $row['last_kind'],
                'body' => $row['last_body'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['last_ts']),
            ],
        ], $rows);
    }

    /**
     * One conversation: up to `PAGE_SIZE` messages older than `$beforeId`
     * (or the newest), oldest first. Opening the newest page marks what
     * `$userId` received as read.
     */
    public function threadWith(int $userId, string $role, int $otherId, ?int $beforeId = null): array
    {
        $selection = $this->pairOf($userId, $role, $otherId);
        if (!$selection) {
            throw new RuntimeException('Conversation not found.');
        }
        $selectionId = (int) $selection['id'];

        $rows = $this->db->db_GetArray(
            self::SELECT . ' WHERE `selection_id` = ' . $selectionId
            . ($beforeId ? ' AND `id` < ' . $beforeId : '')
            . ' ORDER BY `id` DESC LIMIT ' . (self::PAGE_SIZE + 1)
        ) ?: [];
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_reverse(array_slice($rows, 0, self::PAGE_SIZE));

        // Only up to what this response carries: a message that lands between
        // the SELECT and here stays unread until the reader actually gets it.
        if (!$beforeId && $rows) {
            $this->markRead($userId, $otherId, $selectionId, (int) $rows[count($rows) - 1]['id']);
        }

        $other = $this->db->db_GetRow(
            'SELECT u.`id`, u.`name`, u.`image` FROM `users` u WHERE u.`id` = ' . $otherId
        );

        return [
            'counterpart' => [
                'id' => (int) $other['id'],
                'name' => $other['name'],
                'image' => $other['image'] ?: null,
                'role' => Roles::opposite($role),
            ],
            'can_send' => (int) $selection['active'] === 1,
            'has_more' => $hasMore,
            'messages' => array_map(static fn(array $row): array => self::view($row, $userId), $rows),
        ];
    }

    /**
     * What `$userId` received from `$otherId`, up to message `$upToId` (the
     * newest one they've been shown), counts as read — a live message seen in
     * an open conversation.
     */
    public function markReadWith(int $userId, string $role, int $otherId, int $upToId): void
    {
        $selection = $this->pairOf($userId, $role, $otherId);
        if ($selection && $upToId > 0) {
            $this->markRead($userId, $otherId, (int) $selection['id'], $upToId);
        }
    }

    /**
     * Unread messages per user, for the menu counter (`Badges`).
     *
     * @param int[] $userIds
     * @return array<int, int>
     */
    public static function unreadCounts(db $db, array $userIds): array
    {
        $out = array_fill_keys($userIds, 0);
        if (!$userIds) {
            return $out;
        }
        $rows = $db->db_GetArray(
            'SELECT `recipient_id`, COUNT(*) AS `n` FROM `messages`
             WHERE `recipient_id` IN (' . implode(',', array_map('intval', $userIds)) . ') AND `read_at` IS NULL
             GROUP BY `recipient_id`'
        ) ?: [];
        foreach ($rows as $row) {
            $out[(int) $row['recipient_id']] = (int) $row['n'];
        }

        return $out;
    }

    private const SELECT = 'SELECT `id`, `sender_id`, `recipient_id`, `kind`, `body`, `latitude`, `longitude`, `accuracy_m`,
        UNIX_TIMESTAMP(`created_at`) AS `created_ts`, UNIX_TIMESTAMP(`read_at`) AS `read_ts`
        FROM `messages`';

    private function find(int $id): array
    {
        return $this->db->db_GetRow(self::SELECT . ' WHERE `id` = ' . $id);
    }

    /**
     * The pair's `selections` row with `$me` on the `$myRole` side — two
     * people who both hold both roles can have a pair each way, and each pair
     * is its own conversation.
     */
    private function pairOf(int $me, string $myRole, int $other): ?array
    {
        if ($me <= 0 || $other <= 0 || $me === $other) {
            return null;
        }
        [$guestId, $guideId] = $myRole === 'guest' ? [$me, $other] : [$other, $me];
        $row = $this->db->db_GetRow(
            'SELECT `id`, `active` FROM `selections`
             WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId
        );

        return $row ?: null;
    }

    /**
     * Marks read and, if anything changed, tells the sender (`messages_read`,
     * for their "Seen") and refreshes the reader's counter.
     */
    private function markRead(int $userId, int $otherId, int $selectionId, int $upToId): void
    {
        $this->db->db_Execute(
            'UPDATE `messages` SET `read_at` = UTC_TIMESTAMP()
             WHERE `selection_id` = ' . $selectionId . ' AND `recipient_id` = ' . $userId . '
               AND `id` <= ' . $upToId . ' AND `read_at` IS NULL'
        );
        if ($this->db->db_GetAffectedRows() > 0) {
            $this->push->toUser($otherId, [
                'type' => 'messages_read',
                'reader_id' => $userId,
                'up_to_id' => $upToId,
                'read_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->push->touch($userId);
        }
    }

    private function unreadFrom(int $senderId, int $recipientId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS `n` FROM `messages`
             WHERE `sender_id` = ' . $senderId . ' AND `recipient_id` = ' . $recipientId . ' AND `read_at` IS NULL',
            'n'
        );
    }

    /** No message text in the email: it would be read outside the app, and it's the app we want them back in. */
    private function emailFirstUnread(int $senderId, int $recipientId): void
    {
        $sender = $this->db->db_GetRow('SELECT `name` FROM `users` WHERE `id` = ' . $senderId);
        $recipient = $this->db->db_GetRow('SELECT `name` FROM `users` WHERE `id` = ' . $recipientId);
        $senderName = trim((string) ($sender['name'] ?? '')) ?: 'Your match';
        $recipientName = trim((string) ($recipient['name'] ?? ''));

        $this->notifications->emailOnly(
            $recipientId,
            'message',
            "New message from {$senderName}",
            ($recipientName !== '' ? "Hi {$recipientName},\n\n" : "Hi,\n\n")
            . "{$senderName} sent you a message on RockGuide. Open Messages in the app to read it and reply.\n\nRockGuide"
        );
    }

    private static function view(array $row, int $viewerId): array
    {
        return [
            'id' => (int) $row['id'],
            'sender_id' => (int) $row['sender_id'],
            'recipient_id' => (int) $row['recipient_id'],
            'mine' => (int) $row['sender_id'] === $viewerId,
            'kind' => $row['kind'],
            'body' => $row['body'],
            'location' => $row['kind'] === 'location' ? [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'accuracy_m' => $row['accuracy_m'] === null ? null : (int) $row['accuracy_m'],
            ] : null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['created_ts']),
            'read_at' => $row['read_ts'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $row['read_ts']),
        ];
    }
}
