-- My trips: where a trip meets, and telling confirmed trips apart (2026-10-05).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/015_trip_location.sql
--
-- Not re-runnable (ALTER); a second run errors out on the first statement.
--
-- `location` is a short, free-text description of where the trip meets
-- ("Casemates Square, by the fountain"). The guest can give it when paying;
-- either side can set or change it while the booking is open.
ALTER TABLE `bookings`
  ADD COLUMN `location` VARCHAR(160) NULL AFTER `duration_hours`;

-- A trip is a booking that was confirmed at some point: `confirmed`, or
-- `cancelled` after being accepted (`Bookings::tripsFor()`). Bookings
-- cancelled before 014 never got an `accepted_at`, but back then every
-- booking was born confirmed — so they were accepted when created.
UPDATE `bookings` SET `accepted_at` = `created_at`, `updated_at` = `updated_at`
WHERE `status` = 'cancelled' AND `accepted_at` IS NULL;
