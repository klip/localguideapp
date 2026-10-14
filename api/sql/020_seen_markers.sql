-- "Seen" markers behind the red counters on the Matches and My trips menu
-- items (2026-10-14). See `Badges.php`.
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/020_seen_markers.sql
--
-- Each side of a pair has its own marker: a match is new to a user until
-- their `*_seen_at` reaches `selections.matched_at` (so a match that dissolves
-- and forms again is new again), and a trip until it reaches
-- `bookings.accepted_at`. Opening /matches or /trips (`markSeen`) stamps them.
--
-- Everything that exists today is marked seen, so nobody starts with a
-- counter for matches and trips they already know about.
-- Not re-runnable (MySQL 8.4 has no ADD COLUMN IF NOT EXISTS).

ALTER TABLE `selections`
  ADD COLUMN `guest_seen_at` DATETIME NULL AFTER `matched_at`,
  ADD COLUMN `guide_seen_at` DATETIME NULL AFTER `guest_seen_at`;

ALTER TABLE `bookings`
  ADD COLUMN `guest_seen_at` DATETIME NULL AFTER `accepted_at`,
  ADD COLUMN `guide_seen_at` DATETIME NULL AFTER `guest_seen_at`;

UPDATE `selections` SET `guest_seen_at` = UTC_TIMESTAMP(), `guide_seen_at` = UTC_TIMESTAMP();
UPDATE `bookings` SET `guest_seen_at` = UTC_TIMESTAMP(), `guide_seen_at` = UTC_TIMESTAMP();
