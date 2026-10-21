<?php

/**
 * Frozen (suspended) app accounts — `user_freezes` (080), set and lifted by
 * staff in the CMS (docs/auth-cms-plan.md, phase 8). One definition of
 * "frozen now" for every service: auth refuses sign-in and refresh, the API
 * hides the person from decks, the planner and public profiles and makes
 * chats with them read-only, the CMS shows and filters by it.
 */
final class Freezes
{
    /** SQL condition on `user_freezes` aliased `$alias`: in force now. */
    public static function activeSql(string $alias = 'f'): string
    {
        return $alias . '.`lifted_at` IS NULL AND ' . $alias . '.`starts_at` <= UTC_TIMESTAMP()'
            . ' AND (' . $alias . '.`ends_at` IS NULL OR ' . $alias . '.`ends_at` > UTC_TIMESTAMP())';
    }

    /** SQL condition: user id `$idSql` (e.g. "u.`id`") has a freeze in force. */
    public static function isFrozenSql(string $idSql): string
    {
        return 'EXISTS (SELECT 1 FROM `user_freezes` fz_ WHERE fz_.`user_id` = ' . $idSql . ' AND ' . self::activeSql('fz_') . ')';
    }

    /** SQL condition: user id `$idSql` is not frozen. */
    public static function notFrozenSql(string $idSql): string
    {
        return 'NOT ' . self::isFrozenSql($idSql);
    }

    /**
     * The freeze in force for `$userId`, or null:
     * `{ id, startsAt, until (ISO, null = permanently), reason }`.
     */
    public static function activeFor(db $db, int $userId): ?array
    {
        $row = $db->db_GetRow(
            'SELECT f.`id`, f.`reason`, UNIX_TIMESTAMP(f.`starts_at`) AS `starts_ts`, UNIX_TIMESTAMP(f.`ends_at`) AS `ends_ts`
             FROM `user_freezes` f WHERE f.`user_id` = ' . $userId . ' AND ' . self::activeSql('f') . '
             ORDER BY f.`ends_at` IS NULL DESC, f.`ends_at` DESC LIMIT 1'
        );
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'startsAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['starts_ts']),
            'until' => $row['ends_ts'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $row['ends_ts']) : null,
            'reason' => (string) $row['reason'],
        ];
    }
}
