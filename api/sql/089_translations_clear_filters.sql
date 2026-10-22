-- "clear ×" in the Filters heading on both decks (ClearFilters.vue), shown
-- while a filter is set. Re-runnable.

SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.filter_panel.clear_short', 'clear'),
  ('es', 'common.filter_panel.clear_short', 'borrar'),
  ('fr', 'common.filter_panel.clear_short', 'effacer'),
  ('de', 'common.filter_panel.clear_short', 'zurücksetzen'),
  ('zh', 'common.filter_panel.clear_short', '清除'),
  ('it', 'common.filter_panel.clear_short', 'azzera'),
  ('ja', 'common.filter_panel.clear_short', 'クリア'),
  ('ru', 'common.filter_panel.clear_short', 'сбросить'),
  ('ko', 'common.filter_panel.clear_short', '지우기'),
  ('ar', 'common.filter_panel.clear_short', 'مسح'),
  ('tr', 'common.filter_panel.clear_short', 'temizle'),
  ('pt', 'common.filter_panel.clear_short', 'limpar'),
  ('el', 'common.filter_panel.clear_short', 'καθαρισμός'),
  ('th', 'common.filter_panel.clear_short', 'ล้าง'),
  ('uk', 'common.filter_panel.clear_short', 'скинути')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- The header's picks counter (PicksButton.vue) is on every page, so its text
-- gets its own block (common.picks_button) instead of borrowing the Discover
-- page's: copied from those keys in every language. The bar's old keys
-- (discover.bar.*) are no longer used.
INSERT INTO `translations` (`language`, `key`, `value`)
SELECT `language`, CASE `key`
         WHEN 'discover.bar.picks_left' THEN 'common.picks_button.left'
         WHEN 'discover.bar.get_picks' THEN 'common.picks_button.get'
         WHEN 'discover.bar.add_picks' THEN 'common.picks_button.add'
         WHEN 'discover.deck.free_picks' THEN 'common.picks_button.free_on'
         WHEN 'discover.counter.free_picks_until' THEN 'common.picks_button.free_until'
       END, `value`
FROM `translations`
WHERE `key` IN ('discover.bar.picks_left', 'discover.bar.get_picks', 'discover.bar.add_picks',
                'discover.deck.free_picks', 'discover.counter.free_picks_until')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

DELETE FROM `translations` WHERE `key` LIKE 'discover.bar.%';
DELETE b FROM `app_route_blocks` b WHERE b.`prefix` = 'discover.bar';
INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT `id`, 'common.picks_button', 'header', 2 FROM `app_routes` WHERE `name` = '_shell';
