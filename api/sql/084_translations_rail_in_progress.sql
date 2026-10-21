-- 084: "In progress" on a trip under way in the Discover rail's trips tab.
--
-- Re-runnable.

SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'discover.rail.in_progress', 'In progress'),
  ('es', 'discover.rail.in_progress', 'En curso'),
  ('fr', 'discover.rail.in_progress', 'En cours'),
  ('de', 'discover.rail.in_progress', 'Läuft'),
  ('zh', 'discover.rail.in_progress', '进行中'),
  ('it', 'discover.rail.in_progress', 'In corso'),
  ('ja', 'discover.rail.in_progress', '進行中'),
  ('ru', 'discover.rail.in_progress', 'Идёт'),
  ('ko', 'discover.rail.in_progress', '진행 중'),
  ('ar', 'discover.rail.in_progress', 'جارية الآن'),
  ('tr', 'discover.rail.in_progress', 'Devam ediyor'),
  ('pt', 'discover.rail.in_progress', 'A decorrer'),
  ('el', 'discover.rail.in_progress', 'Σε εξέλιξη'),
  ('th', 'discover.rail.in_progress', 'กำลังดำเนินอยู่'),
  ('uk', 'discover.rail.in_progress', 'Триває')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
