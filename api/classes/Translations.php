<?php

/**
 * Data access for `translations` (see `api/sql/018_translations.sql`): every
 * piece of UI text, one row per (language, key). Keys are namespaced
 * `{page}.{widget}.{key}` (e.g. `discover.empty.nobody_new`); values may hold
 * `{placeholder}`s the client fills in, and plural forms are separate keys
 * suffixed `_one`/`_few`/`_many`/`_other` (CLDR categories).
 *
 * English lives here like every other language (so a future CMS can manage
 * it) and is the only complete one. For a key a language doesn't have, the
 * client shows this table's English, else its bundled copy
 * (`client/src/i18n/en.json`) — a partly translated language still works.
 */
class Translations
{
    /**
     * Top ten traveller languages merged with the top ten destination
     * languages (UN Tourism data), plus Portuguese and Ukrainian — anything else is refused.
     * Must match the client's `LANGUAGES` in `client/src/i18n/index.ts`.
     */
    public const LANGUAGES = [
        'en', 'es', 'fr', 'de', 'zh', 'it', 'ja', 'ru', 'ko', 'ar', 'tr', 'pt', 'el', 'th', 'uk',
    ];

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * The strings stored for `$language`, as a flat `{ key: value }` map —
     * all of them, or only keys under `$scopes`, minus any under `$except`.
     * A scope is a key prefix: a page (`discover`) or a page's widget
     * (`common.app_header`); the caller validates their shape.
     *
     * @param string[]|null $scopes
     * @param string[]|null $except
     * @return array<string, string>
     */
    public function forLanguage(string $language, ?array $scopes = null, ?array $except = null): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new RuntimeException('Unsupported language.');
        }

        $where = "`language` = '" . $this->db->db_Escape($language) . "'";
        if ($scopes !== null) {
            if (!$scopes) {
                return [];
            }
            $where .= ' AND ' . $this->prefixMatch($scopes);
        }
        if ($except) {
            $where .= ' AND NOT ' . $this->prefixMatch($except);
        }

        $rows = $this->db->db_GetArray("SELECT `key`, `value` FROM `translations` WHERE {$where}");

        $strings = [];
        foreach ($rows ?: [] as $row) {
            $strings[$row['key']] = $row['value'];
        }
        return $strings;
    }

    /** `(key LIKE 'a.%' OR ...)`, with `_` escaped so it isn't LIKE's one-character wildcard. */
    private function prefixMatch(array $scopes): string
    {
        $likes = [];
        foreach ($scopes as $scope) {
            $likes[] = "`key` LIKE '" . str_replace('_', '\\_', $this->db->db_Escape($scope)) . ".%'";
        }
        return '(' . implode(' OR ', $likes) . ')';
    }
}
