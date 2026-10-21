-- A bonus the team granted from the CMS (`bonus_grants.reason` = `staff_<id>`,
-- 080), as LevelPanel labels it. Re-runnable (upserts).

SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'profile.level.reason_staff', 'A gift from the BeLikeLocal team'),
  ('es', 'profile.level.reason_staff', 'Un regalo del equipo de BeLikeLocal'),
  ('fr', 'profile.level.reason_staff', 'Un cadeau de l’équipe BeLikeLocal'),
  ('de', 'profile.level.reason_staff', 'Ein Geschenk vom BeLikeLocal-Team'),
  ('zh', 'profile.level.reason_staff', 'BeLikeLocal 团队赠送'),
  ('it', 'profile.level.reason_staff', 'Un regalo dal team di BeLikeLocal'),
  ('ja', 'profile.level.reason_staff', 'BeLikeLocal チームからのプレゼント'),
  ('ru', 'profile.level.reason_staff', 'Подарок от команды BeLikeLocal'),
  ('ko', 'profile.level.reason_staff', 'BeLikeLocal 팀의 선물'),
  ('ar', 'profile.level.reason_staff', 'هدية من فريق BeLikeLocal'),
  ('tr', 'profile.level.reason_staff', 'BeLikeLocal ekibinden bir hediye'),
  ('pt', 'profile.level.reason_staff', 'Um presente da equipa BeLikeLocal'),
  ('el', 'profile.level.reason_staff', 'Ένα δώρο από την ομάδα του BeLikeLocal'),
  ('th', 'profile.level.reason_staff', 'ของขวัญจากทีม BeLikeLocal'),
  ('uk', 'profile.level.reason_staff', 'Подарунок від команди BeLikeLocal')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
