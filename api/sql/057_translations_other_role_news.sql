-- 057: dual roles, phase 5 — the dot on the mode switch when the role the
-- account isn't acting as has news (AppHeader.vue). Re-runnable (upserts).

SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.app_header.other_role_news', 'New in your other role'),
  ('es', 'common.app_header.other_role_news', 'Novedades en tu otro rol'),
  ('fr', 'common.app_header.other_role_news', 'Du nouveau dans votre autre rôle'),
  ('de', 'common.app_header.other_role_news', 'Neues in deiner anderen Rolle'),
  ('zh', 'common.app_header.other_role_news', '另一身份有新动态'),
  ('it', 'common.app_header.other_role_news', 'Novità nel tuo altro ruolo'),
  ('ja', 'common.app_header.other_role_news', 'もう一方の役割にお知らせがあります'),
  ('ru', 'common.app_header.other_role_news', 'Новое в другой роли'),
  ('ko', 'common.app_header.other_role_news', '다른 역할에 새 소식이 있어요'),
  ('ar', 'common.app_header.other_role_news', 'جديد في دورك الآخر'),
  ('tr', 'common.app_header.other_role_news', 'Diğer rolünüzde yenilik var'),
  ('pt', 'common.app_header.other_role_news', 'Novidades na sua outra função'),
  ('el', 'common.app_header.other_role_news', 'Νέα στον άλλο σας ρόλο'),
  ('th', 'common.app_header.other_role_news', 'มีรายการใหม่ในอีกบทบาทของคุณ'),
  ('uk', 'common.app_header.other_role_news', 'Нове в іншій ролі')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
