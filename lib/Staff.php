<?php

/**
 * CMS staff accounts (docs/auth-cms-plan.md, phase 6) — separate from app
 * accounts: `cms_users` + `cms_creds` (076). Roles: admin, manager, editor
 * (`roles`). Used by the auth service (signing staff in, `CmsAuthHttp`) and
 * the CMS API (managing staff).
 *
 * - Passwords are hashed like app users' (`Auth`: salt + pepper + bcrypt), at
 *   least `MIN_PASSWORD_LENGTH` long.
 * - A new member gets no password: an invite emails a one-time **set-password
 *   link** (`createSetupLink()`), also used for "forgot password".
 * - **Every sign-in needs an emailed code** (`startSignIn()` / `verifyCode()`,
 *   `cms_challenges`) — there's no remembered device for staff.
 */
final class Staff
{
    public const ROLES = ['admin', 'manager', 'editor'];
    public const MIN_PASSWORD_LENGTH = 12;
    private const CODE_TTL_SECONDS = 10 * 60;
    private const MAX_CODE_ATTEMPTS = 5;
    public const INVITE_TTL_SECONDS = 72 * 3600;
    public const RESET_TTL_SECONDS = 30 * 60;

    /** @var db */
    private $db;
    /** @var MailQueue */
    private $mail;

    public function __construct(db $db, MailQueue $mail)
    {
        $this->db = $db;
        $this->mail = $mail;
    }

    /** One member with their role name, or null. */
    public function find(int $id): ?array
    {
        return $this->row('s.`id` = ' . $id);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->row('s.`email` = "' . $this->db->db_Escape(mb_strtolower(trim($email))) . '"');
    }

    /** @return array<int, array> every member, newest first */
    public function all(): array
    {
        return array_map([$this, 'present'], (array) $this->db->db_GetArray(
            'SELECT s.*, r.`name` AS `role`, (c.`password` IS NOT NULL) AS `has_password`
             FROM `cms_users` s JOIN `roles` r ON r.`id` = s.`role_id`
             LEFT JOIN `cms_creds` c ON c.`staff_id` = s.`id`
             ORDER BY s.`id` DESC'
        ));
    }

    /** Creates a member (no password yet) and returns their id. */
    public function create(string $email, string $name, string $role, ?int $invitedBy): int
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new RuntimeException('Role must be admin, manager or editor.');
        }
        if ($this->findByEmail($email)) {
            throw new RuntimeException('There is already a staff member with that email.');
        }
        $id = $this->db->db_Insert('cms_users', array_filter([
            'role_id' => $this->roleId($role),
            'email' => $email,
            'name' => mb_substr(trim($name), 0, 120),
            'invited_by' => $invitedBy,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], static fn($value): bool => $value !== null));
        if (!$id) {
            throw new RuntimeException('Could not create the staff member.');
        }
        $this->db->db_Execute('INSERT INTO `cms_creds` (`staff_id`) VALUES (' . (int) $id . ')');

        return (int) $id;
    }

    public function setRole(int $id, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new RuntimeException('Role must be admin, manager or editor.');
        }
        $this->db->db_Execute('UPDATE `cms_users` SET `role_id` = ' . $this->roleId($role) . ' WHERE `id` = ' . $id);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $this->db->db_Execute(
            'UPDATE `cms_users` SET `disabled_at` = ' . ($disabled ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE `id` = ' . $id
        );
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `cms_users` s JOIN `roles` r ON r.`id` = s.`role_id`
             WHERE r.`name` = "admin" AND s.`disabled_at` IS NULL', 'n'
        );
    }

    /** The member if the password is right and they may sign in; else null. */
    public function verifyPassword(string $email, string $password): ?array
    {
        $staff = $this->findByEmail($email);
        if (!$staff || $staff['disabled_at'] !== null) {
            return null;
        }
        $creds = $this->db->db_GetRow('SELECT `password`, `salt` FROM `cms_creds` WHERE `staff_id` = ' . (int) $staff['id']);
        if (!$creds || $creds['password'] === null || !Auth::verifyPassword($password, (string) $creds['salt'], (string) $creds['password'])) {
            return null;
        }

        return $staff;
    }

    /**
     * A one-time set-password link — an invite (`INVITE_TTL_SECONDS`) or a
     * reset (`RESET_TTL_SECONDS`) — emailed to the member. Only the newest
     * works. Returns the link (development tooling prints it; nothing else may).
     */
    public function createSetupLink(int $id, int $ttlSeconds, string $why): string
    {
        $staff = $this->find($id);
        if (!$staff) {
            throw new RuntimeException('No such staff member.');
        }
        $token = bin2hex(random_bytes(32));
        $this->db->db_Execute(
            'UPDATE `cms_creds` SET `setup_token_hash` = "' . hash('sha256', $token) . '",
                    `setup_expires_at` = UTC_TIMESTAMP() + INTERVAL ' . $ttlSeconds . ' SECOND
             WHERE `staff_id` = ' . $id
        );
        $link = self::cmsUrl() . '/set-password?token=' . $token;
        $hours = max(1, (int) round($ttlSeconds / 3600));
        $this->mail->enqueue(
            (string) $staff['email'],
            $why === 'invite' ? 'You’re invited to the BeLikeLocal CMS' : 'Set a new BeLikeLocal CMS password',
            'Hi ' . (trim((string) $staff['name']) ?: 'there') . ",\n\n"
            . ($why === 'invite'
                ? "You've been given " . $staff['role'] . " access to the BeLikeLocal CMS. Choose your password here"
                : "Someone (hopefully you) asked to reset your BeLikeLocal CMS password. Choose a new one here")
            . " — the link works once, for {$hours} hour" . ($hours === 1 ? '' : 's') . ":\n{$link}\n\n"
            . "Every sign-in also needs a code we email you.\n\nBeLikeLocal",
            'cms_setup',
            MailQueue::PRIORITY_HIGH
        );

        return $link;
    }

    /** Sets the password from a set-password link, spending it; returns the member's id. */
    public function setPasswordWithLink(string $token, string $password): int
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('The password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
        $row = preg_match('/^[0-9a-f]{64}$/', $token) ? $this->db->db_GetRow(
            'SELECT `staff_id` FROM `cms_creds`
             WHERE `setup_token_hash` = "' . hash('sha256', $token) . '" AND `setup_expires_at` > UTC_TIMESTAMP()'
        ) : null;
        if (!$row) {
            throw new RuntimeException('This link has expired or was already used. Ask for a new one.');
        }
        $id = (int) $row['staff_id'];
        $salt = Auth::generateSalt();
        $this->db->db_Execute(
            'UPDATE `cms_creds` SET `password` = "' . $this->db->db_Escape(Auth::hashPassword($password, $salt)) . '",
                    `salt` = "' . $salt . '", `setup_token_hash` = NULL, `setup_expires_at` = NULL
             WHERE `staff_id` = ' . $id
        );

        return $id;
    }

    /** Emails a sign-in code; returns the challenge for the client. */
    public function startSignIn(array $staff): array
    {
        $id = (int) $staff['id'];
        $this->db->db_Execute('UPDATE `cms_challenges` SET `used_at` = UTC_TIMESTAMP() WHERE `staff_id` = ' . $id . ' AND `used_at` IS NULL');
        $token = bin2hex(random_bytes(32));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->db_Execute(
            'INSERT INTO `cms_challenges` (`staff_id`, `token_hash`, `code_hash`, `expires_at`, `created_at`)
             VALUES (' . $id . ', "' . hash('sha256', $token) . '", "' . $this->db->db_Escape(password_hash($code, PASSWORD_DEFAULT)) . '",
                     UTC_TIMESTAMP() + INTERVAL ' . self::CODE_TTL_SECONDS . ' SECOND, UTC_TIMESTAMP())'
        );
        $this->mail->enqueue(
            (string) $staff['email'],
            "Your BeLikeLocal CMS code: {$code}",
            'Hi ' . (trim((string) $staff['name']) ?: 'there') . ",\n\nYour CMS sign-in code is:\n\n    {$code}\n\n"
            . 'It works for ' . (self::CODE_TTL_SECONDS / 60) . " minutes. If you didn't just sign in, someone has your "
            . "CMS password — tell an admin straight away.\n\nBeLikeLocal",
            'cms_code',
            MailQueue::PRIORITY_HIGH
        );

        return [
            'codeRequired' => true,
            'challengeToken' => $token,
            'expiresInSeconds' => self::CODE_TTL_SECONDS,
        ];
    }

    /** Trades a challenge and its code for the member's id. */
    public function verifyCode(string $token, string $code): int
    {
        $row = preg_match('/^[0-9a-f]{64}$/', $token) ? $this->db->db_GetRow(
            'SELECT `id`, `staff_id`, `code_hash`, `attempts` FROM `cms_challenges`
             WHERE `token_hash` = "' . hash('sha256', $token) . '" AND `used_at` IS NULL
               AND `expires_at` > UTC_TIMESTAMP() AND `attempts` < ' . self::MAX_CODE_ATTEMPTS
        ) : null;
        if (!$row) {
            throw new RuntimeException('This code has expired. Sign in again to get a new one.');
        }
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || !password_verify($code, (string) $row['code_hash'])) {
            $this->db->db_Execute('UPDATE `cms_challenges` SET `attempts` = `attempts` + 1 WHERE `id` = ' . (int) $row['id']);
            throw new RuntimeException('That code isn’t right.');
        }
        $this->db->db_Execute('UPDATE `cms_challenges` SET `used_at` = UTC_TIMESTAMP() WHERE `id` = ' . (int) $row['id']);
        $this->db->db_Execute('UPDATE `cms_users` SET `last_sign_in_at` = UTC_TIMESTAMP() WHERE `id` = ' . (int) $row['staff_id']);

        return (int) $row['staff_id'];
    }

    /** What the CMS shows of a member — never credentials. */
    public function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) ($row['name'] ?? ''),
            'role' => (string) $row['role'],
            'disabled' => $row['disabled_at'] !== null,
            'hasPassword' => (bool) (int) ($row['has_password'] ?? 0),
            'lastSignInAt' => $row['last_sign_in_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($row['last_sign_in_at'] . ' UTC')) : null,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
        ];
    }

    public static function cmsUrl(): string
    {
        return rtrim((string) (getenv('CMS_URL') ?: 'http://localhost:5174'), '/');
    }

    private function row(string $where): ?array
    {
        $row = $this->db->db_GetRow(
            'SELECT s.*, r.`name` AS `role`, (c.`password` IS NOT NULL) AS `has_password`
             FROM `cms_users` s JOIN `roles` r ON r.`id` = s.`role_id`
             LEFT JOIN `cms_creds` c ON c.`staff_id` = s.`id` WHERE ' . $where
        );

        return $row ?: null;
    }

    private function roleId(string $role): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT `id` FROM `roles` WHERE `name` = "' . $this->db->db_Escape($role) . '"', 'id'
        );
    }
}
