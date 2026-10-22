-- The bar above the discovery decks (DeckBar.vue): the filters in force and,
-- on the traveller's deck, the picks counter button ("2/10 left", which opens
-- the add-picks dialog). Its blocks on both deck routes, and its text in 15
-- languages. Re-runnable.

SET NAMES utf8mb4;

INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT r.`id`, b.`prefix`, b.`area`, b.`position` FROM (
  SELECT 'discover' AS `route`, 'discover.bar' AS `prefix`, 'main' AS `area`, -1 AS `position`
  UNION ALL SELECT 'discover', 'common.deck_bar', 'shared', 99
  UNION ALL SELECT 'guide-discover', 'common.deck_bar', 'shared', 99
) b JOIN `app_routes` r ON r.`name` = b.`route`;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'discover.bar.picks_left', '{left}/{capacity} left'),
  ('en', 'discover.bar.get_picks', 'Get picks'),
  ('en', 'discover.bar.add_picks', 'Add picks'),
  ('en', 'common.deck_bar.label', 'Filters and picks'),
  ('es', 'discover.bar.picks_left', 'Quedan {left}/{capacity}'),
  ('es', 'discover.bar.get_picks', 'Conseguir elecciones'),
  ('es', 'discover.bar.add_picks', 'Añadir elecciones'),
  ('es', 'common.deck_bar.label', 'Filtros y elecciones'),
  ('fr', 'discover.bar.picks_left', '{left}/{capacity} restants'),
  ('fr', 'discover.bar.get_picks', 'Obtenir des choix'),
  ('fr', 'discover.bar.add_picks', 'Ajouter des choix'),
  ('fr', 'common.deck_bar.label', 'Filtres et choix'),
  ('de', 'discover.bar.picks_left', '{left}/{capacity} übrig'),
  ('de', 'discover.bar.get_picks', 'Auswahlen holen'),
  ('de', 'discover.bar.add_picks', 'Auswahlen hinzufügen'),
  ('de', 'common.deck_bar.label', 'Filter und Auswahlen'),
  ('zh', 'discover.bar.picks_left', '剩余 {left}/{capacity}'),
  ('zh', 'discover.bar.get_picks', '获取挑选次数'),
  ('zh', 'discover.bar.add_picks', '增加挑选次数'),
  ('zh', 'common.deck_bar.label', '筛选和挑选次数'),
  ('it', 'discover.bar.picks_left', '{left}/{capacity} rimaste'),
  ('it', 'discover.bar.get_picks', 'Ottieni scelte'),
  ('it', 'discover.bar.add_picks', 'Aggiungi scelte'),
  ('it', 'common.deck_bar.label', 'Filtri e scelte'),
  ('ja', 'discover.bar.picks_left', '残り {left}/{capacity}'),
  ('ja', 'discover.bar.get_picks', '選択枠を入手'),
  ('ja', 'discover.bar.add_picks', '選択枠を追加'),
  ('ja', 'common.deck_bar.label', 'フィルターと選択枠'),
  ('ru', 'discover.bar.picks_left', 'Осталось {left}/{capacity}'),
  ('ru', 'discover.bar.get_picks', 'Получить выборы'),
  ('ru', 'discover.bar.add_picks', 'Добавить выборы'),
  ('ru', 'common.deck_bar.label', 'Фильтры и выборы'),
  ('ko', 'discover.bar.picks_left', '{left}/{capacity} 남음'),
  ('ko', 'discover.bar.get_picks', '선택권 받기'),
  ('ko', 'discover.bar.add_picks', '선택권 추가'),
  ('ko', 'common.deck_bar.label', '필터와 선택권'),
  ('ar', 'discover.bar.picks_left', 'متبقٍّ {left}/{capacity}'),
  ('ar', 'discover.bar.get_picks', 'احصل على اختيارات'),
  ('ar', 'discover.bar.add_picks', 'أضف اختيارات'),
  ('ar', 'common.deck_bar.label', 'الفلاتر والاختيارات'),
  ('tr', 'discover.bar.picks_left', '{left}/{capacity} kaldı'),
  ('tr', 'discover.bar.get_picks', 'Seçim hakkı al'),
  ('tr', 'discover.bar.add_picks', 'Seçim hakkı ekle'),
  ('tr', 'common.deck_bar.label', 'Filtreler ve seçim hakları'),
  ('pt', 'discover.bar.picks_left', 'Restam {left}/{capacity}'),
  ('pt', 'discover.bar.get_picks', 'Obter escolhas'),
  ('pt', 'discover.bar.add_picks', 'Adicionar escolhas'),
  ('pt', 'common.deck_bar.label', 'Filtros e escolhas'),
  ('el', 'discover.bar.picks_left', 'Απομένουν {left}/{capacity}'),
  ('el', 'discover.bar.get_picks', 'Αποκτήστε επιλογές'),
  ('el', 'discover.bar.add_picks', 'Προσθήκη επιλογών'),
  ('el', 'common.deck_bar.label', 'Φίλτρα και επιλογές'),
  ('th', 'discover.bar.picks_left', 'เหลือ {left}/{capacity}'),
  ('th', 'discover.bar.get_picks', 'รับสิทธิ์เลือก'),
  ('th', 'discover.bar.add_picks', 'เพิ่มสิทธิ์เลือก'),
  ('th', 'common.deck_bar.label', 'ตัวกรองและสิทธิ์เลือก'),
  ('uk', 'discover.bar.picks_left', 'Залишилось {left}/{capacity}'),
  ('uk', 'discover.bar.get_picks', 'Отримати вибори'),
  ('uk', 'discover.bar.add_picks', 'Додати вибори'),
  ('uk', 'common.deck_bar.label', 'Фільтри та вибори')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
