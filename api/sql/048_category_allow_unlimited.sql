-- 048: a range category can offer "Unlimited" in the filter bar — a switch,
-- on by default, that leaves the category unfiltered and mutes its slider;
-- switching it off activates the range (FilterPanel.vue). Set for
-- `distance` only. Plus the switch's label in all 15 languages.
-- Not re-runnable (ADD COLUMN); the translation upserts are.

SET NAMES utf8mb4;

ALTER TABLE `attribute_categories`
  ADD COLUMN `allow_unlimited` TINYINT(1) NOT NULL DEFAULT 0 AFTER `range_max`;

UPDATE `attribute_categories` SET `allow_unlimited` = 1 WHERE `key` = 'distance';

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.filter_panel.unlimited', 'Unlimited'),
  ('es', 'common.filter_panel.unlimited', 'Sin límite'),
  ('fr', 'common.filter_panel.unlimited', 'Illimitée'),
  ('de', 'common.filter_panel.unlimited', 'Unbegrenzt'),
  ('zh', 'common.filter_panel.unlimited', '不限'),
  ('it', 'common.filter_panel.unlimited', 'Illimitata'),
  ('ja', 'common.filter_panel.unlimited', '制限なし'),
  ('ru', 'common.filter_panel.unlimited', 'Без ограничений'),
  ('ko', 'common.filter_panel.unlimited', '제한 없음'),
  ('ar', 'common.filter_panel.unlimited', 'بلا حدود'),
  ('tr', 'common.filter_panel.unlimited', 'Sınırsız'),
  ('pt', 'common.filter_panel.unlimited', 'Ilimitada'),
  ('el', 'common.filter_panel.unlimited', 'Χωρίς όριο'),
  ('th', 'common.filter_panel.unlimited', 'ไม่จำกัด'),
  ('uk', 'common.filter_panel.unlimited', 'Без обмежень')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
