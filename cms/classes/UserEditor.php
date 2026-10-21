<?php

/**
 * What staff change about an app user (docs/auth-cms-plan.md, phase 8):
 * account fields, roles and what they show in each, profile fields,
 * verification, sanctions, level adjustments, awarded badges and bonuses.
 * Freezing is `UserFreezes`.
 *
 * Every method validates, writes, and returns `[$before, $after]` for the
 * audit log (`CmsApi` records it). Field errors are thrown as
 * `{ field, error }` JSON, which the CMS pins under the input.
 */
final class UserEditor
{
    private const TWO_FACTOR = ['none', 'email'];
    private const VERIFICATION = ['id_card', 'passport', 'driving_licence'];

    /** @var db */
    private $db;
    /** @var RedisClient */
    private $redis;

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /** Name, email, phone, the 2FA channel, and removing the photo. */
    public function updateAccount(int $id, array $input): array
    {
        $user = $this->user($id);
        $set = [];
        $before = [];
        $after = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if (mb_strlen($name) > 120) {
                throw self::fieldError('name', 'At most 120 characters.');
            }
            $set['name'] = $name === '' ? null : $name;
        }
        if (array_key_exists('email', $input)) {
            $email = strtolower(trim((string) $input['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw self::fieldError('email', 'That isn’t an email address.');
            }
            $taken = $this->db->db_GetRow('SELECT `id` FROM `users` WHERE `email` = ' . $this->quote($email) . ' AND `id` <> ' . $id);
            if ($taken) {
                throw self::fieldError('email', 'Another account (#' . $taken['id'] . ') uses that email.');
            }
            $set['email'] = $email;
        }
        if (array_key_exists('phone', $input)) {
            $phone = trim((string) $input['phone']);
            if ($phone !== '' && !preg_match('/^\+?[\d\s().\-]{5,30}$/', $phone)) {
                throw self::fieldError('phone', 'Digits, spaces and + ( ) - only, like +44 7700 900123.');
            }
            $set['phone'] = $phone === '' ? null : $phone;
        }
        if (array_key_exists('twoFactorMethod', $input)) {
            $method = (string) $input['twoFactorMethod'];
            if (!in_array($method, self::TWO_FACTOR, true)) {
                throw self::fieldError('twoFactorMethod', 'Email codes, or none.');
            }
            $set['two_factor_method'] = $method;
        }
        if (!empty($input['removePhoto'])) {
            $set['image'] = null;
        }
        if (!$set) {
            throw new RuntimeException('Nothing to change.');
        }
        foreach ($set as $column => $value) {
            if ($column === 'image') {
                $before['photo'] = $user['image'] ? 'set' : null;
                $after['photo'] = null;
                continue;
            }
            if ((string) ($user[$column] ?? '') !== (string) ($value ?? '')) {
                $before[$column] = $user[$column];
                $after[$column] = $value;
            }
        }
        $this->update('users', $set, '`id` = ' . $id);

        return [$before, $after];
    }

    /** Headline and bio in one role (`role_profiles`). */
    public function setRoleText(int $id, string $role, array $input): array
    {
        $this->user($id);
        $role = self::role($role);
        $headline = trim((string) ($input['headline'] ?? ''));
        $bio = trim((string) ($input['bio'] ?? ''));
        if (mb_strlen($headline) > 160) {
            throw self::fieldError('headline', 'At most 160 characters.');
        }
        if (mb_strlen($bio) > 2000) {
            throw self::fieldError('bio', 'At most 2,000 characters.');
        }
        $old = $this->db->db_GetRow('SELECT `headline`, `bio` FROM `role_profiles` WHERE `user_id` = ' . $id . ' AND `role` = "' . $role . '"') ?: ['headline' => null, 'bio' => null];
        $this->exec(
            'INSERT INTO `role_profiles` (`user_id`, `role`, `headline`, `bio`) VALUES (' . $id . ', "' . $role . '", '
            . $this->quote($headline === '' ? null : $headline) . ', ' . $this->quote($bio === '' ? null : $bio) . ')
             ON DUPLICATE KEY UPDATE `headline` = VALUES(`headline`), `bio` = VALUES(`bio`)'
        );

        return [['role' => $role] + $old, ['role' => $role, 'headline' => $headline ?: null, 'bio' => $bio ?: null]];
    }

    /** Date of birth, gender, a guide's price fields, a traveller's party and trip length. */
    public function updateProfile(int $id, array $input): array
    {
        $this->user($id);
        $set = [];
        if (array_key_exists('dateOfBirth', $input)) {
            $dob = trim((string) $input['dateOfBirth']);
            if ($dob !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !strtotime($dob) || $dob > gmdate('Y-m-d'))) {
                throw self::fieldError('dateOfBirth', 'A date in the past, YYYY-MM-DD.');
            }
            $set['date_of_birth'] = $dob === '' ? null : $dob;
        }
        if (array_key_exists('gender', $input)) {
            $gender = (string) $input['gender'];
            if (!in_array($gender, ['', 'male', 'female'], true)) {
                throw self::fieldError('gender', 'Male, female, or not given.');
            }
            $set['gender'] = $gender === '' ? null : $gender;
        }
        if (array_key_exists('priceAmount', $input)) {
            $price = $input['priceAmount'];
            if ($price !== null && $price !== '' && (!is_numeric($price) || (float) $price < 0 || (float) $price > 10000)) {
                throw self::fieldError('priceAmount', 'A price from 0 to 10,000, or empty for free.');
            }
            $set['price_amount'] = $price === null || $price === '' ? null : round((float) $price, 2);
        }
        foreach (['priceLabel' => ['price_label', 80], 'priceNote' => ['price_note', 120], 'party' => ['party', 160], 'includes' => ['includes', 2000]] as $field => [$column, $max]) {
            if (array_key_exists($field, $input)) {
                $text = trim((string) $input[$field]);
                if (mb_strlen($text) > $max) {
                    throw self::fieldError($field, 'At most ' . number_format($max) . ' characters.');
                }
                $set[$column] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('durationHours', $input)) {
            $hours = $input['durationHours'];
            if ($hours !== null && $hours !== '' && (!is_numeric($hours) || (int) $hours < 1 || (int) $hours > 720)) {
                throw self::fieldError('durationHours', 'From 1 to 720 hours, or empty.');
            }
            $set['duration_hours'] = $hours === null || $hours === '' ? null : (int) $hours;
        }
        if (!$set) {
            throw new RuntimeException('Nothing to change.');
        }
        $old = $this->db->db_GetRow('SELECT * FROM `user_profiles` WHERE `user_id` = ' . $id) ?: [];
        $columns = array_keys($set);
        $this->exec(
            'INSERT INTO `user_profiles` (`user_id`, `' . implode('`, `', $columns) . '`) VALUES (' . $id . ', '
            . implode(', ', array_map([$this, 'quote'], array_values($set))) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn(string $c): string => '`' . $c . '` = VALUES(`' . $c . '`)', $columns))
        );

        return [array_intersect_key($old, $set), $set];
    }

    /** Gives the user a role they don't hold. Their app shows it after their next renewal (≤ 15 min). */
    public function addRole(int $id, string $role): array
    {
        $this->user($id);
        $role = self::role($role);
        if (in_array($role, Roles::of($id, $this->db), true)) {
            throw new RuntimeException('They already have that role.');
        }
        $this->exec(
            'INSERT INTO `user_role` (`user_id`, `role_id`) SELECT ' . $id . ', `id` FROM `roles` WHERE `name` = "' . $role . '"'
        );

        return [null, ['role' => $role]];
    }

    /**
     * Takes a role away — not their only one, and not while they have open
     * bookings or active matches in it. Ends their sign-ins, so every device
     * picks up the change at once.
     */
    public function removeRole(int $id, string $role): array
    {
        $this->user($id);
        $role = self::role($role);
        $roles = Roles::of($id, $this->db);
        if (!in_array($role, $roles, true)) {
            throw new RuntimeException('They don’t have that role.');
        }
        if (count($roles) < 2) {
            throw new RuntimeException('That’s their only role; freeze the account instead.');
        }
        $side = $role === 'guide' ? 'guide_id' : 'guest_id';
        $open = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS `n` FROM `bookings` WHERE `' . $side . '` = ' . $id . '
               AND (`status` = "pending" OR (`status` = "confirmed" AND `trip_status` <> "finished"))',
            'n'
        );
        $matches = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS `n` FROM `selections` WHERE `' . $side . '` = ' . $id . ' AND `active` = 1',
            'n'
        );
        if ($open || $matches) {
            throw new RuntimeException("They have {$open} open booking(s) and {$matches} active match(es) in that role — settle those first.");
        }
        $this->exec(
            'DELETE ur FROM `user_role` ur JOIN `roles` r ON r.`id` = ur.`role_id` WHERE ur.`user_id` = ' . $id . ' AND r.`name` = "' . $role . '"'
        );
        Revocations::endAllSignIns($this->db, $this->redis, $id, 'staff_role_removed');

        return [['role' => $role], null];
    }

    /** Marks the identity checked (`method`: id_card | passport | driving_licence), or clears it (null). */
    public function setVerification(int $id, ?string $method): array
    {
        $user = $this->user($id);
        if ($method !== null && !in_array($method, self::VERIFICATION, true)) {
            throw self::fieldError('method', 'ID card, passport or driving licence.');
        }
        $this->exec(
            'UPDATE `users` SET `verified_at` = ' . ($method === null ? 'NULL' : 'COALESCE(`verified_at`, UTC_TIMESTAMP())')
            . ', `verification_method` = ' . $this->quote($method) . ' WHERE `id` = ' . $id
        );

        return [['method' => $user['verification_method'], 'verifiedAt' => $user['verified_at']], ['method' => $method]];
    }

    /** −N levels across both roles, suspending bonuses, until `days` pass (null = until lifted). */
    public function addSanction(int $id, array $input, array $staff): array
    {
        $this->user($id);
        $levels = (int) ($input['levels'] ?? 0);
        if ($levels < 1 || $levels > 7) {
            throw self::fieldError('levels', 'From 1 to 7 levels.');
        }
        $reason = $this->reason($input['reason'] ?? '', 255);
        $expires = $this->expiry($input['days'] ?? null);
        $complaint = isset($input['complaintId']) && $input['complaintId'] !== '' && $input['complaintId'] !== null ? (int) $input['complaintId'] : null;
        if ($complaint && !$this->db->db_GetRow('SELECT `id` FROM `complaints` WHERE `id` = ' . $complaint)) {
            throw self::fieldError('complaintId', 'No complaint #' . $complaint . '.');
        }
        $this->exec(
            'INSERT INTO `user_sanctions` (`user_id`, `levels`, `reason`, `complaint_id`, `staff_id`, `staff_email`, `created_at`, `expires_at`) VALUES ('
            . $id . ', ' . $levels . ', ' . $this->quote($reason) . ', ' . $this->quote($complaint) . ', ' . (int) $staff['id'] . ', '
            . $this->quote((string) $staff['email']) . ', UTC_TIMESTAMP(), ' . $expires . ')'
        );

        return [null, ['sanction' => (int) $this->db->db_GetLastId(), 'levels' => $levels, 'reason' => $reason, 'days' => $input['days'] ?? null]];
    }

    public function liftSanction(int $id, int $sanctionId, array $staff): array
    {
        return $this->lift('user_sanctions', $id, $sanctionId, $staff, 'sanction');
    }

    /** A signed level change in one role (`role` null = both), until `days` pass (null = until lifted). */
    public function addAdjustment(int $id, array $input, array $staff): array
    {
        $this->user($id);
        $role = $input['role'] ?? null;
        $role = $role === '' || $role === null ? null : self::role((string) $role);
        $delta = (int) ($input['delta'] ?? 0);
        if ($delta === 0 || $delta < -7 || $delta > 7) {
            throw self::fieldError('delta', 'From −7 to +7, not 0.');
        }
        $reason = $this->reason($input['reason'] ?? '', 255);
        $expires = $this->expiry($input['days'] ?? null);
        $this->exec(
            'INSERT INTO `level_adjustments` (`user_id`, `role`, `delta`, `reason`, `expires_at`, `staff_id`, `staff_email`, `created_at`) VALUES ('
            . $id . ', ' . $this->quote($role) . ', ' . $delta . ', ' . $this->quote($reason) . ', ' . $expires . ', '
            . (int) $staff['id'] . ', ' . $this->quote((string) $staff['email']) . ', UTC_TIMESTAMP())'
        );

        return [null, ['adjustment' => (int) $this->db->db_GetLastId(), 'role' => $role, 'delta' => $delta, 'reason' => $reason]];
    }

    public function liftAdjustment(int $id, int $adjustmentId, array $staff): array
    {
        return $this->lift('level_adjustments', $id, $adjustmentId, $staff, 'adjustment');
    }

    /** A badge shown on their cards and profile ("Founding guide"), until `days` pass (null = until revoked). */
    public function addBadge(int $id, array $input, array $staff): array
    {
        $this->user($id);
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 40) {
            throw self::fieldError('label', 'A label of up to 40 characters.');
        }
        ContactFilter::assertClean($label, 'label');
        $icon = trim((string) ($input['icon'] ?? '★'));
        if ($icon === '' || mb_strlen($icon) > 2 || preg_match('/[\p{L}\p{N}]/u', $icon)) {
            throw self::fieldError('icon', 'One symbol or emoji, like ★ or 🏅.');
        }
        $expires = $this->expiry($input['days'] ?? null);
        $this->exec(
            'INSERT INTO `user_badges` (`user_id`, `label`, `icon`, `expires_at`, `staff_id`, `staff_email`, `created_at`) VALUES ('
            . $id . ', ' . $this->quote($label) . ', ' . $this->quote($icon) . ', ' . $expires . ', '
            . (int) $staff['id'] . ', ' . $this->quote((string) $staff['email']) . ', UTC_TIMESTAMP())'
        );

        return [null, ['badge' => (int) $this->db->db_GetLastId(), 'label' => $label, 'icon' => $icon]];
    }

    public function revokeBadge(int $id, int $badgeId, array $staff): array
    {
        $badge = $this->db->db_GetRow('SELECT * FROM `user_badges` WHERE `id` = ' . $badgeId . ' AND `user_id` = ' . $id);
        if (!$badge || $badge['revoked_at'] !== null) {
            throw new RuntimeException('No such badge, or it’s already revoked.');
        }
        $this->exec(
            'UPDATE `user_badges` SET `revoked_at` = UTC_TIMESTAMP(), `revoked_by` = ' . $this->quote((string) $staff['email'])
            . ' WHERE `id` = ' . $badgeId
        );

        return [['badge' => $badgeId, 'label' => $badge['label']], null];
    }

    /**
     * A bonus package: `kind` free_picks | no_fee, `days` 1–365. Left for the
     * user to claim on their profile (like the level ones), or started now
     * (`startNow`) — after any running one of the same kind.
     */
    public function addBonus(int $id, array $input, array $staff): array
    {
        $this->user($id);
        $kind = (string) ($input['kind'] ?? '');
        if (!in_array($kind, ['free_picks', 'no_fee'], true)) {
            throw self::fieldError('kind', 'Free picks (travellers) or no fee (guides).');
        }
        $days = (int) ($input['days'] ?? 0);
        if ($days < 1 || $days > 365) {
            throw self::fieldError('days', 'From 1 to 365 days.');
        }
        $note = $this->reason($input['note'] ?? '', 255);
        $reason = 'staff_' . bin2hex(random_bytes(6));
        $start = 'GREATEST(UTC_TIMESTAMP(), COALESCE((SELECT MAX(`ends_at`) FROM (
                SELECT `ends_at` FROM `bonus_grants` WHERE `user_id` = ' . $id . ' AND `kind` = "' . $kind . '"
                  AND `ends_at` > UTC_TIMESTAMP() AND `revoked_at` IS NULL) x), UTC_TIMESTAMP()))';
        $this->exec(
            'INSERT INTO `bonus_grants` (`user_id`, `kind`, `days`, `reason`, `granted_at`, `starts_at`, `ends_at`, `staff_email`, `note`) VALUES ('
            . $id . ', "' . $kind . '", ' . $days . ', "' . $reason . '", UTC_TIMESTAMP(), '
            . (!empty($input['startNow']) ? $start . ', ' . $start . ' + INTERVAL ' . $days . ' DAY' : 'NULL, NULL') . ', '
            . $this->quote((string) $staff['email']) . ', ' . $this->quote($note) . ')'
        );

        return [null, ['bonus' => (int) $this->db->db_GetLastId(), 'kind' => $kind, 'days' => $days, 'note' => $note, 'started' => !empty($input['startNow'])]];
    }

    /** Adds days to a bonus: to its length if unclaimed, to its end if claimed and not over. */
    public function extendBonus(int $id, int $bonusId, int $days): array
    {
        if ($days < 1 || $days > 365) {
            throw self::fieldError('days', 'From 1 to 365 days.');
        }
        $bonus = $this->bonus($id, $bonusId);
        if ($bonus['ends_at'] !== null && strtotime($bonus['ends_at'] . ' UTC') <= time()) {
            throw new RuntimeException('That bonus has run out; add a new one instead.');
        }
        $this->exec(
            'UPDATE `bonus_grants` SET `days` = `days` + ' . $days
            . ($bonus['ends_at'] !== null ? ', `ends_at` = `ends_at` + INTERVAL ' . $days . ' DAY' : '')
            . ' WHERE `id` = ' . $bonusId
        );

        return [['bonus' => $bonusId, 'days' => (int) $bonus['days']], ['bonus' => $bonusId, 'days' => (int) $bonus['days'] + $days]];
    }

    /** Ends a bonus at once (or withdraws an unclaimed one). */
    public function revokeBonus(int $id, int $bonusId): array
    {
        $bonus = $this->bonus($id, $bonusId);
        $this->exec('UPDATE `bonus_grants` SET `revoked_at` = UTC_TIMESTAMP() WHERE `id` = ' . $bonusId);

        return [['bonus' => $bonusId, 'kind' => $bonus['kind'], 'days' => (int) $bonus['days']], null];
    }

    // ------------------------------------------------------------- internals

    private function user(int $id): array
    {
        $user = $id > 0 ? $this->db->db_GetRow('SELECT * FROM `users` WHERE `id` = ' . $id) : null;
        if (!$user) {
            throw new RuntimeException('No such user.');
        }

        return $user;
    }

    private function bonus(int $id, int $bonusId): array
    {
        $bonus = $this->db->db_GetRow('SELECT * FROM `bonus_grants` WHERE `id` = ' . $bonusId . ' AND `user_id` = ' . $id);
        if (!$bonus || $bonus['revoked_at'] !== null) {
            throw new RuntimeException('No such bonus, or it’s revoked.');
        }

        return $bonus;
    }

    private function lift(string $table, int $id, int $rowId, array $staff, string $what): array
    {
        $row = $this->db->db_GetRow('SELECT * FROM `' . $table . '` WHERE `id` = ' . $rowId . ' AND `user_id` = ' . $id);
        if (!$row || $row['lifted_at'] !== null) {
            throw new RuntimeException("No such {$what}, or it’s already lifted.");
        }
        $this->exec(
            'UPDATE `' . $table . '` SET `lifted_at` = UTC_TIMESTAMP(), `lifted_by` = ' . $this->quote((string) $staff['email'])
            . ' WHERE `id` = ' . $rowId
        );

        return [[$what => $rowId, 'reason' => $row['reason']], null];
    }

    /** A required reason, trimmed, at most `$max` characters. */
    private function reason($text, int $max): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            throw self::fieldError('reason', 'Say why — it’s kept with the record.');
        }
        if (mb_strlen($text) > $max) {
            throw self::fieldError('reason', 'At most ' . $max . ' characters.');
        }

        return $text;
    }

    /** `days` → an SQL expiry: NULL (until lifted) or now + days (1–3650). */
    private function expiry($days): string
    {
        if ($days === null || $days === '') {
            return 'NULL';
        }
        if (!is_numeric($days) || (int) $days < 1 || (int) $days > 3650) {
            throw self::fieldError('days', 'From 1 to 3,650 days, or empty for no end.');
        }

        return 'UTC_TIMESTAMP() + INTERVAL ' . (int) $days . ' DAY';
    }

    private static function role(string $role): string
    {
        if ($role !== 'guest' && $role !== 'guide') {
            throw self::fieldError('role', 'Traveller or guide.');
        }

        return $role;
    }

    private function update(string $table, array $set, string $where): void
    {
        $parts = [];
        foreach ($set as $column => $value) {
            $parts[] = '`' . $column . '` = ' . $this->quote($value);
        }
        $this->exec('UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE ' . $where);
    }

    private function exec(string $sql): void
    {
        if (!$this->db->db_Execute($sql)) {
            throw new RuntimeException('Could not save that.');
        }
    }

    /** @param mixed $value */
    private function quote($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . $this->db->db_Escape((string) $value) . '"';
    }

    private static function fieldError(string $field, string $error): RuntimeException
    {
        return new RuntimeException((string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
