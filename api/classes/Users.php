<?php

/**
 * Data access for accounts as the API sees them: `users` (basic profile +
 * contact info + current role) and `roles`. Creating accounts and anything
 * touching passwords (`creds`) belongs to the auth service (`auth/classes/
 * Accounts.php`). `searchByRole()` additionally reaches into the
 * filterable-attribute tables owned by `Profiles` (`user_profiles` and
 * friends) to run discovery-deck filtering in SQL — see that method's
 * docblock. `getFullAccount()`/`updateAccount()` back the self-service
 * profile page (`ProfilePage.vue`); like every other mutating action they get
 * their `$userId` from the gateway's resolved session, never a request field
 * — see `RequestProcessor::requireUserId()`.
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

    public function findById(int $userId)
    {
        return $this->db->db_GetRowById($userId, 'users');
    }

    /** Whether `$userId` holds the app role `$roleName` (an account can hold both). */
    public function hasRole(int $userId, string $roleName): bool
    {
        return in_array($roleName, Roles::of($userId, $this->db), true);
    }

    /** @return string[] every app role `$userId` holds, registration role first. */
    public function getRoles(int $userId): array
    {
        return Roles::of($userId, $this->db);
    }

    public function getRoleName(int $userId): ?string
    {
        $name = $this->db->db_GetFieldFromQuery(
            'SELECT ' . Roles::nameSql((string) $userId) . ' AS `name`',
            'name'
        );
        return $name === false || $name === null ? null : $name;
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
     * decided-list (`stores/matches.ts`) doesn't survive one. Someone who
     * has marked the caller 'interested' while the caller hasn't answered
     * yet is deliberately still returned — that's how a match gets
     * completed from the deck. Anonymous callers (no session) see
     * everyone, as before.
     *
     * `$filters['includeDecided']` turns that exclusion off (the caller
     * themselves is still dropped): the client's `hydrateDecided()`
     * (`stores/guides.ts`) needs the guides the visitor has by definition
     * already decided on, so the Discover matches rail can name them.
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

        // Holding the role is the `urx` join below (their row for this role,
        // which also carries the headline and description they show in it).
        $where = ['1 = 1'];

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
            // column identifies the viewer depends on their side — and a deck
            // only ever pairs opposite roles: browsing guides is acting as a
            // guest, whatever else the account holds.
            $viewerRole = $this->hasRole($viewerId, Roles::opposite($roleName)) ? Roles::opposite($roleName) : null;
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
                  AND ao.`value` IN (' . $valueList . ') AND ' . Profiles::roleRowsSql('uav', $roleName) . '
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
                WHERE uar.`user_id` = u.`id` AND ac.`key` = "' . $this->db->db_Escape((string) $categoryKey) . '"
                  AND ' . Profiles::roleRowsSql('uar', $roleName);

            $where[] = '(NOT EXISTS (' . $ownRows . ')
                OR EXISTS (' . $ownRows . ' AND (' . implode(' OR ', $overlaps) . ')))';
        }

        // Best first — see `Ranking` for the score.
        $rows = $this->db->db_GetArray(
            'SELECT ' . self::PROFILE_COLUMNS . ', ' . Ranking::columns() . '
             FROM `users` u
             JOIN `user_role` urx ON urx.`user_id` = u.`id` AND urx.`role_id` = ' . (int) $roleId . '
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . self::reviewSummaryJoin($roleName) . '
             ' . Ranking::tripsJoin() . ' ' . Ranking::reviewsJoin(null, $roleName) . ' ' . Levels::sanctionsJoin() . '
             WHERE ' . implode(' AND ', $where) . '
             ' . Ranking::ORDER_BY
        );

        $userIds = array_map(fn($row) => (int) $row['id'], $rows);
        $attributes = $profiles->getAttributesFor($userIds, $roleName);

        foreach ($rows as &$row) {
            $row = Ranking::castRow($this->castReviewSummary($row));
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
    public function getPublicProfile(int $userId, Profiles $profiles, ?string $requestedRole = null): ?array
    {
        $roles = $this->getRoles($userId);
        if (!$roles) {
            return null;
        }
        // Shown as the role asked for (the page: a guide's or a traveller's),
        // when they hold it; else their first.
        $role = in_array($requestedRole, $roles, true) ? $requestedRole : $roles[0];
        $roleId = (int) $this->findRoleIdByName($role);

        $row = $this->db->db_GetRow(
            'SELECT ' . self::PROFILE_COLUMNS . ', ' . Ranking::columns() . '
             FROM `users` u
             JOIN `user_role` urx ON urx.`user_id` = u.`id` AND urx.`role_id` = ' . $roleId . '
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . self::reviewSummaryJoin($role, $userId) . '
             ' . Ranking::tripsJoin($userId) . ' ' . Ranking::reviewsJoin($userId, $role) . ' ' . Levels::sanctionsJoin($userId) . '
             WHERE u.`id` = ' . $userId
        );
        if (!$row) {
            return null;
        }

        $attributes = $profiles->getAttributesFor([$userId], $role);
        $row = Ranking::castRow($this->castReviewSummary($row));
        $row['role'] = $role;
        // Every role held — a guide's page and a traveller's page both
        // resolve for someone who is both.
        $row['roles'] = $roles;
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
                    p.`gender`, urx.`headline`, urx.`bio`, p.`price_label`, p.`price_note`,
                    p.`includes`, p.`party`, p.`duration_hours`,
                    rs.`rating_average`, COALESCE(rs.`review_count`, 0) AS `review_count`';

    /**
     * Every listed person's review count and average **as `$role`** (ratings
     * are per role — `reviews.subject_role`) in the same query as the list
     * itself, rather than one lookup per card — see `Reviews`. `$userId`
     * narrows it to one person (a profile page).
     */
    private static function reviewSummaryJoin(string $role, ?int $userId = null): string
    {
        return 'LEFT JOIN (
                SELECT `subject_id`, COUNT(*) AS `review_count`, ROUND(AVG(`rating`), 1) AS `rating_average`
                FROM `reviews` WHERE `subject_role` = "' . ($role === 'guide' ? 'guide' : 'guest') . '"'
                . ($userId ? ' AND `subject_id` = ' . $userId : '') . '
                GROUP BY `subject_id`
             ) rs ON rs.`subject_id` = u.`id`';
    }

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
    public function getFullAccount(int $userId, Profiles $profiles, string $role): array
    {
        $row = $this->db->db_GetRow(
            'SELECT u.`id`, u.`name`, u.`email`, u.`phone`, u.`two_factor_method`, u.`image`, u.`created_at`,
                    u.`verified_at` IS NOT NULL AS `verified`, u.`verification_method`,
                    p.`date_of_birth`, p.`gender`, urx.`headline`, urx.`bio`, p.`price_amount`, p.`price_label`, p.`price_note`,
                    p.`includes`, p.`party`, p.`duration_hours`
             FROM `users` u
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             LEFT JOIN `user_role` urx ON urx.`user_id` = u.`id` AND urx.`role_id` = ' . (int) $this->findRoleIdByName($role) . '
             WHERE u.`id` = ' . $userId
        );
        if (!$row) {
            throw new RuntimeException('Account not found.');
        }

        $row['role'] = $this->getRoleName($userId);
        $row['roles'] = $this->getRoles($userId);
        $row['verified'] = (bool) (int) $row['verified'];
        // Headline, description, picks and spans are the acting role's.
        $row['acting_role'] = $role;
        $row['attributes'] = (object) $profiles->getAttributeValuesFor($userId, $role);
        $row['ranges'] = (object) $profiles->getRangesFor($userId, $role);

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

    /**
     * How many unexpired devices skip the login code — shown on the profile
     * page. `trusted_devices` belongs to the auth service; this one read
     * stays here until the database is split (docs/scaling.md).
     */
    public function trustedDeviceCount(int $userId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `trusted_devices` WHERE `user_id` = ' . $userId . ' AND `expires_at` > UTC_TIMESTAMP()',
            'n'
        );
    }
}
