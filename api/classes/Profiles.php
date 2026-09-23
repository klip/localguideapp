<?php

/**
 * Data access for the filterable/self-editable profile attributes:
 * `user_profiles` (scalar fields — date_of_birth, gender, price, bio, …),
 * the generic `attribute_categories`/`attribute_options`/`user_attribute_values`
 * taxonomy (a "list of lists" — Languages, Interests, Food allergies,
 * Hour of the day, ... — each category holding its own options or range
 * bounds, cross-referenced by user id; see 007_dynamic_attributes.sql and
 * 010_profile_attributes.sql).
 *
 * Everything that isn't a human constant (name, contact details, date of
 * birth, gender — those stay `users`/`user_profiles` columns) is a category
 * here, with no per-category special cases left in code: `user_specialties`
 * was folded into `interests` by 010, which a guide simply sees under that
 * category's `provider_label` ("Specialities") instead of its `label`.
 *
 * A category is one of two kinds. `options` is a pick-from-the-list set,
 * stored in `user_attribute_values`; `range` is one or more numeric spans
 * within the category's `range_min`/`range_max`, stored in
 * `user_attribute_ranges` — no rows there means unconstrained, which is how
 * "a guide who hasn't set their hours is available whenever" falls out.
 * `setAttributeValues()` creates a category or option on the fly if it
 * doesn't exist yet — see its docblock — so a user can grow the taxonomy
 * from `ProfilePage.vue` itself.
 *
 * `ProfilePage.vue` is the only client of `setAttributeValues()`/
 * `setRanges()`/`getAttributeValuesFor()`/`getRangesFor()`/`getCategories()`;
 * `Users::searchByRole()` is the only client of `getAttributesFor()` (the
 * batched discovery-deck path).
 */
class Profiles
{
    private const SCALAR_COLUMNS = [
        'date_of_birth', 'gender', 'headline', 'bio', 'price_amount', 'price_label',
        'price_note', 'includes', 'rating', 'tours', 'party', 'duration_hours',
    ];

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * Upserts whichever of self::SCALAR_COLUMNS are present (and non-null)
     * in $fields; absent/null keys are left untouched. `db`'s insert
     * helpers always quote values as strings, so there's no clean way to
     * write a literal SQL NULL through them (same tradeoff `Users::register()`
     * makes for `users.image`) — omit a field rather than pass null to clear it.
     */
    public function setProfile(int $userId, array $fields): void
    {
        $data = ['user_id' => $userId];
        $updateKeys = [];

        foreach (self::SCALAR_COLUMNS as $column) {
            if (array_key_exists($column, $fields) && $fields[$column] !== null) {
                $data[$column] = (string) $fields[$column];
                $updateKeys[] = $column;
            }
        }

        if (!$updateKeys) {
            return;
        }

        $this->db->db_InsertUpdate('user_profiles', $data, $updateKeys);
    }

    /**
     * Every category with its options, for the profile editor's pickers and
     * the discovery filter bar. `kind` is `options` (pick from the list) or
     * `range` (numeric spans between `rangeMin`/`rangeMax`); `providerLabel`
     * is what the same category is called on the provider side of a match,
     * so one category reads as "Interests" to a guest and "Specialities" to
     * a guide without being two different things.
     */
    public function getCategories(): array
    {
        $categories = $this->db->db_GetArray(
            'SELECT `id`, `key`, `label`, `kind`, `provider_label`, `multi`, `range_min`, `range_max`
             FROM `attribute_categories` ORDER BY `id`'
        );

        $optionsByCategory = [];
        foreach ($this->db->db_GetArray('SELECT `category_id`, `value`, `label` FROM `attribute_options` ORDER BY `id`') as $option) {
            $optionsByCategory[(int) $option['category_id']][] = ['value' => $option['value'], 'label' => $option['label']];
        }

        return array_map(fn($category) => [
            'key' => $category['key'],
            'label' => $category['label'],
            'kind' => $category['kind'],
            'providerLabel' => $category['provider_label'],
            'multi' => (bool) $category['multi'],
            'rangeMin' => $category['range_min'] === null ? null : (int) $category['range_min'],
            'rangeMax' => $category['range_max'] === null ? null : (int) $category['range_max'],
            'options' => $optionsByCategory[(int) $category['id']] ?? [],
        ], $categories);
    }

    /**
     * Replaces a user's full option set within one category — creating the
     * category and/or any option that doesn't exist yet, rather than
     * skipping it, so `ProfilePage.vue`'s "add a new category/option" flow
     * can extend the taxonomy without a separate admin step. A newly
     * created category takes `$categoryLabel` (falling back to the key
     * itself) and is always `multi = 1`; a newly created option takes
     * `$value` as both its `value` and `label` (there's no client-supplied
     * label for options — see `ProfilePage.vue`'s combobox, which only
     * lets a user type one string per option).
     */
    public function setAttributeValues(int $userId, string $categoryKey, array $values, ?string $categoryLabel = null): void
    {
        $categoryId = $this->db->db_GetFieldFromQuery(
            'SELECT `id` FROM `attribute_categories` WHERE `key` = "' . $this->db->db_Escape($categoryKey) . '"',
            'id'
        );
        if (!$categoryId) {
            $categoryId = $this->db->db_Insert('attribute_categories', [
                'key' => $categoryKey,
                'label' => $categoryLabel ?? $categoryKey,
                'multi' => 1,
            ]);
            if (!$categoryId) {
                return;
            }
        }

        $this->db->db_Execute(
            'DELETE uav FROM `user_attribute_values` uav
             JOIN `attribute_options` ao ON ao.`id` = uav.`option_id`
             WHERE uav.`user_id` = ' . $userId . ' AND ao.`category_id` = ' . intval($categoryId)
        );

        foreach (array_unique($values) as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $optionId = $this->db->db_GetFieldFromQuery(
                'SELECT `id` FROM `attribute_options` WHERE `category_id` = ' . intval($categoryId) . '
                 AND `value` = "' . $this->db->db_Escape($value) . '"',
                'id'
            );
            if (!$optionId) {
                $optionId = $this->db->db_Insert('attribute_options', [
                    'category_id' => $categoryId,
                    'value' => $value,
                    'label' => $value,
                ]);
            }
            if ($optionId) {
                $this->db->db_Insert('user_attribute_values', ['user_id' => $userId, 'option_id' => $optionId]);
            }
        }
    }

    /** One user's selections across every category, keyed by category `key` — what `ProfilePage.vue` prefills from. */
    public function getAttributeValuesFor(int $userId): array
    {
        $result = [];
        foreach ($this->db->db_GetArray(
            'SELECT ac.`key` category_key, ao.`value` FROM `user_attribute_values` uav
             JOIN `attribute_options` ao ON ao.`id` = uav.`option_id`
             JOIN `attribute_categories` ac ON ac.`id` = ao.`category_id`
             WHERE uav.`user_id` = ' . $userId
        ) as $row) {
            $result[$row['category_key']][] = $row['value'];
        }

        return $result;
    }

    /**
     * Selections and ranges for a batch of users, grouped by user id then
     * category `key` — what `Users::searchByRole()` attaches to a discovery
     * result list without a query per row.
     *
     * Fully generic since 010: there's no per-category special casing left
     * (the old LEGACY_FIELD_BY_CATEGORY map, which renamed `interests` to
     * `activities` on its way to the client, is gone), so a category a user
     * invents shows up here the moment somebody picks it.
     *
     * @param int[] $userIds
     * @return array{attributes: array<int, array<string, string[]>>, ranges: array<int, array<string, array{0:int,1:int}[]>>}
     */
    public function getAttributesFor(array $userIds): array
    {
        $result = ['attributes' => [], 'ranges' => []];
        if (!$userIds) {
            return $result;
        }

        $idList = implode(',', array_map('intval', $userIds));

        foreach ($this->db->db_GetArray(
            'SELECT uav.`user_id`, ac.`key` category_key, ao.`value` FROM `user_attribute_values` uav
             JOIN `attribute_options` ao ON ao.`id` = uav.`option_id`
             JOIN `attribute_categories` ac ON ac.`id` = ao.`category_id`
             WHERE uav.`user_id` IN (' . $idList . ')'
        ) as $row) {
            $result['attributes'][(int) $row['user_id']][$row['category_key']][] = $row['value'];
        }

        foreach ($this->db->db_GetArray(
            'SELECT uar.`user_id`, ac.`key` category_key, uar.`range_start`, uar.`range_end`
             FROM `user_attribute_ranges` uar
             JOIN `attribute_categories` ac ON ac.`id` = uar.`category_id`
             WHERE uar.`user_id` IN (' . $idList . ')
             ORDER BY uar.`range_start`'
        ) as $row) {
            $result['ranges'][(int) $row['user_id']][$row['category_key']][] = [
                (int) $row['range_start'],
                (int) $row['range_end'],
            ];
        }

        return $result;
    }

    /**
     * Replaces a user's full set of spans within one `range` category (the
     * hours a guide works, today). An empty `$ranges` clears them, which is
     * meaningful rather than merely absent: no rows means unconstrained, and
     * `Users::searchByRole()` treats that as matching every requested span.
     *
     * Silently ignores a category that doesn't exist or isn't `range` kind —
     * unlike `setAttributeValues()` there's no create-on-the-fly, since a
     * range category needs bounds only a migration can sensibly choose.
     *
     * @param array<array{0:int,1:int}> $ranges
     */
    public function setRanges(int $userId, string $categoryKey, array $ranges): void
    {
        $category = $this->db->db_GetRow(
            'SELECT `id`, `range_min`, `range_max` FROM `attribute_categories`
             WHERE `key` = "' . $this->db->db_Escape($categoryKey) . '" AND `kind` = "range"'
        );
        if (!$category) {
            return;
        }

        $categoryId = (int) $category['id'];
        $min = $category['range_min'] === null ? PHP_INT_MIN : (int) $category['range_min'];
        $max = $category['range_max'] === null ? PHP_INT_MAX : (int) $category['range_max'];

        $this->db->db_Execute(
            'DELETE FROM `user_attribute_ranges` WHERE `user_id` = ' . $userId . ' AND `category_id` = ' . $categoryId
        );

        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }

            // Clamp to the category's own bounds, and flip a back-to-front
            // pair rather than rejecting the whole save over it.
            $start = max($min, min($max, (int) $range[0]));
            $end = max($min, min($max, (int) $range[1]));
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            // The table is unique on (user, category, start, end), so an
            // upsert makes a duplicated span a no-op instead of an error.
            $this->db->db_InsertUpdate('user_attribute_ranges', [
                'user_id' => $userId,
                'category_id' => $categoryId,
                'range_start' => $start,
                'range_end' => $end,
            ], ['range_end']);
        }
    }

    /** One user's spans keyed by category `key` — the `range` half of what `ProfilePage.vue` prefills from. */
    public function getRangesFor(int $userId): array
    {
        $result = [];
        foreach ($this->db->db_GetArray(
            'SELECT ac.`key` category_key, uar.`range_start`, uar.`range_end`
             FROM `user_attribute_ranges` uar
             JOIN `attribute_categories` ac ON ac.`id` = uar.`category_id`
             WHERE uar.`user_id` = ' . $userId . '
             ORDER BY uar.`range_start`'
        ) as $row) {
            $result[$row['category_key']][] = [(int) $row['range_start'], (int) $row['range_end']];
        }

        return $result;
    }
}
