<?php

/**
 * Who is a guest and who is a guide. Roles live only in the `user_role`
 * mapping table (`users.role_id` was dropped by 046); these build the SQL
 * every service uses to read them, so nothing joins the table by hand.
 *
 * An account has exactly one *app* role (guest or guide) — `Accounts::
 * register()` grants it and nothing grants another. The table could map more
 * (a future CMS role, say), so reads go through a single-row subquery rather
 * than a JOIN: an extra row can never duplicate a user in a listing.
 */
class Roles
{
    /** The roles an app account can hold; CMS roles live on `cms_users`. */
    public const APP_ROLES = ['guest', 'guide'];

    /**
     * SQL expression: the app role name of the user whose id is
     * `$userIdExpr` (a column like "u.`id`" or an integer), or NULL.
     */
    public static function nameSql(string $userIdExpr): string
    {
        return '(SELECT r_.`name` FROM `user_role` ur_ JOIN `roles` r_ ON r_.`id` = ur_.`role_id`
                 WHERE ur_.`user_id` = ' . $userIdExpr . ' AND r_.`name` IN ("guest", "guide")
                 ORDER BY ur_.`id` LIMIT 1)';
    }

    /**
     * Every app role `$userId` holds, in the order granted — the first is the
     * one they registered with, and the default acting role.
     *
     * @return string[]
     */
    public static function of(int $userId, db $db): array
    {
        $rows = $db->db_GetArray(
            'SELECT r.`name` FROM `user_role` ur JOIN `roles` r ON r.`id` = ur.`role_id`
             WHERE ur.`user_id` = ' . $userId . ' AND r.`name` IN ("guest", "guide") ORDER BY ur.`id`'
        ) ?: [];

        return array_values(array_map(static fn(array $row): string => $row['name'], $rows));
    }

    /** The other side of a match: guest ⇄ guide. */
    public static function opposite(string $role): string
    {
        return $role === 'guest' ? 'guide' : 'guest';
    }

    /** SQL condition: the user `$userIdExpr` holds the role `$roleName`. */
    public static function hasSql(string $userIdExpr, string $roleName, db $db): string
    {
        return 'EXISTS (SELECT 1 FROM `user_role` ur_ JOIN `roles` r_ ON r_.`id` = ur_.`role_id`
                WHERE ur_.`user_id` = ' . $userIdExpr . ' AND r_.`name` = "' . $db->db_Escape($roleName) . '")';
    }
}
