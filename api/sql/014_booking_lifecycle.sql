-- Booking lifecycle: guide acceptance, guest cancellation requests, and
-- finishing a trip (2026-09-30).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/014_booking_lifecycle.sql
--
-- Not re-runnable (ALTERs); a second run errors out on the first statement.
--
-- New states on `bookings.status`:
--   pending   — the guest paid; the guide hasn't accepted yet. Contact details
--               stay hidden. Accepting makes it `confirmed`; declining (or the
--               meeting time passing unanswered) makes it `declined` and
--               refunds the guest in full. The guest can withdraw it
--               (`withdrawn`, full refund) until then.
--   declined  — see above.
--   withdrawn — see above.
--
-- A guest can ask to cancel a confirmed, paid booking (`cancel_requested_at`
-- + `cancel_request_reason`). If the guide approves, it's cancelled with a
-- refund of the fee minus 5%; if the guide declines, the request is cleared.
--
-- A trip finishes on its own once `meeting_at + duration_hours` has passed
-- (`Bookings::sweep()`); a booking missing either one is finished by one side
-- asking (`finish_requested_by`) and the other confirming.
ALTER TABLE `bookings`
  DROP INDEX `bookings_open_pair`,
  DROP COLUMN `open_pair`;

ALTER TABLE `bookings`
  MODIFY `status` ENUM('pending','confirmed','declined','withdrawn','cancelled') NOT NULL DEFAULT 'confirmed',
  ADD COLUMN `duration_hours`        DECIMAL(4,1) NULL AFTER `meeting_at`,
  ADD COLUMN `accepted_at`           DATETIME     NULL AFTER `payment_status`,
  ADD COLUMN `cancel_requested_at`   DATETIME     NULL AFTER `cancelled_at`,
  ADD COLUMN `cancel_request_reason` VARCHAR(256) NULL AFTER `cancel_requested_at`,
  ADD COLUMN `finish_requested_by`   INT UNSIGNED NULL AFTER `trip_status`,
  ADD COLUMN `finish_requested_at`   DATETIME     NULL AFTER `finish_requested_by`,
  ADD COLUMN `finished_at`           DATETIME     NULL AFTER `finish_requested_at`;

-- At most one open booking per pair — pending counts as open too, so a guest
-- can't stack payments while one waits on the guide.
ALTER TABLE `bookings`
  ADD COLUMN `open_pair` VARCHAR(32) GENERATED ALWAYS AS (
    IF(`status` IN ('pending', 'confirmed') AND `trip_status` <> 'finished', CONCAT(`guest_id`, '-', `guide_id`), NULL)
  ) STORED,
  ADD UNIQUE KEY `bookings_open_pair` (`open_pair`);

-- Confirmed bookings made before acceptance existed were accepted implicitly.
UPDATE `bookings` SET `accepted_at` = `created_at`, `updated_at` = `updated_at` WHERE `status` = 'confirmed' AND `accepted_at` IS NULL;
