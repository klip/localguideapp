-- PlaceSearch's own "Cancel" (beside "Use my current location"), copied in
-- every language from the filters' existing "Cancel"; and the filters' text
-- block on the profile page, whose radius is now the filters' distance
-- slider ("Within 25 km" / "Unlimited"). Re-runnable.
SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`)
SELECT `language`, 'common.place_search.cancel', `value`
FROM `translations` WHERE `key` = 'common.filter_panel.cancel'
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT id, 'common.filter_panel', 'shared', 99 FROM app_routes WHERE name = 'profile';
