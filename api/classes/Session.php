<?php

/**
 * Server-side sessions, backed by the `sessions` table (see
 * `009_sessions.sql`). A session is a random hash mapped to a user id, with
 * a *sliding* expiry: any successful `resolve()` bumps `last_active_at` to
 * now, so a session lives as long as it's used at least once every
 * `TTL_MINUTES`, not for a fixed window from creation. This is the app's
 * first real identity check — every mutating action from `unlockPack` down
 * used to trust a client-supplied `userId` outright (see
 * `RequestProcessor::requireUserId()`, which now calls `resolve()` instead).
 */
class Session
{
    /** Minutes of inactivity before a session is considered expired. */
    private const TTL_MINUTES = 5;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /** Creates a new session for a just-registered/logged-in user, returning its hash. */
    public function create(int $userId): string
    {
        $hash = bin2hex(random_bytes(32));

        $this->db->db_Insert('sessions', [
            'session_hash' => $hash,
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'last_active_at' => date('Y-m-d H:i:s'),
        ]);

        return $hash;
    }

    /**
     * Resolves a session hash to its user id if the session exists and its
     * last activity was within `TTL_MINUTES`, bumping `last_active_at` to
     * now in the same pass (the sliding expiry). Returns `null` for an
     * unknown or expired hash — an expired row is deleted rather than left
     * to rot, so `sessions` only ever holds live sessions.
     */
    public function resolve(string $hash): ?int
    {
        if ($hash === '') {
            return null;
        }

        $row = $this->db->db_GetRow(
            'SELECT `user_id`, `last_active_at` FROM `sessions` WHERE `session_hash` = "' . $this->db->db_Escape($hash) . '"'
        );
        if (!$row) {
            return null;
        }

        $lastActive = strtotime($row['last_active_at']);
        if ($lastActive === false || $lastActive < time() - self::TTL_MINUTES * 60) {
            $this->destroy($hash);
            return null;
        }

        $this->db->db_Execute(
            'UPDATE `sessions` SET `last_active_at` = NOW() WHERE `session_hash` = "' . $this->db->db_Escape($hash) . '"'
        );

        return (int) $row['user_id'];
    }

    /** Ends a session — logout, or an expired session found by `resolve()`. Silently no-ops for an unknown hash. */
    public function destroy(string $hash): void
    {
        $this->db->db_Execute('DELETE FROM `sessions` WHERE `session_hash` = "' . $this->db->db_Escape($hash) . '"');
    }

    /** Signs a user out everywhere — after a password reset (see `AccountSecurity::resetPassword()`). */
    public function destroyAllFor(int $userId): void
    {
        $this->db->db_Execute('DELETE FROM `sessions` WHERE `user_id` = ' . $userId);
    }
}
