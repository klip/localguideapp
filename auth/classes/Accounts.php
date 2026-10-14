<?php

/**
 * The auth service's view of accounts: creating them, and checking and
 * changing passwords (`users`, `creds`, `user_role`). Everything about
 * profiles stays in the API's `Users`.
 *
 * Both services still share one database; `creds`, `auth_challenges` and
 * `trusted_devices` belong to this service, `users` to both for now (see
 * docs/scaling.md for splitting it).
 */
class Accounts
{
    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    public function findByEmail(string $email)
    {
        return $this->db->db_GetRow(
            'SELECT * FROM `users` WHERE `email` = "' . $this->db->db_Escape($email) . '"'
        );
    }

    public function findById(int $userId)
    {
        return $this->db->db_GetRowById($userId, 'users');
    }

    public function getRoleName(int $userId): ?string
    {
        $name = $this->db->db_GetFieldFromQuery(
            'SELECT r.`name` FROM `users` u JOIN `roles` r ON r.`id` = u.`role_id` WHERE u.`id` = ' . $userId,
            'name'
        );
        return $name === false ? null : $name;
    }

    /**
     * Creates a `users` row plus its matching `creds` row, and logs the
     * grant in `user_role` (separate from `users.role_id`, the account's
     * current role — see the note atop `api/sql/003_user_role.sql`).
     *
     * Relies on the unique index on `users.email` to reject duplicates
     * atomically, rather than check-then-insert.
     *
     * @return int the new user id
     */
    public function register(
        string $email,
        string $password,
        string $roleName,
        ?string $name = null,
        ?string $image = null
    ): int {
        $roleId = $this->db->db_GetFieldFromQuery(
            'SELECT `id` FROM `roles` WHERE `name` = "' . $this->db->db_Escape($roleName) . '"',
            'id'
        );
        if (!$roleId || !in_array($roleName, ['guest', 'guide'], true)) {
            throw new RuntimeException('Unknown role: ' . $roleName);
        }

        $userId = $this->db->db_Insert('users', [
            'role_id' => $roleId,
            'email' => $email,
            'name' => $name ?? '',
            'image' => $image ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$userId) {
            throw new RuntimeException('Could not create account — email may already be registered.');
        }

        $salt = Auth::generateSalt();
        $hash = Auth::hashPassword($password, $salt);

        $this->db->db_Insert('creds', [
            'user_id' => $userId,
            'password' => $hash,
            'salt' => $salt,
        ]);

        $this->db->db_Insert('user_role', [
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        return (int) $userId;
    }

    /**
     * Verifies login credentials. Returns the `users` row on success, false
     * otherwise (unknown email, no creds row, or password mismatch).
     */
    public function verifyCredentials(string $email, string $password)
    {
        $user = $this->findByEmail($email);
        if (!$user) {
            return false;
        }

        return $this->verifyPasswordFor((int) $user['id'], $password) ? $user : false;
    }

    /** Verifies a password against the account's stored hash — the "current password" check before `setPassword()`. */
    public function verifyPasswordFor(int $userId, string $password): bool
    {
        $creds = $this->db->db_GetRow('SELECT * FROM `creds` WHERE `user_id` = ' . $userId);
        if (!$creds) {
            return false;
        }

        return Auth::verifyPassword($password, $creds['salt'], $creds['password']);
    }

    /** Re-salts and re-hashes the account's password. The caller verifies the current one first. */
    public function setPassword(int $userId, string $newPassword): void
    {
        $salt = Auth::generateSalt();
        $hash = Auth::hashPassword($newPassword, $salt);

        $this->db->db_Update('creds', ['password' => $hash, 'salt' => $salt], 'WHERE `user_id` = ' . $userId);
    }
}
