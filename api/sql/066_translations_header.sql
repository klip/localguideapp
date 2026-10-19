-- 066: the header's role switch — "Travel" (switch to traveller mode) and
-- "Start travelling" (a guide adding the traveller role, was "I want to
-- travel"). Drops the old Traveller | Guide toggle's label and the header's
-- picks counter.
--
-- Re-runnable.

SET NAMES utf8mb4;

DELETE FROM `translations` WHERE `key` IN ('common.app_header.mode_guest', 'common.app_header.mode_label', 'common.app_header.picked');

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.app_header.switch_travel', 'Travel'),
  ('es', 'common.app_header.switch_travel', 'Viajar'),
  ('fr', 'common.app_header.switch_travel', 'Voyager'),
  ('de', 'common.app_header.switch_travel', 'Reisen'),
  ('zh', 'common.app_header.switch_travel', '旅行'),
  ('it', 'common.app_header.switch_travel', 'Viaggiare'),
  ('ja', 'common.app_header.switch_travel', '旅行'),
  ('ru', 'common.app_header.switch_travel', 'Путешествовать'),
  ('ko', 'common.app_header.switch_travel', '여행'),
  ('ar', 'common.app_header.switch_travel', 'السفر'),
  ('tr', 'common.app_header.switch_travel', 'Seyahat'),
  ('pt', 'common.app_header.switch_travel', 'Viajar'),
  ('el', 'common.app_header.switch_travel', 'Ταξίδι'),
  ('th', 'common.app_header.switch_travel', 'เที่ยว'),
  ('uk', 'common.app_header.switch_travel', 'Подорожувати'),
  ('en', 'common.app_header.want_to_travel', 'Start travelling'),
  ('es', 'common.app_header.want_to_travel', 'Empezar a viajar'),
  ('fr', 'common.app_header.want_to_travel', 'Commencer à voyager'),
  ('de', 'common.app_header.want_to_travel', 'Reisen starten'),
  ('zh', 'common.app_header.want_to_travel', '开始旅行'),
  ('it', 'common.app_header.want_to_travel', 'Inizia a viaggiare'),
  ('ja', 'common.app_header.want_to_travel', '旅を始める'),
  ('ru', 'common.app_header.want_to_travel', 'Начать путешествовать'),
  ('ko', 'common.app_header.want_to_travel', '여행 시작하기'),
  ('ar', 'common.app_header.want_to_travel', 'ابدأ السفر'),
  ('tr', 'common.app_header.want_to_travel', 'Seyahate başla'),
  ('pt', 'common.app_header.want_to_travel', 'Começar a viajar'),
  ('el', 'common.app_header.want_to_travel', 'Ξεκινήστε να ταξιδεύετε'),
  ('th', 'common.app_header.want_to_travel', 'เริ่มเที่ยว'),
  ('uk', 'common.app_header.want_to_travel', 'Почати подорожувати')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
