-- Screen-reader text for the red counters on the Matches / My trips menu
-- items (2026-10-14): "3 new". Needs 018. Re-runnable (upserts).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/021_translations_counters.sql

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.app_header.new_count', '{count} new'),
  ('es', 'common.app_header.new_count', '{count} nuevos'),
  ('fr', 'common.app_header.new_count', '{count} nouveaux'),
  ('de', 'common.app_header.new_count', '{count} neu'),
  ('zh', 'common.app_header.new_count', '{count} 个新的'),
  ('it', 'common.app_header.new_count', '{count} nuovi'),
  ('ja', 'common.app_header.new_count', '新着 {count} 件'),
  ('ru', 'common.app_header.new_count', 'новых: {count}'),
  ('ko', 'common.app_header.new_count', '새 항목 {count}개'),
  ('ar', 'common.app_header.new_count', '{count} جديدة'),
  ('tr', 'common.app_header.new_count', '{count} yeni'),
  ('pt', 'common.app_header.new_count', '{count} novos'),
  ('el', 'common.app_header.new_count', '{count} νέα'),
  ('th', 'common.app_header.new_count', 'ใหม่ {count} รายการ'),
  ('uk', 'common.app_header.new_count', 'нових: {count}')
AS new ON DUPLICATE KEY UPDATE `value` = new.`value`;
