-- Reviews: a 1–5 grade plus an optional short comment (2026-09-24).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/013_reviews.sql
--
-- Additive and IF NOT EXISTS-guarded, so a rerun is a no-op.
--
-- A review is tied to a booking, not just to a pair of users: only the guest
-- and guide of a `confirmed` booking whose trip is `finished` may review each
-- other (see `Reviews::eligibleBookingId()`), once per booking each — the
-- guest reviews the guide, the guide reviews the guest. The author can edit
-- (upsert on `reviews_booking_author`) or delete their own review.
--
-- `subject_id` is denormalised from the booking (the other side of it) so a
-- profile's reviews, average and histogram are one indexed read.
--
-- Replies are a later feature. They'll need their own table (reply_to a
-- review id, author = the subject) rather than a column here, since a review
-- may one day get more than one — nothing in this table has to change.
--
-- utf8mb4 so a comment can carry emoji; `db.php` connects as utf8mb4 too.
-- `comment` is VARCHAR(256) in characters, matching the `mb_strlen` check in
-- `RequestProcessor::actionSubmitReview()`; NULL when the author left none.
--
-- These replace `user_profiles.rating`/`tours` as the source of a profile's
-- ★ rating (those columns are no longer read or written — drop them in a
-- later migration).
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `booking_id` INT UNSIGNED     NOT NULL,
  `author_id`  INT UNSIGNED     NOT NULL,
  `subject_id` INT UNSIGNED     NOT NULL,
  `rating`     TINYINT UNSIGNED NOT NULL,
  `comment`    VARCHAR(256)     NULL,
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reviews_booking_author` (`booking_id`, `author_id`),
  KEY `reviews_subject_created` (`subject_id`, `created_at`),
  KEY `reviews_author_id` (`author_id`),
  CONSTRAINT `reviews_rating_range` CHECK (`rating` BETWEEN 1 AND 5),
  CONSTRAINT `reviews_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_author_fk` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_subject_fk` FOREIGN KEY (`subject_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
