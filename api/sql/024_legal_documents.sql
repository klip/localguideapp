-- 024: legal documents and their acceptance (applied 2026-10-15).
--
-- `legal_documents` holds every version of every document (today only
-- `terms`: the terms and conditions with the GDPR privacy notice), one row
-- per language. The version in force is the newest one with
-- `published_at <= NOW()`. Bodies are a small Markdown subset (`##`/`###`
-- headings, `- ` lists, paragraphs) and may contain `{{placeholder}}`s.
--
-- `legal_placeholders` fills those placeholders once for every language —
-- the legal entities (operator, data controller, processors, DPO, ...).
-- A placeholder whose `value` is NULL is shown as `[label]`, so the text
-- reads sensibly before a lawyer has supplied the details. `effective_date`
-- isn't here: it's the document's version.
--
-- `user_legal_acceptances` records who accepted which version, when, in
-- which language and from which browser (proof of consent). Registration
-- writes it (auth service).
--
-- The document text itself is seeded by 025, generated from
-- docs/legal/terms/<version>/<lang>.md (api/bin/build-legal-sql.php).
--
-- Re-runnable.

CREATE TABLE IF NOT EXISTS `legal_documents` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(40)  NOT NULL,
  `version`      VARCHAR(20)  NOT NULL,
  `language`     VARCHAR(8)   NOT NULL,
  `title`        VARCHAR(200) NOT NULL,
  `body`         MEDIUMTEXT   NOT NULL,
  `published_at` DATETIME     NOT NULL,
  `created_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legal_documents_unique` (`slug`, `version`, `language`),
  KEY `legal_documents_current` (`slug`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legal_placeholders` (
  `key`   VARCHAR(60)  NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `value` TEXT         NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_legal_acceptances` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `slug`        VARCHAR(40)  NOT NULL,
  `version`     VARCHAR(20)  NOT NULL,
  `language`    VARCHAR(8)   NOT NULL,
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `accepted_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_legal_acceptances_unique` (`user_id`, `slug`, `version`),
  CONSTRAINT `user_legal_acceptances_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Labels only: the values are for the operator's lawyer to supply
-- (UPDATE legal_placeholders SET value = '...' WHERE `key` = '...').
-- Re-running never overwrites a value that has been filled in.
INSERT INTO `legal_placeholders` (`key`, `label`) VALUES
  ('operator_name',         'Operator: legal name of the company'),
  ('operator_address',      'Operator: registered address'),
  ('operator_registration', 'Operator: company number and register'),
  ('contact_email',         'Contact email for complaints and account requests'),
  ('controller_name',       'Data controller: legal name'),
  ('controller_address',    'Data controller: address'),
  ('controller_contact',    'Data controller: privacy contact email'),
  ('dpo_contact',           'Data protection officer: name and contact'),
  ('representative',        'EU/UK representative (GDPR Art. 27), or "not required"'),
  ('processors',            'Data processors: e.g. hosting, email delivery, payments'),
  ('retention_period',      'Retention period after account closure, e.g. 12 months'),
  ('supervisory_authority', 'Lead supervisory authority'),
  ('governing_law',         'Governing law / jurisdiction')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);
