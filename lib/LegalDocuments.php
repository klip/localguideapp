<?php

/**
 * Legal documents (`legal_documents`, 024) — today only `terms`, the terms
 * and conditions with the GDPR privacy notice — and who accepted them
 * (`user_legal_acceptances`). The api service serves the text
 * (`legalDocument` action); the auth service checks and records acceptance
 * when an account is created.
 *
 * The version in force is the newest one already published; a language
 * without a row for it falls back to English. `{{placeholder}}`s are filled
 * from `legal_placeholders` (the legal entities, set once for every
 * language); one with no value yet reads `[its label]`.
 */
class LegalDocuments
{
    public const TERMS = 'terms';
    public const GUIDE_AGREEMENT = 'guide_agreement';
    public const TRAVELLER_AGREEMENT = 'traveller_agreement';
    public const SLUGS = [self::TERMS, self::GUIDE_AGREEMENT, self::TRAVELLER_AGREEMENT];

    /**
     * The agreement each role accepts on top of the terms — at registration
     * for the starting role, and when adding the other one (`addRole`).
     */
    public const ROLE_AGREEMENTS = ['guide' => self::GUIDE_AGREEMENT, 'guest' => self::TRAVELLER_AGREEMENT];

    /**
     * Throws unless the client showed `$role`'s own agreement (`$slug` — the
     * two agreements can share a version date, so the version alone can't
     * tell them apart) in the version in force (`$version`); returns it.
     */
    public function assertRoleAgreement(string $role, string $slug, string $version): string
    {
        $expected = self::ROLE_AGREEMENTS[$role] ?? null;
        $current = $expected && $slug === $expected ? $this->currentVersion($expected) : null;
        if ($current === null || $version !== $current) {
            throw new RuntimeException($role === 'guide'
                ? 'Please accept the current guide agreement.'
                : 'Please accept the current traveller agreement.');
        }

        return $current;
    }

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /** The version of `$slug` in force now, or null if none is published. */
    public function currentVersion(string $slug): ?string
    {
        $version = $this->db->db_GetFieldFromQuery(
            "SELECT `version` FROM `legal_documents`
             WHERE `slug` = '" . $this->db->db_Escape($slug) . "' AND `published_at` <= UTC_TIMESTAMP()
             ORDER BY `published_at` DESC, `version` DESC LIMIT 1",
            'version'
        );

        return $version ? (string) $version : null;
    }

    /**
     * The document in force, in `$language` (else English), placeholders
     * filled: `{ slug, version, language, title, body }`.
     */
    public function current(string $slug, string $language): array
    {
        if (!in_array($slug, self::SLUGS, true)) {
            throw new RuntimeException('Unknown document.');
        }
        $version = $this->currentVersion($slug);
        if ($version === null) {
            throw new RuntimeException('This document is not available yet.');
        }

        $rows = $this->db->db_GetArray(
            "SELECT `language`, `title`, `body` FROM `legal_documents`
             WHERE `slug` = '" . $this->db->db_Escape($slug) . "'
               AND `version` = '" . $this->db->db_Escape($version) . "'
               AND `language` IN ('" . $this->db->db_Escape($language) . "', 'en')"
        );
        $byLanguage = array_column($rows ?: [], null, 'language');
        $row = $byLanguage[$language] ?? $byLanguage['en'] ?? null;
        if ($row === null) {
            throw new RuntimeException('This document is not available yet.');
        }

        $values = ['effective_date' => $version] + $this->placeholderValues();
        $fill = static function (string $text) use ($values): string {
            return preg_replace_callback('/\{\{([a-z_]+)\}\}/', static function ($m) use ($values) {
                return $values[$m[1]] ?? '[' . $m[1] . ']';
            }, $text);
        };

        return [
            'slug' => $slug,
            'version' => $version,
            'language' => $row['language'],
            'title' => $fill($row['title']),
            'body' => $fill($row['body']),
        ];
    }

    /** Records that `$userId` accepted `$version` of `$slug`; throws if it couldn't be stored. */
    public function recordAcceptance(int $userId, string $slug, string $version, string $language, string $userAgent): void
    {
        $id = $this->db->db_Insert('user_legal_acceptances', [
            'user_id' => $userId,
            'slug' => $slug,
            'version' => $version,
            'language' => $language,
            'user_agent' => mb_substr($userAgent, 0, 255),
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$id) {
            throw new Exception('Could not record the acceptance of ' . $slug . ' ' . $version . '.');
        }
    }

    /** @return array<string, string> placeholder key → value, or `[label]` while unset */
    private function placeholderValues(): array
    {
        $values = [];
        foreach ($this->db->db_GetArray('SELECT `key`, `label`, `value` FROM `legal_placeholders`') ?: [] as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            $values[$row['key']] = $value !== '' ? $value : '[' . $row['label'] . ']';
        }
        return $values;
    }
}
