<?php

/**
 * Two-factor login codes and password resets, both delivered by email for now
 * (see `016_auth_challenges.sql`).
 *
 * Two-factor login: when an account has a channel set (`users.two_factor_method`
 * — anything but 'none'), a correct password no longer creates a session.
 * `startLogin()` emails a 6-digit code and hands the client an opaque
 * `challengeToken`; `verifyLogin()` trades token + code for the user id, and
 * only then does `RequestProcessor` create the session. SMS/WhatsApp aren't
 * wired to a provider yet, so every code goes by email whatever the setting.
 *
 * Password reset: `requestPasswordReset()` emails a one-time link (to
 * `APP_URL`/reset-password?token=…) if — and only if — the address belongs
 * to an account, but its caller can't tell either way. `resetPassword()`
 * sets the new password, spends the link, and signs the account out
 * everywhere.
 *
 * Tokens are 64 hex characters from `random_bytes()`; only their SHA-256 is
 * stored. Codes are stored as `password_hash()`. All date-times are UTC.
 */
class AccountSecurity
{
    private const LOGIN_CODE_TTL_SECONDS = 10 * 60;
    private const RESET_TTL_SECONDS = 30 * 60;
    /** Wrong guesses allowed per login challenge, across resends. */
    private const MAX_CODE_ATTEMPTS = 5;
    /** The first email plus two resends. */
    private const MAX_CODE_SENDS = 3;
    private const RESEND_COOLDOWN_SECONDS = 30;
    /** Reset emails per account per hour; more are silently dropped. */
    private const MAX_RESETS_PER_HOUR = 3;
    private const MIN_PASSWORD_LENGTH = 8;

    /** @var db */
    private $db;
    /** @var Users */
    private $users;
    /** @var Mailer */
    private $mailer;
    /** @var Session */
    private $sessions;

    public function __construct(db $db, Users $users, Mailer $mailer, Session $sessions)
    {
        $this->db = $db;
        $this->users = $users;
        $this->mailer = $mailer;
        $this->sessions = $sessions;
    }

    /** Whether logging in to `$user` (a `users` row) needs a code as well as the password. */
    public static function requiresTwoFactor(array $user): bool
    {
        return in_array($user['two_factor_method'] ?? 'none', ['email', 'sms', 'whatsapp'], true);
    }

    // ---------------------------------------------------------------- two-factor login

    /**
     * The password was right: open a login challenge and email its code.
     * Any older unfinished login challenge for the account is dropped.
     */
    public function startLogin(array $user): array
    {
        $userId = (int) $user['id'];
        $this->spendOpen($userId, 'login');

        $token = self::newToken();
        $code = self::newCode();
        $this->db->db_Execute(
            'INSERT INTO `auth_challenges`
               (`user_id`, `purpose`, `token_hash`, `code_hash`, `last_sent_at`, `expires_at`, `created_at`)
             VALUES (' . $userId . ', "login", "' . hash('sha256', $token) . '",
                     "' . $this->db->db_Escape(password_hash($code, PASSWORD_DEFAULT)) . '",
                     UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ' . self::LOGIN_CODE_TTL_SECONDS . ' SECOND, UTC_TIMESTAMP())'
        );

        $this->sendCode($user, $code);

        return $this->challengeResponse($token, (string) $user['email']);
    }

    /**
     * Trades a login challenge's token + emailed code for the user id. Wrong
     * codes count against `MAX_CODE_ATTEMPTS`; running out spends the
     * challenge, so the user has to start again from their password.
     */
    public function verifyLogin(string $token, string $code): int
    {
        $challenge = $this->openChallenge('login', $token);
        if (!$challenge) {
            throw new RuntimeException('This code has expired. Log in again to get a new one.');
        }

        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || !password_verify($code, (string) $challenge['code_hash'])) {
            $this->db->db_Execute('UPDATE `auth_challenges` SET `attempts` = `attempts` + 1 WHERE `id` = ' . (int) $challenge['id']);
            $left = self::MAX_CODE_ATTEMPTS - ((int) $challenge['attempts'] + 1);
            if ($left <= 0) {
                $this->spend((int) $challenge['id']);
                throw new RuntimeException('Too many wrong codes. Log in again to get a new one.');
            }
            throw new RuntimeException('That code isn’t right. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.');
        }

        $this->spend((int) $challenge['id']);

        return (int) $challenge['user_id'];
    }

    /** Emails a fresh code for an open login challenge (the old code stops working). */
    public function resendLoginCode(string $token): array
    {
        $challenge = $this->openChallenge('login', $token);
        if (!$challenge) {
            throw new RuntimeException('This code has expired. Log in again to get a new one.');
        }
        if ((int) $challenge['sends'] >= self::MAX_CODE_SENDS) {
            throw new RuntimeException('No more codes can be sent for this login. Log in again to start over.');
        }
        if ((int) $challenge['seconds_since_sent'] < self::RESEND_COOLDOWN_SECONDS) {
            throw new RuntimeException('Wait a few seconds before asking for another code.');
        }

        $user = $this->users->findById((int) $challenge['user_id']);
        if (!$user) {
            throw new RuntimeException('This code has expired. Log in again to get a new one.');
        }

        $code = self::newCode();
        $this->db->db_Execute(
            'UPDATE `auth_challenges`
             SET `code_hash` = "' . $this->db->db_Escape(password_hash($code, PASSWORD_DEFAULT)) . '",
                 `sends` = `sends` + 1, `last_sent_at` = UTC_TIMESTAMP(),
                 `expires_at` = UTC_TIMESTAMP() + INTERVAL ' . self::LOGIN_CODE_TTL_SECONDS . ' SECOND
             WHERE `id` = ' . (int) $challenge['id']
        );

        $this->sendCode($user, $code);

        return $this->challengeResponse($token, (string) $user['email']);
    }

    // ---------------------------------------------------------------- password reset

    /**
     * Emails a reset link if `$email` belongs to an account. Returns nothing
     * either way, so the caller can't use it to find out who's registered.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->users->findByEmail(trim($email));
        if (!$user) {
            return;
        }
        $userId = (int) $user['id'];

        $recent = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `auth_challenges`
             WHERE `user_id` = ' . $userId . ' AND `purpose` = "reset"
               AND `created_at` > UTC_TIMESTAMP() - INTERVAL 1 HOUR',
            'n'
        );
        if ($recent >= self::MAX_RESETS_PER_HOUR) {
            $this->db->writeLog('AccountSecurity: reset email limit reached for user ' . $userId);
            return;
        }

        $this->spendOpen($userId, 'reset');
        $token = self::newToken();
        $this->db->db_Execute(
            'INSERT INTO `auth_challenges` (`user_id`, `purpose`, `token_hash`, `last_sent_at`, `expires_at`, `created_at`)
             VALUES (' . $userId . ', "reset", "' . hash('sha256', $token) . '",
                     UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ' . self::RESET_TTL_SECONDS . ' SECOND, UTC_TIMESTAMP())'
        );

        $link = self::appUrl() . '/reset-password?token=' . $token;
        $minutes = (int) (self::RESET_TTL_SECONDS / 60);
        $this->mailer->send(
            (string) $user['email'],
            'Reset your RockGuide password',
            self::greeting($user)
            . "Someone (hopefully you) asked to reset the password for your RockGuide account.\n\n"
            . "Choose a new password here — the link works once, for the next {$minutes} minutes:\n{$link}\n\n"
            . "If you didn't ask for this, ignore this email; your password stays as it is.\n\nRockGuide"
        );
    }

    /** Sets a new password from a reset link, spends the link, and signs the account out everywhere. */
    public function resetPassword(string $token, string $newPassword): void
    {
        self::assertPasswordStrength($newPassword);

        $challenge = $this->openChallenge('reset', $token);
        if (!$challenge) {
            throw new RuntimeException('This reset link is invalid or has expired. Ask for a new one.');
        }
        $userId = (int) $challenge['user_id'];

        $this->users->setPassword($userId, $newPassword);
        $this->spendOpen($userId, 'reset');
        $this->spendOpen($userId, 'login');
        $this->sessions->destroyAllFor($userId);

        $user = $this->users->findById($userId);
        if ($user) {
            $this->notifyPasswordChanged($user);
        }
    }

    /** Lets the account owner know their password changed — a reset, or a change from the profile page. */
    public function notifyPasswordChanged(array $user): void
    {
        $this->mailer->send(
            (string) $user['email'],
            'Your RockGuide password was changed',
            self::greeting($user)
            . "The password for your RockGuide account was just changed.\n\n"
            . "If that was you, there's nothing to do. If it wasn't, reset your password straight away:\n"
            . self::appUrl() . "/forgot-password\n\nRockGuide"
        );
    }

    public static function assertPasswordStrength(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
        }
    }

    // ---------------------------------------------------------------- internals

    /** An unspent, unexpired challenge of `$purpose` for `$token`, with a few derived fields; `null` otherwise. */
    private function openChallenge(string $purpose, string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $row = $this->db->db_GetRow(
            'SELECT *, TIMESTAMPDIFF(SECOND, `last_sent_at`, UTC_TIMESTAMP()) AS `seconds_since_sent`
             FROM `auth_challenges`
             WHERE `token_hash` = "' . hash('sha256', $token) . '" AND `purpose` = "' . $purpose . '"
               AND `used_at` IS NULL AND `expires_at` > UTC_TIMESTAMP()
               AND `attempts` < ' . self::MAX_CODE_ATTEMPTS
        );

        return $row ?: null;
    }

    private function spend(int $challengeId): void
    {
        $this->db->db_Execute('UPDATE `auth_challenges` SET `used_at` = UTC_TIMESTAMP() WHERE `id` = ' . $challengeId);
    }

    /** Spends every open challenge of `$purpose` for the user — only the newest link or code ever works. */
    private function spendOpen(int $userId, string $purpose): void
    {
        $this->db->db_Execute(
            'UPDATE `auth_challenges` SET `used_at` = UTC_TIMESTAMP()
             WHERE `user_id` = ' . $userId . ' AND `purpose` = "' . $purpose . '" AND `used_at` IS NULL'
        );
    }

    private function sendCode(array $user, string $code): void
    {
        $minutes = (int) (self::LOGIN_CODE_TTL_SECONDS / 60);
        $sent = $this->mailer->send(
            (string) $user['email'],
            "Your RockGuide login code: {$code}",
            self::greeting($user)
            . "Your login code is:\n\n    {$code}\n\n"
            . "It works for {$minutes} minutes. If you didn't just try to log in, someone has your password — "
            . "reset it at " . self::appUrl() . "/forgot-password\n\nRockGuide"
        );
        if (!$sent) {
            throw new RuntimeException('We couldn’t email your login code just now. Please try again in a minute.');
        }
    }

    private function challengeResponse(string $token, string $email): array
    {
        return [
            'twoFactorRequired' => true,
            'challengeToken' => $token,
            'method' => 'email',
            'destination' => self::maskEmail($email),
            'expiresInSeconds' => self::LOGIN_CODE_TTL_SECONDS,
            'resendAfterSeconds' => self::RESEND_COOLDOWN_SECONDS,
        ];
    }

    /** "raihlini@gmail.com" → "ra••••••@gmail.com". */
    private static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '•••';
        }
        $local = substr($email, 0, $at);
        $shown = substr($local, 0, min(2, max(1, strlen($local) - 1)));

        return $shown . str_repeat('•', max(3, strlen($local) - strlen($shown))) . substr($email, $at);
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Where the web app lives, for links in emails. From the server
     * environment (`fastcgi_param APP_URL`) — never from the request, whose
     * Origin/Host an attacker controls and could point a victim's reset link
     * at their own site.
     */
    private static function appUrl(): string
    {
        $url = (string) ($_SERVER['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:5173');

        return rtrim($url, '/');
    }

    private static function greeting(array $user): string
    {
        $name = trim((string) ($user['name'] ?? ''));

        return 'Hi ' . ($name !== '' ? $name : 'there') . ",\n\n";
    }
}
