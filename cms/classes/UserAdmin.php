<?php

/**
 * The CMS's Users section (docs/auth-cms-plan.md, phase 8): finding app
 * users and everything staff see and change about one.
 *
 * Levels are computed with the app's own SQL (`Ranking::tripsJoin()`,
 * `Levels::sanctionsJoin()`, `Levels::effectiveLevelSql()`), so the CMS shows
 * exactly the level the app uses — sanctions and staff adjustments included.
 */
final class UserAdmin
{
    public const PAGE_SIZE = 50;
    private const SORTS = [
        'id' => 'u.`id`',
        'name' => 'u.`name`',
        'email' => 'u.`email`',
        'created' => 'u.`created_at`',
        'guestLevel' => '`guest_level`',
        'guideLevel' => '`guide_level`',
    ];

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * One page of users matching `$input`:
     *
     * - `q`: `#123` or a short number → that id; something with `@` → email;
     *   mostly digits (5+) → phone, formatting ignored; else name, email or
     *   location (a guide's base or a traveller's area — "Lisbon", "Portugal").
     * - `role` guest | guide | both; `country` (ISO 2) and `city`
     *   (`"PT|Lisbon"`, from `locations()`) match a guide's base or a
     *   traveller's search area.
     * - `levelRole` guest | guide with `levelMin` / `levelMax` (effective
     *   level in that role); `verified` / `frozen` yes | no;
     *   `createdFrom` / `createdTo` (YYYY-MM-DD, inclusive).
     * - `sort` id | name | email | created | guestLevel | guideLevel,
     *   `dir` asc | desc; `page` from 1.
     *
     * @return array{users: array, total: int, page: int, pageSize: int}
     */
    public function search(array $input): array
    {
        $where = ['1'];
        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $where[] = $this->queryCondition($q);
        }

        $role = (string) ($input['role'] ?? '');
        if ($role === 'guest' || $role === 'guide') {
            $where[] = Roles::hasSql('u.`id`', $role, $this->db);
        } elseif ($role === 'both') {
            $where[] = Roles::hasSql('u.`id`', 'guest', $this->db) . ' AND ' . Roles::hasSql('u.`id`', 'guide', $this->db);
        }

        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        if ($country !== '') {
            if (!preg_match('/^[A-Z]{2}$/', $country)) {
                throw new RuntimeException('A country is a two-letter code, like PT.');
            }
            $where[] = '(gb.`country_code` = "' . $country . '" OR ta.`country_code` = "' . $country . '")';
        }

        $city = trim((string) ($input['city'] ?? ''));
        if ($city !== '') {
            [$code, $town] = array_pad(explode('|', $city, 2), 2, '');
            if (!preg_match('/^[A-Z]{2}$/', $code) || trim($town) === '') {
                throw new RuntimeException('Pick a city from the list.');
            }
            $town = '"' . $this->db->db_Escape(trim($town)) . '"';
            $where[] = '((gb.`country_code` = "' . $code . '" AND ' . self::townSql('gb') . ' = ' . $town . ')'
                . ' OR (ta.`country_code` = "' . $code . '" AND ' . self::townSql('ta') . ' = ' . $town . '))';
        }

        $levelRole = (string) ($input['levelRole'] ?? '');
        if ($levelRole === 'guest' || $levelRole === 'guide') {
            $level = Levels::effectiveLevelSql($levelRole === 'guide' ? 'tsd' : 'tsg', $levelRole === 'guide' ? 'sxd' : 'sxg');
            $where[] = Roles::hasSql('u.`id`', $levelRole, $this->db);
            if (isset($input['levelMin']) && $input['levelMin'] !== '') {
                $where[] = $level . ' >= ' . max(0, (int) $input['levelMin']);
            }
            if (isset($input['levelMax']) && $input['levelMax'] !== '') {
                $where[] = $level . ' <= ' . max(0, (int) $input['levelMax']);
            }
        }

        $verified = (string) ($input['verified'] ?? '');
        if ($verified === 'yes' || $verified === 'no') {
            $where[] = 'u.`verified_at` IS ' . ($verified === 'yes' ? 'NOT ' : '') . 'NULL';
        }
        $frozen = (string) ($input['frozen'] ?? '');
        if ($frozen === 'yes' || $frozen === 'no') {
            $where[] = $frozen === 'yes' ? Freezes::isFrozenSql('u.`id`') : Freezes::notFrozenSql('u.`id`');
        }

        foreach (['createdFrom' => '>=', 'createdTo' => '<'] as $field => $op) {
            $date = trim((string) ($input[$field] ?? ''));
            if ($date === '') {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                throw new RuntimeException('Dates are YYYY-MM-DD.');
            }
            $bound = $field === 'createdTo' ? gmdate('Y-m-d', (int) strtotime($date . ' +1 day')) : $date;
            $where[] = 'u.`created_at` ' . $op . ' "' . $bound . '"';
        }

        $sort = self::SORTS[(string) ($input['sort'] ?? 'id')] ?? self::SORTS['id'];
        $dir = strtolower((string) ($input['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $page = max(1, (int) ($input['page'] ?? 1));

        $from = ' FROM `users` u
            LEFT JOIN `guide_bases` gb ON gb.`user_id` = u.`id`
            LEFT JOIN `traveller_areas` ta ON ta.`user_id` = u.`id`
            ' . Ranking::tripsJoin(null, 'guest', 'tsg') . '
            ' . Ranking::tripsJoin(null, 'guide', 'tsd') . '
            ' . Levels::sanctionsJoin(null, 'guest', 'sxg') . '
            ' . Levels::sanctionsJoin(null, 'guide', 'sxd') . '
            WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n`' . $from, 'n');
        $rows = (array) $this->db->db_GetArray(
            'SELECT u.`id`, u.`name`, u.`email`, u.`phone`, u.`image`, u.`created_at`, u.`verified_at`,
                    (SELECT GROUP_CONCAT(r.`name` ORDER BY ur.`id`) FROM `user_role` ur JOIN `roles` r ON r.`id` = ur.`role_id`
                      WHERE ur.`user_id` = u.`id` AND r.`name` IN ("guest", "guide")) AS `roles`,
                    gb.`label` AS `base_label`, ta.`label` AS `area_label`,
                    ' . Levels::effectiveLevelSql('tsg', 'sxg') . ' AS `guest_level`,
                    ' . Levels::effectiveLevelSql('tsd', 'sxd') . ' AS `guide_level`,
                    (SELECT UNIX_TIMESTAMP(MAX(COALESCE(f.`ends_at`, "3000-01-01"))) FROM `user_freezes` f
                      WHERE f.`user_id` = u.`id` AND ' . Freezes::activeSql('f') . ') AS `frozen_until_ts`'
            . $from . '
             ORDER BY ' . $sort . ' ' . $dir . ', u.`id` DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE)
        );

        return [
            'users' => array_map([$this, 'listRow'], $rows),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    /**
     * Everything about one user for the user page, section by section.
     * `$withSecurity` (admins) adds devices, sign-ins and linked accounts.
     */
    public function detail(int $id, bool $withSecurity): array
    {
        $u = $this->db->db_GetRow('SELECT * FROM `users` WHERE `id` = ' . $id);
        if (!$u) {
            throw new RuntimeException('No such user.');
        }
        $roles = Roles::of($id, $this->db);
        $profile = $this->db->db_GetRow('SELECT * FROM `user_profiles` WHERE `user_id` = ' . $id) ?: [];
        $levels = new Levels($this->db);

        $perRole = [];
        foreach (['guest', 'guide'] as $role) {
            $text = $this->db->db_GetRow('SELECT `headline`, `bio`, `level` FROM `role_profiles` WHERE `user_id` = ' . $id . ' AND `role` = "' . $role . '"') ?: [];
            $record = $levels->recordFor($id, $role);
            $reviews = $this->db->db_GetRow(
                'SELECT COUNT(*) AS `n`, ROUND(AVG(`rating`), 2) AS `avg`, COUNT(DISTINCT `author_id`) AS `authors`
                 FROM `reviews` WHERE `subject_id` = ' . $id . ' AND `subject_role` = "' . $role . '"'
            ) ?: [];
            $perRole[$role] = [
                'held' => in_array($role, $roles, true),
                'headline' => $text['headline'] ?? null,
                'bio' => $text['bio'] ?? null,
                'announcedLevel' => (int) ($text['level'] ?? 0),
                'trips' => $record['trips'],
                'paidTrips' => $record['paid'],
                'points' => $record['points'],
                'earnedLevel' => Levels::levelForPoints($record['points']),
                'adjustment' => $levels->activeAdjustment($id, $role),
                'effectiveLevel' => $levels->effectiveLevel($id, $role),
                'reviews' => ['count' => (int) ($reviews['n'] ?? 0), 'average' => $reviews['avg'] !== null ? (float) $reviews['avg'] : null],
                'topRated' => (int) ($reviews['authors'] ?? 0) >= 5 && (float) ($reviews['avg'] ?? 0) >= 4.6,
                'volunteer' => $role === 'guide' && $record['trips'] > 0 && $record['paid'] === 0,
                'attributes' => $this->attributesFor($id, $role),
            ];
        }

        $base = $this->db->db_GetRow('SELECT `label`, `country_code`, `radius_km` FROM `guide_bases` WHERE `user_id` = ' . $id);
        $area = $this->db->db_GetRow('SELECT `label`, `country_code`, `updated_at` FROM `traveller_areas` WHERE `user_id` = ' . $id);

        $detail = [
            'account' => [
                'id' => $id,
                'name' => (string) ($u['name'] ?? ''),
                'email' => (string) $u['email'],
                'phone' => $u['phone'],
                'image' => $u['image'] ?: null,
                'twoFactorMethod' => (string) $u['two_factor_method'],
                'createdAt' => self::iso($u['created_at']),
                'roles' => $roles,
            ],
            'profile' => [
                'dateOfBirth' => $profile['date_of_birth'] ?? null,
                'gender' => $profile['gender'] ?? null,
                'priceAmount' => isset($profile['price_amount']) ? (float) $profile['price_amount'] : null,
                'priceLabel' => $profile['price_label'] ?? null,
                'priceNote' => $profile['price_note'] ?? null,
                'includes' => $profile['includes'] ?? null,
                'party' => $profile['party'] ?? null,
                'durationHours' => isset($profile['duration_hours']) ? (int) $profile['duration_hours'] : null,
                'guideBase' => $base ? ['label' => $base['label'], 'countryCode' => $base['country_code'], 'radiusKm' => $base['radius_km'] !== null ? (int) $base['radius_km'] : null] : null,
                'travellerArea' => $area ? ['label' => $area['label'], 'countryCode' => $area['country_code'], 'updatedAt' => self::iso($area['updated_at'])] : null,
            ],
            'roles' => $perRole,
            'verification' => [
                'verifiedAt' => self::iso($u['verified_at']),
                'method' => $u['verification_method'],
            ],
            'sanctions' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'levels' => (int) $r['levels'],
                'reason' => (string) $r['reason'],
                'complaintId' => $r['complaint_id'] !== null ? (int) $r['complaint_id'] : null,
                'createdAt' => self::iso($r['created_at']),
                'expiresAt' => self::iso($r['expires_at']),
                'liftedAt' => self::iso($r['lifted_at']),
                'by' => (string) ($r['staff_email'] ?? ''),
                'active' => $r['lifted_at'] === null && ($r['expires_at'] === null || strtotime($r['expires_at'] . ' UTC') > time()),
            ], (array) $this->db->db_GetArray('SELECT * FROM `user_sanctions` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC')),
            'adjustments' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'role' => $r['role'],
                'delta' => (int) $r['delta'],
                'reason' => (string) $r['reason'],
                'createdAt' => self::iso($r['created_at']),
                'expiresAt' => self::iso($r['expires_at']),
                'liftedAt' => self::iso($r['lifted_at']),
                'by' => (string) $r['staff_email'],
                'active' => $r['lifted_at'] === null && ($r['expires_at'] === null || strtotime($r['expires_at'] . ' UTC') > time()),
            ], (array) $this->db->db_GetArray('SELECT * FROM `level_adjustments` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC')),
            'badges' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'label' => (string) $r['label'],
                'icon' => (string) $r['icon'],
                'createdAt' => self::iso($r['created_at']),
                'expiresAt' => self::iso($r['expires_at']),
                'revokedAt' => self::iso($r['revoked_at']),
                'by' => (string) $r['staff_email'],
                'active' => $r['revoked_at'] === null && ($r['expires_at'] === null || strtotime($r['expires_at'] . ' UTC') > time()),
            ], (array) $this->db->db_GetArray('SELECT * FROM `user_badges` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC')),
            'bonuses' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'kind' => (string) $r['kind'],
                'days' => (int) $r['days'],
                'reason' => (string) $r['reason'],
                'note' => $r['note'],
                'grantedAt' => self::iso($r['granted_at']),
                'startsAt' => self::iso($r['starts_at']),
                'endsAt' => self::iso($r['ends_at']),
                'revokedAt' => self::iso($r['revoked_at']),
                'by' => $r['staff_email'],
                'state' => $r['revoked_at'] !== null ? 'revoked'
                    : ($r['starts_at'] === null ? 'unclaimed'
                    : (strtotime($r['ends_at'] . ' UTC') > time() ? (strtotime($r['starts_at'] . ' UTC') > time() ? 'queued' : 'running') : 'used')),
            ], (array) $this->db->db_GetArray('SELECT * FROM `bonus_grants` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC')),
            'freeze' => Freezes::activeFor($this->db, $id),
            'freezes' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'startsAt' => self::iso($r['starts_at']),
                'endsAt' => self::iso($r['ends_at']),
                'reason' => (string) $r['reason'],
                'by' => (string) $r['staff_email'],
                'liftedAt' => self::iso($r['lifted_at']),
                'liftedBy' => $r['lifted_by'],
                'liftReason' => $r['lift_reason'],
            ], (array) $this->db->db_GetArray('SELECT * FROM `user_freezes` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC')),
            'activity' => $this->activity($id),
            'openBookings' => $this->openBookings($id),
        ];
        if ($withSecurity) {
            $detail['security'] = $this->security($id);
        }

        return $detail;
    }

    /** A user's open bookings (pending, or confirmed and not finished) — what a freeze must decide about. */
    public function openBookings(int $id): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT b.`id`, b.`guest_id`, b.`guide_id`, b.`status`, b.`payment_status`, b.`amount`, b.`meeting_at`, b.`trip_status`,
                    o.`id` AS `other_id`, o.`name` AS `other_name`
             FROM `bookings` b
             JOIN `users` o ON o.`id` = IF(b.`guest_id` = ' . $id . ', b.`guide_id`, b.`guest_id`)
             WHERE (b.`guest_id` = ' . $id . ' OR b.`guide_id` = ' . $id . ')
               AND (b.`status` = "pending" OR (b.`status` = "confirmed" AND b.`trip_status` <> "finished"))
             ORDER BY b.`meeting_at` IS NULL, b.`meeting_at`'
        );

        return array_map(fn(array $r): array => [
            'id' => (int) $r['id'],
            'side' => (int) $r['guest_id'] === $id ? 'guest' : 'guide',
            'other' => ['id' => (int) $r['other_id'], 'name' => (string) ($r['other_name'] ?? '')],
            'status' => (string) $r['status'],
            'tripStatus' => (string) $r['trip_status'],
            'paid' => $r['payment_status'] === 'paid',
            'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
            'meetingAt' => self::iso($r['meeting_at']),
        ], $rows);
    }

    /** Matches, trips, reviews and complaints — counts and the latest of each. */
    private function activity(int $id): array
    {
        $count = fn(string $sql): int => (int) $this->db->db_GetFieldFromQuery('SELECT COUNT(*) AS `n` ' . $sql, 'n');
        $people = fn(string $sql): array => (array) $this->db->db_GetArray($sql);

        return [
            'counts' => [
                'matches' => $count('FROM `selections` WHERE `active` = 1 AND (`guest_id` = ' . $id . ' OR `guide_id` = ' . $id . ')'),
                'tripsFinished' => $count('FROM `bookings` WHERE ' . str_replace('b.', '', Levels::FINISHED_TRIP) . ' AND (`guest_id` = ' . $id . ' OR `guide_id` = ' . $id . ')'),
                'reviewsWritten' => $count('FROM `reviews` WHERE `author_id` = ' . $id),
                'reviewsReceived' => $count('FROM `reviews` WHERE `subject_id` = ' . $id),
                'complaintsBy' => $count('FROM `complaints` WHERE `reporter_id` = ' . $id),
                'complaintsAbout' => $count('FROM `complaints` WHERE `subject_id` = ' . $id),
            ],
            'bookings' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'side' => (int) $r['guest_id'] === $id ? 'guest' : 'guide',
                'other' => ['id' => (int) $r['other_id'], 'name' => (string) ($r['other_name'] ?? '')],
                'status' => (string) $r['status'],
                'tripStatus' => (string) $r['trip_status'],
                'amount' => $r['amount'] !== null ? (float) $r['amount'] : null,
                'meetingAt' => self::iso($r['meeting_at']),
                'createdAt' => self::iso($r['created_at']),
            ], $people(
                'SELECT b.*, o.`id` AS `other_id`, o.`name` AS `other_name` FROM `bookings` b
                 JOIN `users` o ON o.`id` = IF(b.`guest_id` = ' . $id . ', b.`guide_id`, b.`guest_id`)
                 WHERE b.`guest_id` = ' . $id . ' OR b.`guide_id` = ' . $id . ' ORDER BY b.`id` DESC LIMIT 20'
            )),
            'reviews' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'direction' => (int) $r['author_id'] === $id ? 'written' : 'received',
                'other' => ['id' => (int) $r['other_id'], 'name' => (string) ($r['other_name'] ?? '')],
                'rating' => (int) $r['rating'],
                'comment' => $r['comment'],
                'createdAt' => self::iso($r['created_at']),
            ], $people(
                'SELECT r.*, o.`id` AS `other_id`, o.`name` AS `other_name` FROM `reviews` r
                 JOIN `users` o ON o.`id` = IF(r.`author_id` = ' . $id . ', r.`subject_id`, r.`author_id`)
                 WHERE r.`author_id` = ' . $id . ' OR r.`subject_id` = ' . $id . ' ORDER BY r.`id` DESC LIMIT 10'
            )),
            'complaints' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'direction' => (int) $r['reporter_id'] === $id ? 'by' : 'about',
                'other' => $r['other_id'] !== null ? ['id' => (int) $r['other_id'], 'name' => (string) ($r['other_name'] ?? '')] : null,
                'title' => (string) $r['title'],
                'status' => (string) $r['status'],
                'createdAt' => self::iso($r['created_at']),
            ], $people(
                'SELECT c.*, o.`id` AS `other_id`, o.`name` AS `other_name` FROM `complaints` c
                 LEFT JOIN `users` o ON o.`id` = IF(c.`reporter_id` = ' . $id . ', c.`subject_id`, c.`reporter_id`)
                 WHERE c.`reporter_id` = ' . $id . ' OR c.`subject_id` = ' . $id . ' ORDER BY c.`id` DESC LIMIT 20'
            )),
        ];
    }

    /** Devices, the last 50 sign-in events and linked accounts — admins only. */
    private function security(int $id): array
    {
        return [
            'devices' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'label' => (string) $r['label'],
                'place' => $r['last_place'],
                'country' => $r['last_country'],
                'ip' => $r['last_ip'],
                'firstSeenAt' => self::iso($r['first_seen_at']),
                'lastSeenAt' => self::iso($r['last_seen_at']),
            ], (array) $this->db->db_GetArray('SELECT * FROM `devices` WHERE `user_id` = ' . $id . ' ORDER BY `last_seen_at` DESC')),
            'events' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'event' => (string) $r['event'],
                'ip' => $r['ip'],
                'place' => $r['place'],
                'country' => $r['country'],
                'userAgent' => (string) $r['user_agent'],
                'riskScore' => $r['risk_score'] !== null ? (int) $r['risk_score'] : null,
                'riskReasons' => $r['risk_reasons'] !== null ? json_decode((string) $r['risk_reasons'], true) : null,
                'at' => self::iso($r['created_at']),
            ], (array) $this->db->db_GetArray('SELECT * FROM `login_events` WHERE `user_id` = ' . $id . ' ORDER BY `id` DESC LIMIT 50')),
            'links' => array_map(fn(array $r): array => [
                'id' => (int) $r['id'],
                'other' => ['id' => (int) $r['other_id'], 'name' => (string) ($r['other_name'] ?? ''), 'email' => (string) $r['other_email']],
                'signal' => (string) $r['signal'],
                'strength' => (int) $r['strength'],
                'hits' => (int) $r['hits'],
                'lastSeenAt' => self::iso($r['last_seen_at']),
                'verdict' => $r['verdict'],
            ], (array) $this->db->db_GetArray(
                'SELECT l.*, o.`id` AS `other_id`, o.`name` AS `other_name`, o.`email` AS `other_email` FROM `account_links` l
                 JOIN `users` o ON o.`id` = IF(l.`user_a` = ' . $id . ', l.`user_b`, l.`user_a`)
                 WHERE l.`user_a` = ' . $id . ' OR l.`user_b` = ' . $id . ' ORDER BY l.`strength` DESC, l.`last_seen_at` DESC'
            )),
        ];
    }

    /** Category label → picked option labels (or "09:00–13:00" spans), for one role. */
    private function attributesFor(int $id, string $role): array
    {
        $out = [];
        foreach ((array) $this->db->db_GetArray(
            'SELECT c.`label` AS `category`, o.`label` AS `value` FROM `user_attribute_values` v
             JOIN `attribute_options` o ON o.`id` = v.`option_id` JOIN `attribute_categories` c ON c.`id` = o.`category_id`
             WHERE v.`user_id` = ' . $id . ' AND v.`role` IN ("any", "' . $role . '") ORDER BY c.`id`, o.`label`'
        ) as $r) {
            $out[(string) $r['category']][] = (string) $r['value'];
        }
        foreach ((array) $this->db->db_GetArray(
            'SELECT c.`label` AS `category`, c.`key`, r.`range_start`, r.`range_end` FROM `user_attribute_ranges` r
             JOIN `attribute_categories` c ON c.`id` = r.`category_id`
             WHERE r.`user_id` = ' . $id . ' AND r.`role` IN ("any", "' . $role . '") ORDER BY c.`id`, r.`range_start`'
        ) as $r) {
            $format = static fn($v): string => $r['key'] === 'hour_of_day' ? sprintf('%02d:00', (int) $v) : (string) (int) $v;
            $out[(string) $r['category']][] = $format($r['range_start']) . '–' . $format($r['range_end']);
        }

        return $out;
    }

    private static function iso($datetime): ?string
    {
        return $datetime === null || $datetime === '' ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($datetime . ' UTC'));
    }

    /** The SQL for the search box: id, email, phone or name — see `search()`. */
    private function queryCondition(string $q): string
    {
        if (preg_match('/^#\s*(\d+)$/', $q, $m) || preg_match('/^\d{1,4}$/', $q, $m)) {
            return 'u.`id` = ' . (int) ($m[1] ?? $m[0]);
        }
        $like = static fn(string $text, db $db): string => '"%' . $db->db_Escape(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text)) . '%"';
        if (strpos($q, '@') !== false) {
            return 'u.`email` LIKE ' . $like($q, $this->db);
        }
        $digits = preg_replace('/\D+/', '', $q);
        if (strlen($digits) >= 5 && preg_match('/^[\d\s+().\-]+$/', $q)) {
            return 'REGEXP_REPLACE(COALESCE(u.`phone`, ""), "[^0-9]", "") LIKE "%' . $digits . '%"';
        }

        $pattern = $like($q, $this->db);

        return '(u.`name` LIKE ' . $pattern . ' OR u.`email` LIKE ' . $pattern
            . ' OR gb.`label` LIKE ' . $pattern . ' OR ta.`label` LIKE ' . $pattern . ')';
    }

    /** SQL: the town in a location row's label ("Lisbon, Portugal" → "Lisbon"). */
    private static function townSql(string $alias): string
    {
        return 'TRIM(SUBSTRING_INDEX(' . $alias . '.`label`, ",", 1))';
    }

    /**
     * The cities and countries users are in — guides' bases and travellers'
     * search areas — for the list's filters: `cities: [{ value: "PT|Lisbon",
     * city, countryCode, users }]`, `countries: [{ code, users }]`, most
     * users first.
     */
    public function locations(): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT `town`, `country_code`, COUNT(DISTINCT `user_id`) AS `users` FROM (
                SELECT `user_id`, ' . self::townSql('gb') . ' AS `town`, gb.`country_code` FROM `guide_bases` gb
                UNION ALL
                SELECT `user_id`, ' . self::townSql('ta') . ' AS `town`, ta.`country_code` FROM `traveller_areas` ta
             ) l WHERE `country_code` IS NOT NULL AND `town` <> ""
             GROUP BY `town`, `country_code` ORDER BY `users` DESC, `town`'
        );
        $countries = (array) $this->db->db_GetArray(
            'SELECT `country_code`, COUNT(DISTINCT `user_id`) AS `users` FROM (
                SELECT `user_id`, `country_code` FROM `guide_bases`
                UNION ALL SELECT `user_id`, `country_code` FROM `traveller_areas`
             ) l WHERE `country_code` IS NOT NULL GROUP BY `country_code` ORDER BY `users` DESC'
        );

        return [
            'cities' => array_map(static fn(array $r): array => [
                'value' => $r['country_code'] . '|' . $r['town'],
                'city' => (string) $r['town'],
                'countryCode' => (string) $r['country_code'],
                'users' => (int) $r['users'],
            ], $rows),
            'countries' => array_map(static fn(array $r): array => [
                'code' => (string) $r['country_code'],
                'users' => (int) $r['users'],
            ], $countries),
        ];
    }

    private function listRow(array $row): array
    {
        $roles = $row['roles'] !== null && $row['roles'] !== '' ? explode(',', (string) $row['roles']) : [];
        $frozenTs = $row['frozen_until_ts'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) $row['email'],
            'phone' => $row['phone'],
            'image' => $row['image'] ?: null,
            'roles' => $roles,
            'levels' => [
                'guest' => in_array('guest', $roles, true) ? (int) $row['guest_level'] : null,
                'guide' => in_array('guide', $roles, true) ? (int) $row['guide_level'] : null,
            ],
            'location' => $row['base_label'] ?? $row['area_label'],
            'verified' => $row['verified_at'] !== null,
            'frozen' => $frozenTs !== null,
            // 3000-01-01 stands for "permanently" (UNIX_TIMESTAMP stops in 3001).
            'frozenUntil' => $frozenTs !== null && (int) $frozenTs < 32503680000 ? gmdate('Y-m-d\TH:i:s\Z', (int) $frozenTs) : null,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['created_at'] . ' UTC')),
        ];
    }
}
