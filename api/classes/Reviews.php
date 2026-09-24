<?php

/**
 * Data access for `reviews` (see `api/sql/013_reviews.sql`): a 1–5 grade and
 * an optional comment of up to 256 characters, which the guest and the guide
 * of a finished trip leave about each other.
 *
 * Eligibility is a `bookings` row, not a match: the author and the subject
 * must be the guest and guide of a `confirmed` booking whose `trip_status` is
 * `finished` — so only people who actually met, and paid where there was a
 * fee, can review. One review per (booking, author), which the author can
 * edit (`submit()` upserts) or delete.
 *
 * Also the source of every ★ rating the app shows: `summaryFor()` here, and
 * `Users::REVIEW_SUMMARY_JOIN` for the batched version the discovery decks
 * and `publicProfile` read (`user_profiles.rating`/`tours` are no longer
 * used).
 */
class Reviews
{
    public const MAX_COMMENT_LENGTH = 256;
    /** The most `listFor()` returns in one call — the "Show all" modal's page size. */
    public const MAX_PAGE_SIZE = 20;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * One page of `$subjectId`'s reviews, newest first, with the author's
     * name and photo — plus the summary (`total`, `average`, `histogram`) so
     * the profile page gets its header and its first page in one request.
     *
     * @return array{reviews: array[], total: int, average: float|null, histogram: array<int,int>}
     */
    public function listFor(int $subjectId, int $limit, int $offset): array
    {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->db_GetArray(
            self::SELECT_REVIEW . '
             WHERE r.`subject_id` = ' . $subjectId . '
             ORDER BY r.`created_at` DESC, r.`id` DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        return ['reviews' => array_map([$this, 'castReview'], $rows)] + $this->summaryFor($subjectId);
    }

    /**
     * The Google-Maps-style header for one profile: how many reviews, their
     * average (to one decimal, `null` with none), and how many of each grade.
     * `histogram` is keyed 5 → 1 with every grade present, zeros included, so
     * the client can draw five bars without filling gaps.
     *
     * @return array{total: int, average: float|null, histogram: array<int,int>}
     */
    public function summaryFor(int $subjectId): array
    {
        $histogram = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        $sum = 0;

        foreach ($this->db->db_GetArray(
            'SELECT `rating`, COUNT(*) AS `n` FROM `reviews` WHERE `subject_id` = ' . $subjectId . ' GROUP BY `rating`'
        ) as $row) {
            $rating = (int) $row['rating'];
            $count = (int) $row['n'];
            if (isset($histogram[$rating])) {
                $histogram[$rating] = $count;
                $total += $count;
                $sum += $rating * $count;
            }
        }

        return [
            'total' => $total,
            'average' => $total > 0 ? round($sum / $total, 1) : null,
            'histogram' => $histogram,
        ];
    }

    /**
     * What the profile page needs to decide whether to offer "Write a review"
     * / "Edit your review" to the person looking at it. Signed-out viewers
     * and people looking at their own profile never can.
     *
     * @return array{canReview: bool, bookingId: int|null, myReview: array|null}
     */
    public function viewerStateFor(?int $viewerId, int $subjectId): array
    {
        $none = ['canReview' => false, 'bookingId' => null, 'myReview' => null];
        if (!$viewerId || $viewerId === $subjectId) {
            return $none;
        }

        $bookingId = $this->eligibleBookingId($viewerId, $subjectId);
        if (!$bookingId) {
            return $none;
        }

        return [
            'canReview' => true,
            'bookingId' => $bookingId,
            'myReview' => $this->findByBookingAndAuthor($bookingId, $viewerId),
        ];
    }

    /**
     * The booking a review from `$authorId` about `$subjectId` attaches to,
     * or `null` if they aren't eligible: the two have to be the guest and the
     * guide (either way round) of a `confirmed` booking with a `finished`
     * trip. With several such trips, the most recent one this author hasn't
     * reviewed yet wins — so a second trip earns a second review — and
     * failing that the most recent one, whose existing review is then the one
     * being edited.
     */
    public function eligibleBookingId(int $authorId, int $subjectId): ?int
    {
        if ($authorId === $subjectId) {
            return null;
        }

        $id = $this->db->db_GetFieldFromQuery(
            'SELECT b.`id`
             FROM `bookings` b
             LEFT JOIN `reviews` r ON r.`booking_id` = b.`id` AND r.`author_id` = ' . $authorId . '
             WHERE b.`status` = "confirmed" AND b.`trip_status` = "finished"
               AND ((b.`guest_id` = ' . $authorId . ' AND b.`guide_id` = ' . $subjectId . ')
                 OR (b.`guide_id` = ' . $authorId . ' AND b.`guest_id` = ' . $subjectId . '))
             ORDER BY (r.`id` IS NULL) DESC, COALESCE(b.`meeting_at`, b.`created_at`) DESC, b.`id` DESC
             LIMIT 1',
            'id'
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * Creates or replaces `$authorId`'s review of `$subjectId` on the booking
     * `eligibleBookingId()` picks. Validates everything itself, so no caller
     * can store a grade outside 1–5, an over-long comment or a review nobody
     * earned: the grade must be a whole number 1–5, the comment is trimmed,
     * capped at `MAX_COMMENT_LENGTH` characters (not bytes — emoji count as
     * one) and stored as `NULL` when empty.
     *
     * @param mixed $rating straight from the request; anything but an integer 1–5 is rejected
     * @return array the stored review, same shape as `listFor()`'s rows
     */
    public function submit(int $authorId, int $subjectId, $rating, ?string $comment): array
    {
        if ($authorId === $subjectId) {
            throw new RuntimeException('You cannot review yourself.');
        }

        // is_bool first: filter_var() would read JSON `true` as 1.
        $rating = is_bool($rating) || is_array($rating)
            ? false
            : filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($rating === false) {
            throw new RuntimeException('Rating must be a whole number from 1 to 5.');
        }

        $comment = $comment === null ? '' : trim($comment);
        if (!mb_check_encoding($comment, 'UTF-8')) {
            throw new RuntimeException('Comment must be valid text.');
        }
        if (mb_strlen($comment, 'UTF-8') > self::MAX_COMMENT_LENGTH) {
            throw new RuntimeException('Comment must be ' . self::MAX_COMMENT_LENGTH . ' characters or fewer.');
        }

        $bookingId = $this->eligibleBookingId($authorId, $subjectId);
        if (!$bookingId) {
            throw new RuntimeException('You can only review someone after a finished trip with them.');
        }

        // Hand-written rather than `db_InsertUpdate()`: that helper quotes
        // every value, and an empty comment has to be a real NULL.
        $commentSql = $comment === '' ? 'NULL' : '"' . $this->db->db_Escape($comment) . '"';
        $ok = $this->db->db_Execute(
            'INSERT INTO `reviews` (`booking_id`, `author_id`, `subject_id`, `rating`, `comment`)
             VALUES (' . $bookingId . ', ' . $authorId . ', ' . $subjectId . ', ' . $rating . ', ' . $commentSql . ')
             ON DUPLICATE KEY UPDATE `rating` = VALUES(`rating`), `comment` = VALUES(`comment`)'
        );
        if (!$ok) {
            throw new RuntimeException('Could not save your review.');
        }

        $review = $this->findByBookingAndAuthor($bookingId, $authorId);
        if (!$review) {
            throw new RuntimeException('Could not save your review.');
        }

        return $review;
    }

    /** Deletes one of `$authorId`'s own reviews; someone else's (or a missing one) is "not found" either way. */
    public function delete(int $authorId, int $reviewId): void
    {
        $this->db->db_Execute('DELETE FROM `reviews` WHERE `id` = ' . $reviewId . ' AND `author_id` = ' . $authorId);
        if ($this->db->db_GetAffectedRows() < 1) {
            throw new RuntimeException('Review not found.');
        }
    }

    private function findByBookingAndAuthor(int $bookingId, int $authorId): ?array
    {
        $row = $this->db->db_GetRow(
            self::SELECT_REVIEW . ' WHERE r.`booking_id` = ' . $bookingId . ' AND r.`author_id` = ' . $authorId
        );

        return $row ? $this->castReview($row) : null;
    }

    /**
     * A review plus its author's display name and photo — the author's
     * public face only, never contact details. Timestamps come out as Unix
     * seconds (in the same session time zone they were written in) so
     * `castReview()` can hand the client unambiguous ISO 8601 strings.
     */
    private const SELECT_REVIEW = 'SELECT r.`id`, r.`booking_id`, r.`author_id`, r.`subject_id`, r.`rating`, r.`comment`,
                UNIX_TIMESTAMP(r.`created_at`) AS `created_ts`, UNIX_TIMESTAMP(r.`updated_at`) AS `updated_ts`,
                u.`name` AS `author_name`, u.`image` AS `author_image`
         FROM `reviews` r
         JOIN `users` u ON u.`id` = r.`author_id`';

    private function castReview(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'booking_id' => (int) $row['booking_id'],
            'author_id' => (int) $row['author_id'],
            'subject_id' => (int) $row['subject_id'],
            'rating' => (int) $row['rating'],
            'comment' => $row['comment'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['created_ts']),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['updated_ts']),
            'author_name' => $row['author_name'] !== null && $row['author_name'] !== '' ? $row['author_name'] : 'RockGuide member',
            'author_image' => $row['author_image'] ?: null,
        ];
    }
}
