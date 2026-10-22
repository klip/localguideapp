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
     *   near?: {latitude, longitude}  — the search point: rows carry
     *     `distance_km` to it and closer people rank higher (`Ranking`).
     *     Browsing guides, it's measured to their base (`guide_bases`).
     *   maxKm?: int  — with `near`: only guides whose area comes within
     *     that distance (distance − their radius ≤ maxKm). A flexible guide
     *     (no radius) counts as 25 km (`Ranking::FLEXIBLE_RADIUS_KM`); a
     *     guide with no base never passes.
     *     Browsing travellers, both use their search area
     *     (`traveller_areas`, fresh for 30 days; rows carry `area_label`) or
     *     the nearest stop still ahead on their saved trips, whichever is
     *     closer; rows carry that stop as `next_stop` (without `near`, the
     *     soonest one).
     *   ids?: int[]  — server-side only: just these people (`TripPlanner`).
     */
    public function searchByRole(string $roleName, array $filters, Profiles $profiles, ?int $viewerId = null): array
    {
        $roleId = $this->findRoleIdByName($roleName);
        if (!$roleId) {
            return [];
        }

        // Holding the role is the `urx` join below (`user_role`); the headline
        // and description they show in it come from `rp` (`role_profiles`).
        // A frozen account (`user_freezes`, the CMS) is on no deck, and so in
        // no planner suggestion either.
        $where = ['1 = 1', Freezes::notFrozenSql('u.`id`')];

        if ($roleName === 'guide') {
            // A guide out on a trip isn't available: hidden until the trip's
            // end, or for 12 hours when its length isn't known (so a trip
            // nobody marks finished can't hide them for good).
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM `bookings` tb
                WHERE tb.`guide_id` = u.`id` AND tb.`status` = "confirmed" AND tb.`trip_status` = "started"
                  AND COALESCE(tb.`started_at`, tb.`meeting_at`) + INTERVAL ROUND(COALESCE(tb.`duration_hours`, 12) * 60) MINUTE > UTC_TIMESTAMP()
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

        // Server-side only (`readFilters()` never sets it): just these people —
        // the travel planner showing the guides it suggested.
        if (isset($filters['ids'])) {
            $ids = array_filter(array_map('intval', (array) $filters['ids']), fn($id) => $id > 0);
            $where[] = $ids ? 'u.`id` IN (' . implode(',', $ids) . ')' : '0 = 1';
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

        // Where each person is: a guide's base; a traveller's search area
        // (where they're looking for guides — only while fresh).
        $near = $filters['near'] ?? null;
        $maxKm = $filters['maxKm'] ?? null;
        $distanceSql = null;
        $radiusSql = null;
        $locationColumns = '';
        if ($roleName === 'guide') {
            $locationJoin = $near ? 'LEFT JOIN `guide_bases` gb ON gb.`user_id` = u.`id`' : '';
            if ($near) {
                $distanceSql = Places::distanceKmSql('gb.`point`', $near);
                $radiusSql = 'gb.`radius_km`';
                if ($maxKm) {
                    // A flexible guide (no radius) counts as covering 25 km
                    // here too, as in the ranking — "flexible" isn't "anywhere
                    // on Earth", so a guide in Bangkok stays out of a 1 km
                    // search in Lisbon.
                    $where[] = '(gb.`user_id` IS NOT NULL AND ' . $distanceSql . ' - COALESCE(gb.`radius_km`, ' . Ranking::FLEXIBLE_RADIUS_KM . ') <= ' . (int) $maxKm . ')';
                }
            }
        } else {
            $locationJoin = 'LEFT JOIN `traveller_areas` ta ON ta.`user_id` = u.`id`
                AND ta.`updated_at` > UTC_TIMESTAMP() - INTERVAL ' . self::SEARCH_AREA_DAYS . ' DAY';
            // …and the stops of their saved trips still ahead (`TripPlanner`):
            // a traveller is near wherever their area or one of those stops is.
            $stopsFrom = 'FROM `trip_plan_stops` tps JOIN `trip_plans` tp ON tp.`id` = tps.`plan_id`
                WHERE tp.`user_id` = u.`id` AND tps.`depart_on` >= UTC_DATE()';
            if ($near) {
                $areaKm = Places::distanceKmSql('ta.`point`', $near);
                $stopKm = Places::distanceKmSql('tps.`point`', $near);
                $nearestStopKm = '(SELECT MIN(' . $stopKm . ') ' . $stopsFrom . ')';
                // LEAST() is NULL if either is; this is the smaller of the two that exist.
                $distanceSql = 'LEAST(COALESCE(' . $areaKm . ', ' . $nearestStopKm . '), COALESCE(' . $nearestStopKm . ', ' . $areaKm . '))';
                $nextStopSql = '(SELECT tps.`id` ' . $stopsFrom . ' ORDER BY ' . $stopKm . ', tps.`arrive_on` LIMIT 1)';
                if ($maxKm) {
                    $where[] = '(' . $distanceSql . ' IS NOT NULL AND ' . $distanceSql . ' <= ' . (int) $maxKm . ')';
                }
            } else {
                $nextStopSql = '(SELECT tps.`id` ' . $stopsFrom . ' ORDER BY tps.`arrive_on`, tps.`id` LIMIT 1)';
            }
            $locationColumns = ', ta.`label` AS `area_label`, ' . $nextStopSql . ' AS `next_stop_id`';
        }

        // Best first — see `Ranking` for the score.
        $rows = $this->db->db_GetArray(
            'SELECT ' . self::PROFILE_COLUMNS . ', ' . Ranking::columns($roleName, $distanceSql, $radiusSql)
                . ($distanceSql ? ', ROUND(' . $distanceSql . ') AS `distance_km`' : '') . $locationColumns . '
             FROM `users` u
             JOIN `user_role` urx ON urx.`user_id` = u.`id` AND urx.`role_id` = ' . (int) $roleId . '
             LEFT JOIN `role_profiles` rp ON rp.`user_id` = u.`id` AND rp.`role` = "' . $this->db->db_Escape($roleName) . '"
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . $locationJoin . '
             ' . self::reviewSummaryJoin($roleName) . '
             ' . Ranking::tripsJoin(null, $roleName) . ' ' . Ranking::reviewsJoin(null, $roleName) . ' ' . Levels::sanctionsJoin(null, $roleName) . '
             WHERE ' . implode(' AND ', $where) . '
             ' . ($distanceSql
                // Searching near somewhere: people with no location go last.
                ? str_replace('ORDER BY ', 'ORDER BY `distance_km` IS NULL, ', Ranking::ORDER_BY)
                : Ranking::ORDER_BY)
        );

        $userIds = array_map(fn($row) => (int) $row['id'], $rows);
        $attributes = $profiles->getAttributesFor($userIds, $roleName);
        $awarded = $this->awardedBadges($userIds);
        $stops = $roleName === 'guest' ? $this->tripStops(array_column($rows, 'next_stop_id')) : [];

        foreach ($rows as &$row) {
            $row = Ranking::castRow($this->castReviewSummary($row));
            $id = (int) $row['id'];
            // Cast so an empty set encodes as a JSON object rather than `[]`
            // — the client types both of these as maps keyed by category.
            $row['attributes'] = (object) ($attributes['attributes'][$id] ?? []);
            $row['ranges'] = (object) ($attributes['ranges'][$id] ?? []);
            $row['badges'] = $awarded[$id] ?? [];
            if ($roleName === 'guest') {
                $row['next_stop'] = $stops[(int) $row['next_stop_id']] ?? null;
                unset($row['next_stop_id']);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Trip stops by id — the town and dates a guide may see on a traveller's
     * card ("Visiting Lisbon, 3–5 May"), never the trip itself.
     *
     * @return array<int, array{label: string, arriveOn: string, departOn: string}>
     */
    private function tripStops(array $stopIds): array
    {
        $ids = array_filter(array_map('intval', $stopIds));
        if (!$ids) {
            return [];
        }
        $stops = [];
        foreach ($this->db->db_GetArray(
            'SELECT `id`, `label`, `arrive_on`, `depart_on` FROM `trip_plan_stops` WHERE `id` IN (' . implode(',', array_unique($ids)) . ')'
        ) as $stop) {
            $stops[(int) $stop['id']] = ['label' => $stop['label'], 'arriveOn' => $stop['arrive_on'], 'departOn' => $stop['depart_on']];
        }

        return $stops;
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
        // A frozen account's page reads as "not found" while the freeze lasts.
        if (!$roles || Freezes::activeFor($this->db, $userId) !== null) {
            return null;
        }
        // Shown as the role asked for (the page: a guide's or a traveller's),
        // when they hold it; else their first.
        $role = in_array($requestedRole, $roles, true) ? $requestedRole : $roles[0];
        $roleId = (int) $this->findRoleIdByName($role);

        $row = $this->db->db_GetRow(
            'SELECT ' . self::PROFILE_COLUMNS . ', ' . Ranking::columns($role) . '
             FROM `users` u
             JOIN `user_role` urx ON urx.`user_id` = u.`id` AND urx.`role_id` = ' . $roleId . '
             LEFT JOIN `role_profiles` rp ON rp.`user_id` = u.`id` AND rp.`role` = "' . $this->db->db_Escape($role) . '"
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             ' . self::reviewSummaryJoin($role, $userId) . '
             ' . Ranking::tripsJoin($userId, $role) . ' ' . Ranking::reviewsJoin($userId, $role) . ' ' . Levels::sanctionsJoin($userId, $role) . '
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
        $row['badges'] = $this->awardedBadges([$userId])[$userId] ?? [];

        return $row;
    }

    /**
     * Badges staff awarded by hand (`user_badges`, 080) still in force, per
     * user: `[{ label, icon }]`, oldest first. Shown beside the earned ones.
     *
     * @param int[] $userIds
     * @return array<int, array<int, array{label: string, icon: string}>>
     */
    private function awardedBadges(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $out = [];
        foreach ((array) $this->db->db_GetArray(
            'SELECT `user_id`, `label`, `icon` FROM `user_badges`
             WHERE `user_id` IN (' . implode(',', array_map('intval', $userIds)) . ') AND `revoked_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())
             ORDER BY `id`'
        ) as $row) {
            $out[(int) $row['user_id']][] = ['label' => (string) $row['label'], 'icon' => (string) $row['icon']];
        }

        return $out;
    }

    /**
     * What `searchByRole()` and `getPublicProfile()` both show about a
     * person — never contact details: those are what a booking unlocks (see
     * `Bookings`), so the deck mustn't hand them out. `rating_average`/`review_count` come from
     * `REVIEW_SUMMARY_JOIN`, not from `user_profiles.rating`/`tours`, which
     * users used to be able to set themselves and are no longer read.
     */
    /** How long a traveller's search area counts as where they are. */
    private const SEARCH_AREA_DAYS = 30;

    private const PROFILE_COLUMNS = 'u.`id`, u.`name`, u.`image`, u.`created_at`,
                    TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) AS `age`,
                    p.`gender`, rp.`headline`, rp.`bio`, p.`price_amount`, p.`price_label`, p.`price_note`,
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
        // The guide's fee in pounds — the client shows it in the viewer's currency.
        if (array_key_exists('price_amount', $row)) {
            $row['price_amount'] = $row['price_amount'] === null ? null : (float) $row['price_amount'];
        }

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
                    p.`date_of_birth`, p.`gender`, rp.`headline`, rp.`bio`, p.`price_amount`, p.`price_label`, p.`price_note`,
                    p.`includes`, p.`party`, p.`duration_hours`
             FROM `users` u
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             LEFT JOIN `role_profiles` rp ON rp.`user_id` = u.`id` AND rp.`role` = "' . $this->db->db_Escape($role) . '"
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
        // Where they guide — only shown while editing as a guide.
        $row['guide_base'] = $role === 'guide' ? $profiles->getGuideBase($userId) : null;

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
