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
     * Every string stored for `$language`, as a flat `{ key: value }` map.
     *
     * @return array<string, string>
     */
    public function forLanguage(string $language): array
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new RuntimeException('Unsupported language.');
        }

        $rows = $this->db->db_GetArray(
            "SELECT `key`, `value` FROM `translations` WHERE `language` = '" . $this->db->db_Escape($language) . "'"
        );

        $strings = [];
        foreach ($rows ?: [] as $row) {
            $strings[$row['key']] = $row['value'];
        }
        return $strings;
    }
}
