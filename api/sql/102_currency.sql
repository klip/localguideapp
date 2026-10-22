-- The header's currency switcher and the "charged in pounds" line before
-- a payment shown in another currency, in 15 languages, and the switcher's
-- text block on every page. Re-runnable.
SET NAMES utf8mb4;

INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT id, 'common.currency_switcher', 'always', 99 FROM app_routes WHERE name = '_shell';

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.currency_switcher.label', 'Currency'),
  ('en', 'common.currency_switcher.auto', 'Automatic ({code})'),
  ('en', 'common.protected_notice.charged_in_gbp', 'Prices are shown in your currency at today’s rate; you’re charged {amount} in pounds.'),
  ('es', 'common.currency_switcher.label', 'Moneda'),
  ('es', 'common.currency_switcher.auto', 'Automática ({code})'),
  ('es', 'common.protected_notice.charged_in_gbp', 'Los precios se muestran en tu moneda al cambio de hoy; se te cobran {amount} en libras.'),
  ('fr', 'common.currency_switcher.label', 'Devise'),
  ('fr', 'common.currency_switcher.auto', 'Automatique ({code})'),
  ('fr', 'common.protected_notice.charged_in_gbp', 'Les prix sont affichés dans votre devise au taux du jour ; vous êtes débité de {amount} en livres.'),
  ('de', 'common.currency_switcher.label', 'Währung'),
  ('de', 'common.currency_switcher.auto', 'Automatisch ({code})'),
  ('de', 'common.protected_notice.charged_in_gbp', 'Preise werden zum heutigen Kurs in deiner Währung angezeigt; abgebucht werden {amount} in Pfund.'),
  ('zh', 'common.currency_switcher.label', '货币'),
  ('zh', 'common.currency_switcher.auto', '自动（{code}）'),
  ('zh', 'common.protected_notice.charged_in_gbp', '价格按今日汇率以你的货币显示；实际扣款为 {amount}（英镑）。'),
  ('it', 'common.currency_switcher.label', 'Valuta'),
  ('it', 'common.currency_switcher.auto', 'Automatica ({code})'),
  ('it', 'common.protected_notice.charged_in_gbp', 'I prezzi sono mostrati nella tua valuta al cambio di oggi; l’addebito è di {amount} in sterline.'),
  ('ja', 'common.currency_switcher.label', '通貨'),
  ('ja', 'common.currency_switcher.auto', '自動（{code}）'),
  ('ja', 'common.protected_notice.charged_in_gbp', '価格は本日のレートであなたの通貨で表示しています。請求額は英ポンドで {amount} です。'),
  ('ru', 'common.currency_switcher.label', 'Валюта'),
  ('ru', 'common.currency_switcher.auto', 'Автоматически ({code})'),
  ('ru', 'common.protected_notice.charged_in_gbp', 'Цены показаны в вашей валюте по сегодняшнему курсу; списывается {amount} в фунтах.'),
  ('ko', 'common.currency_switcher.label', '통화'),
  ('ko', 'common.currency_switcher.auto', '자동({code})'),
  ('ko', 'common.protected_notice.charged_in_gbp', '가격은 오늘 환율로 귀하의 통화로 표시됩니다. 결제는 파운드로 {amount}입니다.'),
  ('ar', 'common.currency_switcher.label', 'العملة'),
  ('ar', 'common.currency_switcher.auto', 'تلقائي ({code})'),
  ('ar', 'common.protected_notice.charged_in_gbp', 'تُعرض الأسعار بعملتك وفق سعر اليوم؛ ويُخصم منك {amount} بالجنيه الإسترليني.'),
  ('tr', 'common.currency_switcher.label', 'Para birimi'),
  ('tr', 'common.currency_switcher.auto', 'Otomatik ({code})'),
  ('tr', 'common.protected_notice.charged_in_gbp', 'Fiyatlar bugünkü kurla sizin para biriminizde gösterilir; sizden sterlin olarak {amount} tahsil edilir.'),
  ('pt', 'common.currency_switcher.label', 'Moeda'),
  ('pt', 'common.currency_switcher.auto', 'Automática ({code})'),
  ('pt', 'common.protected_notice.charged_in_gbp', 'Os preços são mostrados na sua moeda ao câmbio de hoje; é cobrado {amount} em libras.'),
  ('el', 'common.currency_switcher.label', 'Νόμισμα'),
  ('el', 'common.currency_switcher.auto', 'Αυτόματα ({code})'),
  ('el', 'common.protected_notice.charged_in_gbp', 'Οι τιμές εμφανίζονται στο νόμισμά σας με τη σημερινή ισοτιμία· χρεώνεστε {amount} σε λίρες.'),
  ('th', 'common.currency_switcher.label', 'สกุลเงิน'),
  ('th', 'common.currency_switcher.auto', 'อัตโนมัติ ({code})'),
  ('th', 'common.protected_notice.charged_in_gbp', 'ราคาแสดงในสกุลเงินของคุณตามอัตราแลกเปลี่ยนวันนี้ คุณจะถูกเรียกเก็บ {amount} เป็นเงินปอนด์'),
  ('uk', 'common.currency_switcher.label', 'Валюта'),
  ('uk', 'common.currency_switcher.auto', 'Автоматично ({code})'),
  ('uk', 'common.protected_notice.charged_in_gbp', 'Ціни показано у вашій валюті за сьогоднішнім курсом; списується {amount} у фунтах.')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
