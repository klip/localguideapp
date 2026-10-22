-- The Matches page's link to My trips counts trips the way My trips lists
-- them ("{count} trips"), replacing confirmed_trips_*, which counted pairs.
-- Re-runnable.
SET NAMES utf8mb4;

DELETE FROM `translations` WHERE `key` LIKE 'matches.toolbar.confirmed\_trips\_%';

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'matches.toolbar.trips_one', '{count} trip'),
  ('en', 'matches.toolbar.trips_other', '{count} trips'),
  ('es', 'matches.toolbar.trips_one', '{count} viaje'),
  ('es', 'matches.toolbar.trips_other', '{count} viajes'),
  ('fr', 'matches.toolbar.trips_one', '{count} sortie'),
  ('fr', 'matches.toolbar.trips_other', '{count} sorties'),
  ('de', 'matches.toolbar.trips_one', '{count} Tour'),
  ('de', 'matches.toolbar.trips_other', '{count} Touren'),
  ('it', 'matches.toolbar.trips_one', '{count} tour'),
  ('it', 'matches.toolbar.trips_other', '{count} tour'),
  ('pt', 'matches.toolbar.trips_one', '{count} viagem'),
  ('pt', 'matches.toolbar.trips_other', '{count} viagens'),
  ('ru', 'matches.toolbar.trips_one', '{count} поездка'),
  ('ru', 'matches.toolbar.trips_few', '{count} поездки'),
  ('ru', 'matches.toolbar.trips_many', '{count} поездок'),
  ('ru', 'matches.toolbar.trips_other', '{count} поездки'),
  ('uk', 'matches.toolbar.trips_one', '{count} поїздка'),
  ('uk', 'matches.toolbar.trips_few', '{count} поїздки'),
  ('uk', 'matches.toolbar.trips_many', '{count} поїздок'),
  ('uk', 'matches.toolbar.trips_other', '{count} поїздки'),
  ('el', 'matches.toolbar.trips_one', '{count} ξενάγηση'),
  ('el', 'matches.toolbar.trips_other', '{count} ξεναγήσεις'),
  ('tr', 'matches.toolbar.trips_one', '{count} tur'),
  ('tr', 'matches.toolbar.trips_other', '{count} tur'),
  ('ar', 'matches.toolbar.trips_zero', 'لا توجد رحلات'),
  ('ar', 'matches.toolbar.trips_one', 'رحلة واحدة'),
  ('ar', 'matches.toolbar.trips_two', 'رحلتان'),
  ('ar', 'matches.toolbar.trips_few', '{count} رحلات'),
  ('ar', 'matches.toolbar.trips_many', '{count} رحلة'),
  ('ar', 'matches.toolbar.trips_other', '{count} رحلة'),
  ('ja', 'matches.toolbar.trips_other', 'ツアー {count} 件'),
  ('ko', 'matches.toolbar.trips_other', '투어 {count}개'),
  ('zh', 'matches.toolbar.trips_other', '{count} 个行程'),
  ('th', 'matches.toolbar.trips_other', '{count} ทริป')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
