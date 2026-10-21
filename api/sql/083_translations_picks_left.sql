-- 083: Discover's pick counter — "N selections left" with "Add more" in the
-- deck header and the rail (replacing "x / y picked" and "x / y selected");
-- drops the deck's "selections left" strip and the rail's Unlock button text.
--
-- Re-runnable.

SET NAMES utf8mb4;

DELETE FROM `translations` WHERE `key` IN ('discover.counter.get_more', 'discover.counter.pack_caption', 'discover.counter.free_picks_caption', 'discover.deck.picked', 'discover.rail.count', 'discover.rail.selected', 'discover.rail.unlock_more');

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'discover.deck.add_more', 'Add more'),
  ('es', 'discover.deck.add_more', 'Añadir más'),
  ('fr', 'discover.deck.add_more', 'En ajouter'),
  ('de', 'discover.deck.add_more', 'Mehr hinzufügen'),
  ('zh', 'discover.deck.add_more', '添加更多'),
  ('it', 'discover.deck.add_more', 'Aggiungi altre'),
  ('ja', 'discover.deck.add_more', '追加する'),
  ('ru', 'discover.deck.add_more', 'Добавить'),
  ('ko', 'discover.deck.add_more', '더 추가'),
  ('ar', 'discover.deck.add_more', 'أضف المزيد'),
  ('tr', 'discover.deck.add_more', 'Daha ekle'),
  ('pt', 'discover.deck.add_more', 'Adicionar mais'),
  ('el', 'discover.deck.add_more', 'Προσθήκη'),
  ('th', 'discover.deck.add_more', 'เพิ่มอีก'),
  ('uk', 'discover.deck.add_more', 'Додати ще')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
