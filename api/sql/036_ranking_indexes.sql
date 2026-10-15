-- 036: indexes for the discovery ranking (api/classes/Ranking.php), which
-- groups finished trips per person and reviews per (subject, author) on
-- every deck request. Covering indexes keep both to index scans; a
-- `user_stats` table updated on finish/review is the next step if this
-- grows (see docs/scaling.md).
--
-- Not re-runnable (ADD INDEX).

ALTER TABLE `bookings`
  ADD INDEX `bookings_finished_guest` (`status`, `trip_status`, `guest_id`, `guide_id`),
  ADD INDEX `bookings_finished_guide` (`status`, `trip_status`, `guide_id`, `guest_id`);

ALTER TABLE `reviews`
  ADD INDEX `reviews_subject_author_rating` (`subject_id`, `author_id`, `rating`);
