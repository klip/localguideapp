-- 047: "Distance" becomes a range category (0–50 km), filtered with a
-- two-handle slider like hours and age.
--
-- It was created from a profile page as an `options` category with a single
-- option, "5 km", picked by one account (2026-10-18). That pick becomes the
-- span 0–5 km in `user_attribute_ranges`; then the option goes.
-- Re-runnable: a second run finds no options left to convert.

SET NAMES utf8mb4;

START TRANSACTION;

SET @distance = (SELECT `id` FROM `attribute_categories` WHERE `key` = 'distance');

-- An option like "5 km" means "within 5 km": span 0–n.
INSERT INTO `user_attribute_ranges` (`user_id`, `category_id`, `range_start`, `range_end`)
SELECT v.`user_id`, @distance, 0, LEAST(50, CAST(REGEXP_SUBSTR(o.`value`, '[0-9]+') AS UNSIGNED))
FROM `user_attribute_values` v
JOIN `attribute_options` o ON o.`id` = v.`option_id`
WHERE o.`category_id` = @distance AND REGEXP_SUBSTR(o.`value`, '[0-9]+') > 0;

DELETE v FROM `user_attribute_values` v
JOIN `attribute_options` o ON o.`id` = v.`option_id`
WHERE o.`category_id` = @distance;

DELETE FROM `attribute_options` WHERE `category_id` = @distance;

UPDATE `attribute_categories`
SET `kind` = 'range', `range_min` = 0, `range_max` = 50
WHERE `id` = @distance;

COMMIT;
