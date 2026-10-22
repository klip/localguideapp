-- The guide's deck now uses the traveller deck's matches rail (MatchesRail.vue
-- — "Your matches", Matched / Upcoming), so its route preloads that text too,
-- and the rail's empty message gets a guide's version ("when a traveller…").
-- Re-runnable.

SET NAMES utf8mb4;

INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT r.`id`, b.`prefix`, 'shared', 99 FROM (
  SELECT 'discover.rail' AS `prefix` UNION ALL SELECT 'common.booking_time'
) b JOIN `app_routes` r ON r.`name` = 'guide-discover';

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'discover.rail.no_matches_guide', 'No matches yet — when a traveller you like likes you back, they appear here.'),
  ('es', 'discover.rail.no_matches_guide', 'Aún no hay coincidencias: cuando un viajero que te gusta también se interese por ti, aparecerá aquí.'),
  ('fr', 'discover.rail.no_matches_guide', 'Pas encore de match : quand un voyageur qui vous plaît s’intéresse aussi à vous, il apparaît ici.'),
  ('de', 'discover.rail.no_matches_guide', 'Noch keine Matches – wenn ein Reisender, der dir gefällt, auch Interesse zeigt, erscheint er hier.'),
  ('zh', 'discover.rail.no_matches_guide', '暂无匹配——当你喜欢的旅行者也对你感兴趣时，会显示在这里。'),
  ('it', 'discover.rail.no_matches_guide', 'Nessun match per ora: quando un viaggiatore che ti piace ricambia l’interesse, compare qui.'),
  ('ja', 'discover.rail.no_matches_guide', 'まだマッチはありません。気になる旅行者もあなたに興味を示すと、ここに表示されます。'),
  ('ru', 'discover.rail.no_matches_guide', 'Совпадений пока нет — когда понравившийся путешественник ответит взаимностью, он появится здесь.'),
  ('ko', 'discover.rail.no_matches_guide', '아직 매칭이 없어요. 마음에 드는 여행자도 관심을 보이면 여기에 나타나요.'),
  ('ar', 'discover.rail.no_matches_guide', 'لا توجد مطابقات بعد — عندما يبادلك مسافر أعجبك الاهتمام، سيظهر هنا.'),
  ('tr', 'discover.rail.no_matches_guide', 'Henüz eşleşme yok — beğendiğin bir gezgin de seninle ilgilenince burada görünür.'),
  ('pt', 'discover.rail.no_matches_guide', 'Ainda sem matches — quando um viajante de que gosta também se interessar por si, aparece aqui.'),
  ('el', 'discover.rail.no_matches_guide', 'Δεν υπάρχουν ακόμη ταιριάσματα — όταν ένας ταξιδιώτης που σας αρέσει δείξει κι αυτός ενδιαφέρον, θα εμφανιστεί εδώ.'),
  ('th', 'discover.rail.no_matches_guide', 'ยังไม่มีแมตช์ — เมื่อนักท่องเที่ยวที่คุณถูกใจสนใจคุณเช่นกัน จะแสดงที่นี่'),
  ('uk', 'discover.rail.no_matches_guide', 'Збігів поки немає — коли мандрівник, який вам сподобався, відповість взаємністю, він з’явиться тут.')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
