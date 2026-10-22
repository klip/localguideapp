-- Guide Discover's filter panel: "Filters" (as on the traveller's deck) and
-- a new hint, in all 15 languages. Replaces "Availability & fit" / "Match
-- visitors against how you actually work". Re-runnable (upserts).
SET NAMES utf8mb4;

INSERT INTO translations (language, `key`, value)
SELECT language, 'guide_discover.filters.title', value
  FROM translations WHERE `key` = 'discover.filters.title'
ON DUPLICATE KEY UPDATE value = VALUES(value);

INSERT INTO translations (language, `key`, value) VALUES
  ('en', 'guide_discover.filters.hint', 'Who would you like to guide today?'),
  ('es', 'guide_discover.filters.hint', '¿A quién te gustaría guiar hoy?'),
  ('fr', 'guide_discover.filters.hint', 'Qui aimeriez-vous guider aujourd’hui ?'),
  ('de', 'guide_discover.filters.hint', 'Wen möchtest du heute führen?'),
  ('zh', 'guide_discover.filters.hint', '今天你想带谁游览？'),
  ('it', 'guide_discover.filters.hint', 'Chi ti piacerebbe guidare oggi?'),
  ('ja', 'guide_discover.filters.hint', '今日は誰を案内しますか？'),
  ('ru', 'guide_discover.filters.hint', 'Кого вы хотели бы сопровождать сегодня?'),
  ('ko', 'guide_discover.filters.hint', '오늘은 누구를 안내하고 싶으세요?'),
  ('ar', 'guide_discover.filters.hint', 'من تودّ أن ترشده اليوم؟'),
  ('tr', 'guide_discover.filters.hint', 'Bugün kime rehberlik etmek istersiniz?'),
  ('pt', 'guide_discover.filters.hint', 'Quem gostaria de guiar hoje?'),
  ('el', 'guide_discover.filters.hint', 'Ποιον θα θέλατε να ξεναγήσετε σήμερα;'),
  ('th', 'guide_discover.filters.hint', 'วันนี้คุณอยากพาใครเที่ยว'),
  ('uk', 'guide_discover.filters.hint', 'Кого ви хотіли б супроводжувати сьогодні?')
ON DUPLICATE KEY UPDATE value = VALUES(value);
