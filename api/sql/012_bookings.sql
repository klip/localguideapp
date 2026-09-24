-- Bookings, in-app notifications, and when a match happened (2026-09-24).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/012_bookings.sql
--
-- The two CREATE TABLEs are IF NOT EXISTS-guarded; the ALTER on `selections`
-- is not (MySQL 8.4 has no ADD COLUMN IF NOT EXISTS), so a rerun errors on it
-- safely after the tables are already in place.

-- A booking is what a match turns into once contact details are unlocked:
-- the guest paid the guide's fee, or the guide works for free (price 0 or
-- unset), in which case it is created with `amount = 0` and
-- `payment_status = 'none'` the moment the match forms. Contact details are
-- only ever shown for a pair with a `confirmed` booking (see
-- `Selections::getOverview()`).
--
-- Like `payments`, nothing real is charged or refunded — `GuideFees::pay()`
-- records `payment_status = 'paid'` directly.
--
-- A guide can cancel at any time (`GuideFees::cancel()`): the booking becomes
-- `cancelled`, a paid fee `refunded`, and `refund_amount` records what went
-- back — the fee plus 5% when the cancellation came late (see
-- `GuideFees::refundAmount()`).
--
-- `trip_status` is advanced by the guide (a later task). The reviews feature
-- only lets the two people on a `confirmed` booking whose trip is `finished`
-- review each other.
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `selection_id`   INT UNSIGNED NOT NULL,
  `guest_id`       INT UNSIGNED NOT NULL,
  `guide_id`       INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(8,2) NOT NULL DEFAULT 0,
  `meeting_at`     DATETIME     NULL,
  `status`         ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `payment_status` ENUM('none','paid','refunded') NOT NULL DEFAULT 'none',
  `refund_amount`  DECIMAL(8,2) NULL,
  `cancel_reason`  VARCHAR(256) NULL,
  `cancelled_at`   DATETIME     NULL,
  `trip_status`    ENUM('pending','started','finished') NOT NULL DEFAULT 'pending',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- At most one open booking per pair. A finished trip closes it, so the
  -- same guest and guide can book again for the next trip; a cancelled one
  -- is closed too. NULLs don't collide in a UNIQUE index.
  `open_pair` VARCHAR(32) GENERATED ALWAYS AS (
    IF(`status` = 'confirmed' AND `trip_status` <> 'finished', CONCAT(`guest_id`, '-', `guide_id`), NULL)
  ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bookings_open_pair` (`open_pair`),
  KEY `bookings_guest_id` (`guest_id`),
  KEY `bookings_guide_id` (`guide_id`),
  KEY `bookings_selection_id` (`selection_id`),
  -- No FKs on `guest_id`/`guide_id`: MySQL refuses a cascading FK on a
  -- base column of a stored generated column (`open_pair`). Deleting a user
  -- still removes their bookings, through `selections`' own cascade.
  CONSTRAINT `bookings_selection_fk` FOREIGN KEY (`selection_id`) REFERENCES `selections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- In-app notifications: written server-side when something happens to a user
-- that they weren't there to see (a booking paid for, a booking cancelled),
-- fetched and shown as toasts by the client, then marked read.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `kind`       VARCHAR(32)  NOT NULL,
  `message`    VARCHAR(512) NOT NULL,
  `read_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `notifications_user_unread` (`user_id`, `read_at`),
  CONSTRAINT `notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- When the pair last became an active match — the late-cancellation rule
-- shortens its window from 24 hours to 2 for a match younger than a day.
-- `Selections::syncActive()` sets it on the flip to active. Existing active
-- matches get their last update time, the closest record there is.
ALTER TABLE `selections` ADD COLUMN `matched_at` DATETIME NULL AFTER `active`;
UPDATE `selections` SET `matched_at` = `updated_at`, `updated_at` = `updated_at` WHERE `active` = 1;
