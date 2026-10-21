<?php

/**
 * The CMS's content editor (docs/auth-cms-plan.md, phase 7): the app's UI
 * text in the `translations` table, block by block (`page.widget`, e.g.
 * `discover.deck`), in any of the 15 languages.
 *
 * - The map of pages and blocks is the CMS's own page map (`AppMap`,
 *   `app_routes`/`app_route_blocks`) — the same one the app builds its router
 *   from. Text on no page is listed as "Other".
 * - A block's keys are its English rows (English is the reference: every key
 *   exists in English first); each language shows its value, the English one
 *   beside it, and whether it's missing (the app then shows English).
 * - **Saving checks** every value: the same `{placeholders}` as the English
 *   (a translation that drops `{count}` would print nonsense), no HTML (it's
 *   shown as text), at most 2000 characters. An empty value removes the
 *   translation — the app falls back to English; English itself can't be
 *   emptied. Plural forms a language needs (`_one`/`_few`/… — CLDR, as the
 *   app's `tn()` picks them) are reported when missing, not forced.
 * - Every change goes into `translations_history` (old and new value, who)
 *   and the audit log; `revert()` puts an earlier value back.
 * - New keys: the last segment only (`[a-z0-9_]+`), English value first.
 *   A key no code uses is harmless but shows nowhere — the editor says how
 *   developers reference it.
 */
final class ContentEditor
{
    private const PLURAL_SUFFIXES = ['zero', 'one', 'two', 'few', 'many', 'other'];
    /** The plural forms each language's `Intl.PluralRules` uses for whole numbers. */
    private const PLURAL_FORMS = [
        'ar' => ['zero', 'one', 'two', 'few', 'many', 'other'],
        'ru' => ['one', 'few', 'many', 'other'],
        'uk' => ['one', 'few', 'many', 'other'],
        'zh' => ['other'], 'ja' => ['other'], 'ko' => ['other'], 'th' => ['other'],
    ];
    private const MAX_LENGTH = 2000;

    /** @var db */
    private $db;
    /** @var AppMap */
    private $appMap;

    public function __construct(db $db, AppMap $appMap)
    {
        $this->db = $db;
        $this->appMap = $appMap;
    }

    /**
     * The page map as the editor draws it: `{ shell: { header, footer,
     * always }, pages: [{ route, path, title, enabled, main, shared }],
     * unplaced, languages }` — pages in the map's order, redirects left out.
     */
    public function layout(): array
    {
        $shell = ['header' => [], 'footer' => [], 'always' => []];
        $pages = [];
        $placed = [];
        foreach ($this->appMap->full() as $route) {
            $areas = [];
            foreach ($route['blocks'] as $block) {
                $areas[$block['area']][] = $block['prefix'];
                $placed[$block['prefix']] = true;
            }
            if ($route['kind'] === 'shell') {
                $shell = array_merge($shell, array_intersect_key($areas, $shell));
            } elseif ($route['kind'] === 'page') {
                $pages[] = [
                    'route' => $route['name'],
                    'path' => (string) $route['path'],
                    'title' => $route['title'],
                    'enabled' => $route['enabled'],
                    'main' => $areas['main'] ?? [],
                    'shared' => $areas['shared'] ?? [],
                ];
            }
        }
        $unplaced = array_values(array_filter($this->appMap->textPrefixes(), static fn(string $p): bool => !isset($placed[$p])));

        return ['shell' => $shell, 'pages' => $pages, 'unplaced' => $unplaced, 'languages' => Translations::LANGUAGES];
    }

    /**
     * One block in one language: every key (English order), its value here,
     * the English, whether it's missing, and the plural groups lacking a form.
     */
    public function block(string $prefix, string $language): array
    {
        $this->assertPrefix($prefix);
        $this->assertLanguage($language);
        $english = $this->values($prefix, 'en');
        $here = $language === 'en' ? $english : $this->values($prefix, $language);

        $entries = [];
        foreach ($english as $key => $value) {
            $entries[] = [
                'key' => $key,
                'name' => substr($key, strlen($prefix) + 1),
                'value' => $here[$key] ?? null,
                'english' => $value,
                'missing' => !array_key_exists($key, $here),
            ];
        }

        return [
            'prefix' => $prefix,
            'language' => $language,
            'entries' => $entries,
            'missingPluralForms' => $this->missingPluralForms(array_keys($english), $here, $language),
        ];
    }

    /**
     * Saves `$values` (`key => text`, `''` removes a translation) for one
     * block and language. All or nothing: any invalid value refuses the save
     * (`{ field, error }` in the exception message's JSON). Returns the keys
     * changed, with their old and new values, for the audit log.
     *
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function save(string $prefix, string $language, array $values, array $staff): array
    {
        $this->assertPrefix($prefix);
        $this->assertLanguage($language);
        $english = $this->values($prefix, 'en');
        $current = $language === 'en' ? $english : $this->values($prefix, $language);

        $changes = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (!array_key_exists($key, $english)) {
                throw new RuntimeException("Unknown key {$key} in {$prefix}.");
            }
            $value = is_string($value) ? str_replace("\r\n", "\n", trim($value)) : '';
            $old = $current[$key] ?? null;
            if ($value === '' && $language === 'en') {
                throw new RuntimeException(self::fieldError($key, 'English can’t be empty — every other language falls back to it.'));
            }
            if ($value !== '') {
                $this->assertValid($key, $value, $english[$key], $language);
            }
            $new = $value === '' ? null : $value;
            if ($new !== $old) {
                $changes[$key] = [$old, $new];
            }
        }

        $this->db->db_Execute('START TRANSACTION');
        try {
            foreach ($changes as $key => [$old, $new]) {
                $this->write($language, $key, $old, $new, $staff);
            }
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }

        return $changes;
    }

    /** A new key in a block: its last segment and the English text. Returns the full key. */
    public function addKey(string $prefix, string $name, string $english, array $staff): string
    {
        $this->assertPrefix($prefix);
        if (!preg_match('/^[a-z0-9_]{1,60}$/', $name)) {
            throw new RuntimeException(self::fieldError('name', 'Use lowercase letters, digits and underscores only.'));
        }
        $key = $prefix . '.' . $name;
        if (array_key_exists($key, $this->values($prefix, 'en'))) {
            throw new RuntimeException(self::fieldError('name', 'That key already exists in this block.'));
        }
        $english = str_replace("\r\n", "\n", trim($english));
        if ($english === '') {
            throw new RuntimeException(self::fieldError('value', 'Give the English text first.'));
        }
        $this->assertValid($key, $english, null, 'en');
        $this->write('en', $key, null, $english, $staff);

        return $key;
    }

    /** The last 50 changes in a block (or to one key), newest first. */
    public function history(string $prefix, ?string $key = null): array
    {
        $this->assertPrefix($prefix);
        $where = $key !== null && $key !== ''
            ? '`key` = "' . $this->db->db_Escape($key) . '"'
            : '`key` LIKE "' . $this->db->db_Escape(str_replace(['%', '_'], ['\\%', '\\_'], $prefix)) . '.%"';
        $rows = (array) $this->db->db_GetArray(
            'SELECT * FROM `translations_history` WHERE ' . $where . ' ORDER BY `id` DESC LIMIT 50'
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'language' => (string) $row['language'],
            'key' => (string) $row['key'],
            'oldValue' => $row['old_value'],
            'newValue' => $row['new_value'],
            'staffEmail' => (string) $row['staff_email'],
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
        ], $rows);
    }

    /**
     * Puts back the value a history entry replaced. Returns `[language, key,
     * old, new]` for the audit log.
     */
    public function revert(int $historyId, array $staff): array
    {
        $row = $this->db->db_GetRow('SELECT * FROM `translations_history` WHERE `id` = ' . $historyId);
        if (!$row) {
            throw new RuntimeException('No such change.');
        }
        $language = (string) $row['language'];
        $key = (string) $row['key'];
        $target = $row['old_value'];
        if ($target === null && $language === 'en') {
            throw new RuntimeException('That change added the key; an English text can’t be removed here.');
        }
        $current = $this->db->db_GetRow(
            'SELECT `value` FROM `translations` WHERE `language` = "' . $this->db->db_Escape($language) . '"
               AND `key` = "' . $this->db->db_Escape($key) . '"'
        )['value'] ?? null;
        if ($target !== null) {
            $english = $language === 'en' ? null : ($this->db->db_GetRow(
                'SELECT `value` FROM `translations` WHERE `language` = "en" AND `key` = "' . $this->db->db_Escape($key) . '"'
            )['value'] ?? null);
            $this->assertValid($key, (string) $target, $english, $language);
        }
        if ($current !== $target) {
            $this->write($language, $key, $current, $target, $staff);
        }

        return [$language, $key, $current, $target];
    }

    // ------------------------------------------------------------- internals

    private function write(string $language, string $key, ?string $old, ?string $new, array $staff): void
    {
        $lang = $this->db->db_Escape($language);
        $k = $this->db->db_Escape($key);
        if ($new === null) {
            $this->db->db_Execute('DELETE FROM `translations` WHERE `language` = "' . $lang . '" AND `key` = "' . $k . '"');
        } else {
            $this->db->db_Execute(
                'INSERT INTO `translations` (`language`, `key`, `value`) VALUES ("' . $lang . '", "' . $k . '", "' . $this->db->db_Escape($new) . '")
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
        }
        $this->db->db_Execute(
            'INSERT INTO `translations_history` (`language`, `key`, `old_value`, `new_value`, `staff_id`, `staff_email`, `created_at`)
             VALUES ("' . $lang . '", "' . $k . '", ' . $this->quote($old) . ', ' . $this->quote($new) . ', '
            . (int) $staff['id'] . ', "' . $this->db->db_Escape((string) $staff['email']) . '", UTC_TIMESTAMP())'
        );
    }

    /** @return array<string, string> key → value, the block's rows in one language, key order */
    private function values(string $prefix, string $language): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT `key`, `value` FROM `translations`
             WHERE `language` = "' . $this->db->db_Escape($language) . '"
               AND `key` LIKE "' . $this->db->db_Escape(str_replace(['%', '_'], ['\\%', '\\_'], $prefix)) . '.%"
             ORDER BY `key`'
        );

        return array_column($rows, 'value', 'key');
    }

    private function assertValid(string $key, string $value, ?string $english, string $language): void
    {
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new RuntimeException(self::fieldError($key, 'Too long — at most ' . self::MAX_LENGTH . ' characters.'));
        }
        if (preg_match('/<\s*[a-z\/!]/i', $value)) {
            throw new RuntimeException(self::fieldError($key, 'No HTML — the app shows this as plain text.'));
        }
        if ($english !== null && $language !== 'en') {
            $want = self::placeholders($english);
            $have = self::placeholders($value);
            if ($want !== $have) {
                $missing = array_diff($want, $have);
                $extra = array_diff($have, $want);
                throw new RuntimeException(self::fieldError($key, trim(
                    ($missing ? 'Keep ' . implode(', ', array_map(static fn($p) => '{' . $p . '}', $missing)) . '. ' : '')
                    . ($extra ? 'Remove ' . implode(', ', array_map(static fn($p) => '{' . $p . '}', $extra)) . ' — the English doesn’t have it.' : '')
                )));
            }
        }
    }

    /** @return string[] the `{names}` in a text, sorted, unique */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * Plural groups (`base_one`, `base_other`, …) whose forms this language
     * needs but doesn't have — `[{ base, missing: [forms] }]`.
     */
    private function missingPluralForms(array $englishKeys, array $here, string $language): array
    {
        $needed = self::PLURAL_FORMS[$language] ?? ['one', 'other'];
        $groups = [];
        foreach ($englishKeys as $key) {
            if (preg_match('/^(.*)_(' . implode('|', self::PLURAL_SUFFIXES) . ')$/', $key, $m)) {
                $groups[$m[1]] = true;
            }
        }
        $result = [];
        foreach (array_keys($groups) as $base) {
            $missing = array_values(array_filter($needed, static fn(string $form): bool => !array_key_exists($base . '_' . $form, $here)));
            if ($missing) {
                $result[] = ['base' => $base, 'missing' => $missing];
            }
        }

        return $result;
    }

    private function assertPrefix(string $prefix): void
    {
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/', $prefix)) {
            throw new RuntimeException('A block is "page.widget".');
        }
    }

    private function assertLanguage(string $language): void
    {
        if (!in_array($language, Translations::LANGUAGES, true)) {
            throw new RuntimeException('Unsupported language.');
        }
    }

    /** A message the editor can pin to one field: `{"field":…,"error":…}`. */
    private static function fieldError(string $field, string $error): string
    {
        return (string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE);
    }

    private function quote(?string $value): string
    {
        return $value === null ? 'NULL' : '"' . $this->db->db_Escape($value) . '"';
    }
}
