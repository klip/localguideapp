-- Additive migration — unlike schema.sql, safe to (re)run: both tables use
-- CREATE TABLE IF NOT EXISTS and this never touches `users`/`roles`/`creds`.
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/002_payments_and_selections.sql

-- One row per pack purchase. No real payment gateway is wired up (see
-- UnlockPage.vue) — `Payments::recordPackPurchase()` inserts a row with
-- `status = 'paid'` directly. `status` exists for when a real gateway
-- lands (pending until a webhook confirms it, etc).
CREATE TABLE IF NOT EXISTS `payments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `amount`     DECIMAL(6,2) NOT NULL,
  `pack_size`  INT UNSIGNED NOT NULL,
  `status`     ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'paid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `payments_user_id` (`user_id`),
  CONSTRAINT `payments_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (guest, guide) pair — not per decision. Each side records its
-- own decision independently; `active` is 1 exactly when both sides are
-- 'interested' (a match), computed and persisted by Selections::syncActive()
-- on every write rather than derived on read.
--
-- Revoking (Selections::revoke()) clears only the acting side's decision
-- back to NULL and re-syncs `active` to 0 — the row and the other side's
-- decision are kept, so the pair can become an active match again later if
-- both sides pick 'interested' again, and the revoking user is free to be
-- matched elsewhere in the meantime.
CREATE TABLE IF NOT EXISTS `selections` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `guest_id`       INT UNSIGNED NOT NULL,
  `guide_id`       INT UNSIGNED NOT NULL,
  `guest_decision` ENUM('interested','pass') DEFAULT NULL,
  `guide_decision` ENUM('interested','pass') DEFAULT NULL,
  `active`         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selections_pair` (`guest_id`, `guide_id`),
  KEY `selections_guide_id` (`guide_id`),
  CONSTRAINT `selections_guest_fk` FOREIGN KEY (`guest_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `selections_guide_fk` FOREIGN KEY (`guide_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
