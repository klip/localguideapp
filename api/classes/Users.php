<?php

/**
 * Data access for accounts: `users` (basic profile + contact info + current
 * role), `roles` (guest/guide/admin/manager/viewer), `creds` (password hash
 * + salt, one row per user), and `user_role` (a log of role grants, written
 * on `register()`). `searchByRole()` additionally reaches into the
 * filterable-attribute tables owned by `Profiles` (`user_profiles` and
 * friends) to run discovery-deck filtering in SQL — see that method's
 * docblock. `getFullAccount()`/`updateAccount()`/`verifyPasswordFor()`/
 * `setPassword()` back the self-service profile page (`ProfilePage.vue`) —
 * see the note on `RequestProcessor`'s `updateAccount`/`changePassword`
 * actions: like every other mutating action, these get their
 * `$userId` from a resolved `Session`, not a raw request field — see
 * `RequestProcessor::requireUserId()`. `findById()` backs
 * `RequestProcessor::actionSession()`.
 */
class Users
{
    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    public function findRoleIdByName(string $roleName)
    {
        return $this->db->db_GetFieldFromQuery(
            'SELECT `id` FROM `roles` WHERE `name` = "' . $this->db->db_Escape($roleName) . '"',
            'id'
        );
    }

    public function findByEmail(string $email)
    {
        return $this->db->db_GetRow(
            'SELECT * FROM `users` WHERE `email` = "' . $this->db->db_Escape($email) . '"'
        );
    }

    /** Used by `RequestProcessor::actionSession()` to turn a resolved session's user id back into an `AuthResult`-shaped row. */
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
        $roleId = $this->findRoleIdByName($roleName);
        if (!$roleId) {
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

        $creds = $this->db->db_GetRow(
            'SELECT * FROM `creds` WHERE `user_id` = ' . intval($user['id'])
        );
        if (!$creds) {
            return false;
        }

        if (!Auth::verifyPassword($password, $creds['salt'], $creds['password'])) {
            return false;
        }

        return $user;
    }

    /**
     * Users of a role, filtered in SQL against `user_profiles` (scalar
     * fields) and the generic `attribute_categories`/`attribute_options`/
     * `user_attribute_values` tables (many-to-many, filtered by category
     * `key` — "languages"/"interests"), with `Profiles::getAttributesFor()`
     * attaching languages/activities/specialties to each row afterward.
     * Mirrors the semantics the client used to apply itself in
     * `utils/matching.ts::matchesFilters()` before filtering moved
     * server-side: every filter is optional and permissive when unset,
     * `maxAge` (compared against an age derived from `user_profiles.
     * date_of_birth` via `TIMESTAMPDIFF`) treats an unknown/unset date of
     * birth as passing, `language`/`activities` exclude a candidate with no
     * matching row (activities on overlap, not full containment).
     *
     * `$viewerId` — the caller, when the request carried a session (see
     * `RequestProcessor::optionalUserId()`) — drops every candidate that
     * user has already decided on, in either direction ('interested' or
     * 'pass'), plus the caller themselves. Without it the deck re-serves
     * matched and passed cards on every reload, because the client's own
     * decided-list (`stores/shortlist.ts`) doesn't survive one. Someone who
     * has marked the caller 'interested' while the caller hasn't answered
     * yet is deliberately still returned — that's how a match gets
     * completed from the deck. Anonymous callers (no session) see
     * everyone, as before.
     *
     * `$filters['includeDecided']` turns that exclusion off (the caller
     * themselves is still dropped): `ShortlistPage`
     * renders cards the visitor has by definition already decided on and
     * resolves them out of the same fetch, so there the deck rule would
     * empty the page.
     *
     * $filters (every key optional, absent never excludes):
     *   gender?: 'male'|'female'
     *   attributes?: array<string categoryKey, string[] values>  — AND across
     *     categories, OR within one, so naming two categories narrows
     *   ageRanges?: array<array{0:int,1:int}>  — matched against an age
     *     derived from `date_of_birth`; a candidate with no date of birth
     *     passes, same permissive-when-unknown rule as every other filter
     *   ranges?: array<string categoryKey, array<array{0:int,1:int}>>  — for
     *     `range` categories (hours of the day): matches a candidate whose
     *     own spans overlap any requested one, *or* who stored none at all
     *   includeDecided?: bool  — see below
     */
    public function searchByRole(string $roleName, array $filters, Profiles $profiles, ?int $viewerId = null): array
    {
        $roleId = $this->findRoleIdByName($roleName);
        if (!$roleId) {
            return [];
        }

        $where = ['u.`role_id` = ' . intval($roleId)];

        if ($roleName === 'guide') {
            // A guide out on a trip isn't available: hidden until the trip's
            // end, or for 12 hours when its length isn't known (so a trip
            // nobody marks finished can't hide them for good).
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM `bookings` tb
                WHERE tb.`guide_id` = u.`id` AND tb.`status` = "confirmed" AND tb.`trip_status` = "started"
                  AND tb.`meeting_at` + INTERVAL ROUND(COALESCE(tb.`duration_hours`, 12) * 60) MINUTE > UTC_TIMESTAMP()
            )';
        }

        if ($viewerId) {
            $where[] = 'u.`id` <> ' . $viewerId;

            // `selections` holds one row per (guest, guide) pair, so which
            // column identifies the viewer depends on their role — and a
            // deck only ever pairs opposite roles.
            $viewerRole = $this->getRoleName($viewerId);
            $hideDecided = empty($filters['includeDecided']);
            if ($hideDecided && $roleName === 'guide' && $viewerRole === 'guest') {
                $where[] = 'NOT EXISTS (
                    SELECT 1 FROM `selections` s
                    WHERE s.`guest_id` = ' . $viewerId . ' AND s.`guide_id` = u.`id` AND s.`guest_decision` IS NOT NULL
                )';
            } elseif ($hideDecided && $roleName === 'guest' && $viewerRole === 'guide') {
                $where[] = 'NOT EXISTS (
                    SELECT 1 FROM `selections` s
                    WHERE s.`guide_id` = ' . $viewerId . ' AND s.`guest_id` = u.`id` AND s.`guide_decision` IS NOT NULL
                )';
            }
        }

        $gender = $filters['gender'] ?? null;
        if ($gender && $gender !== 'any') {
            $where[] = 'p.`gender` = "' . $this->db->db_Escape((string) $gender) . '"';
        }

        // Age is derived from the date of birth rather than stored, so the
        // filter is a set of spans over a computed value; someone who hasn't
        // filled their date in passes every span.
        $ageClauses = [];
        foreach ($this->normaliseRanges($filters['ageRanges'] ?? []) as $range) {
            $ageClauses[] = 'TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) BETWEEN ' . $range[0] . ' AND ' . $range[1];
        }
        if ($ageClauses) {
            $where[] = '(p.`date_of_birth` IS NULL OR ' . implode(' OR ', $ageClauses) . ')';
        }

        // One EXISTS per named category: a candidate has to match something
        // in each of them (AND across, OR within), which is what makes
        // "English speaker who does food tours" narrow rather than widen.
        foreach ((array) ($filters['attributes'] ?? []) as $categoryKey => $values) {
            $values = array_filter(array_map('strval', (array) $values), fn($value) => trim($value) !== '');
            if (!$values) {
                continue;
            }

            $valueList = implode(',', array_map(fn($value) => '"' . $this->db->db_Escape($value) . '"', $values));
            $where[] = 'EXISTS (
                SELECT 1 FROM `user_attribute_values` uav
                JOIN `attribute_options` ao ON ao.`id` = uav.`option_id`
                JOIN `attribute_categories` ac ON ac.`id` = ao.`category_id`
                WHERE uav.`user_id` = u.`id` AND ac.`key` = "' . $this->db->db_Escape((string) $categoryKey) . '"
                  AND ao.`value` IN (' . $valueList . ')
            )';
        }

        // `range` categories (hours of the day, today). A candidate matches
        // when one of their own spans overlaps a requested one — or when
        // they stored no spans at all, which reads as unconstrained, so a
        // guide who never set their hours isn't hidden from someone
        // filtering by them. Wrapped in parentheses because the two halves
        // are OR'd inside a clause list that gets AND'd together.
        foreach ((array) ($filters['ranges'] ?? []) as $categoryKey => $ranges) {
            $overlaps = [];
            foreach ($this->normaliseRanges($ranges) as $range) {
                $overlaps[] = '(uar.`range_start` <= ' . $range[1] . ' AND uar.`range_end` >= ' . $range[0] . ')';
            }
            if (!$overlaps) {
                continue;
            }

            $ownRows = 'SELECT 1 FROM `user_attribute_ranges` uar
                JOIN `attribute_categories` ac ON ac.`id` = uar.`category_id`
                WHERE uar.`user_id` = u.`id` AND ac.`key` = "' . $this->db->db_Escape((string) $categoryKey) . '"';

            $where[] = '(NOT EXISTS (' . $ownRows . ')
                OR EXISTS (' . $ownRows . ' AND (' . implode(' OR ', $overlaps) . ')))';
        }

        $rows = $this->db->db_GetArray(
            'SELECT ' . self::PROFILE_COLUMNS . '
             FROM `users` u
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . self::REVIEW_SUMMARY_JOIN . '
             WHERE ' . implode(' AND ', $where)
        );

        $userIds = array_map(fn($row) => (int) $row['id'], $rows);
        $attributes = $profiles->getAttributesFor($userIds);

        foreach ($rows as &$row) {
            $row = $this->castReviewSummary($row);
            $id = (int) $row['id'];
            // Cast so an empty set encodes as a JSON object rather than `[]`
            // — the client types both of these as maps keyed by category.
            $row['attributes'] = (object) ($attributes['attributes'][$id] ?? []);
            $row['ranges'] = (object) ($attributes['ranges'][$id] ?? []);
        }
        unset($row);

        return $rows;
    }

    /**
     * The public face of one guest or guide, for `GuideProfilePage.vue` and
     * `VisitorProfilePage.vue` (`publicProfile` action): the same shape
     * `searchByRole()` returns per row, plus `role`, but with **no contact
     * details** — no email, no phone. Anyone can load it, signed in or not,
     * and it ignores every deck rule (decided-on or not, it resolves).
     * `null` for an unknown id or an account that isn't a guest/guide.
     */
    public function getPublicProfile(int $userId, Profiles $profiles): ?array
    {
        $role = $this->getRoleName($userId);
        if (!in_array($role, ['guest', 'guide'], true)) {
            return null;
        }

        $row = $this->db->db_GetRow(
            'SELECT ' . self::PROFILE_COLUMNS . '
             FROM `users` u
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . self::REVIEW_SUMMARY_JOIN . '
             WHERE u.`id` = ' . $userId
        );
        if (!$row) {
            return null;
        }

        $attributes = $profiles->getAttributesFor([$userId]);
        $row = $this->castReviewSummary($row);
        $row['role'] = $role;
        $row['attributes'] = (object) ($attributes['attributes'][$userId] ?? []);
        $row['ranges'] = (object) ($attributes['ranges'][$userId] ?? []);

        return $row;
    }

    /**
     * What `searchByRole()` and `getPublicProfile()` both show about a
     * person — never contact details: those are what a booking unlocks (see
     * `Bookings`), so the deck mustn't hand them out. `rating_average`/`review_count` come from
     * `REVIEW_SUMMARY_JOIN`, not from `user_profiles.rating`/`tours`, which
     * users used to be able to set themselves and are no longer read.
     */
    private const PROFILE_COLUMNS = 'u.`id`, u.`name`, u.`image`, u.`created_at`,
                    TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) AS `age`,
                    p.`gender`, p.`headline`, p.`bio`, p.`price_label`, p.`price_note`,
                    p.`includes`, p.`party`, p.`duration_hours`,
                    rs.`rating_average`, COALESCE(rs.`review_count`, 0) AS `review_count`';

    /**
     * Every listed person's review count and average in the same query as
     * the list itself, rather than one lookup per card — see `Reviews`.
     */
    private const REVIEW_SUMMARY_JOIN = 'LEFT JOIN (
                SELECT `subject_id`, COUNT(*) AS `review_count`, ROUND(AVG(`rating`), 1) AS `rating_average`
                FROM `reviews` GROUP BY `subject_id`
             ) rs ON rs.`subject_id` = u.`id`';

    /** mysqli returns strings; the client types these two as numbers. */
    private function castReviewSummary(array $row): array
    {
        $row['review_count'] = (int) $row['review_count'];
        $row['rating_average'] = $row['rating_average'] === null ? null : (float) $row['rating_average'];

        return $row;
    }

    /**
     * Coerces a `[[min, max], ...]` filter value into clean integer pairs,
     * dropping anything that isn't a usable pair and flipping a
     * back-to-front one rather than letting it match nothing silently.
     *
     * @return array<array{0:int,1:int}>
     */
    private function normaliseRanges($ranges): array
    {
        $out = [];
        foreach ((array) $ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }

            $start = (int) $range[0];
            $end = (int) $range[1];
            $out[] = $start <= $end ? [$start, $end] : [$end, $start];
        }

        return $out;
    }

    /**
     * The full self-view `ProfilePage.vue` loads on mount: the `users` row
     * (including `phone`/`two_factor_method`/`image`), the current role
     * name, `user_profiles`' scalar fields (raw `date_of_birth`, not a
     * computed age — the edit form needs the actual date for its calendar
     * input), every category's current selections (`Profiles::
     * getAttributeValuesFor()`), and specialty tags. Unlike `searchByRole()`
     * this is a single-user, unfiltered fetch — the caller already knows
     * which account it wants.
     */
    public function getFullAccount(int $userId, Profiles $profiles): array
    {
        $row = $this->db->db_GetRow(
            'SELECT u.`id`, u.`name`, u.`email`, u.`phone`, u.`two_factor_method`, u.`image`, u.`created_at`,
                    p.`date_of_birth`, p.`gender`, p.`headline`, p.`bio`, p.`price_amount`, p.`price_label`, p.`price_note`,
                    p.`includes`, p.`party`, p.`duration_hours`
             FROM `users` u
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             WHERE u.`id` = ' . $userId
        );
        if (!$row) {
            throw new RuntimeException('Account not found.');
        }

        $row['role'] = $this->getRoleName($userId);
        $row['attributes'] = (object) $profiles->getAttributeValuesFor($userId);
        $row['ranges'] = (object) $profiles->getRangesFor($userId);

        return $row;
    }

    /** Current phone on file, or `false` if the account has none — used by `RequestProcessor::actionUpdateAccount()`'s SMS-2FA guard. */
    public function getPhone(int $userId)
    {
        return $this->db->db_GetFieldBy('phone', $userId, 'users');
    }

    /**
     * Updates whichever of `name`/`email`/`phone`/`two_factor_method`/`image`
     * are present in $fields (validation — email format/uniqueness, 2FA
     * requiring a phone — is `RequestProcessor::actionUpdateAccount()`'s job,
     * same validate-in-the-processor/persist-in-the-class split as
     * `register()`/`actionRegister()`).
     */
    public function updateAccount(int $userId, array $fields): void
    {
        if (!$fields) {
            return;
        }

        $this->db->db_Update('users', $fields, 'WHERE `id` = ' . $userId);
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

    /** Re-salts and re-hashes the account's password. Caller (`RequestProcessor::actionChangePassword()`) must verify the current password first. */
    public function setPassword(int $userId, string $newPassword): void
    {
        $salt = Auth::generateSalt();
        $hash = Auth::hashPassword($newPassword, $salt);

        $this->db->db_Update('creds', ['password' => $hash, 'salt' => $salt], 'WHERE `user_id` = ' . $userId);
    }
}
