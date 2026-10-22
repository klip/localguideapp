-- The cancellation fee spelled out before the start (common.booking_panel.
-- cancel_fee_note, replacing request_cancel_note), and the meetup prompt
-- reworded: it now also shows when a location is missing, not only vague.
-- Re-runnable.
SET NAMES utf8mb4;

DELETE FROM `translations` WHERE `key` = 'common.booking_panel.request_cancel_note';

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.booking_panel.cancel_fee_note', 'Cancelling before the trip starts costs a 5% cancellation fee ({fee}). If {name} agrees, you’re refunded {amount} and get your pick back.'),
  ('en', 'common.booking_panel.meetup_prompt', 'We can’t confirm from your locations that you’ve met. Tap “Confirm meetup” when you’re together.'),
  ('es', 'common.booking_panel.cancel_fee_note', 'Cancelar antes de que empiece el viaje tiene una tarifa de cancelación del 5 % ({fee}). Si {name} acepta, se te reembolsan {amount} y recuperas tu elección.'),
  ('es', 'common.booking_panel.meetup_prompt', 'No podemos confirmar con vuestras ubicaciones que os habéis encontrado. Pulsa «Confirmar encuentro» cuando estéis juntos.'),
  ('fr', 'common.booking_panel.cancel_fee_note', 'Annuler avant le début du voyage entraîne des frais d’annulation de 5 % ({fee}). Si {name} accepte, vous êtes remboursé de {amount} et récupérez votre sélection.'),
  ('fr', 'common.booking_panel.meetup_prompt', 'Vos positions ne permettent pas de confirmer que vous vous êtes retrouvés. Appuyez sur « Confirmer la rencontre » quand vous êtes ensemble.'),
  ('de', 'common.booking_panel.cancel_fee_note', 'Eine Stornierung vor Reisebeginn kostet eine Stornogebühr von 5 % ({fee}). Stimmt {name} zu, bekommst du {amount} erstattet und deine Auswahl zurück.'),
  ('de', 'common.booking_panel.meetup_prompt', 'Eure Standorte können nicht bestätigen, dass ihr euch getroffen habt. Tippe auf „Treffen bestätigen“, wenn ihr zusammen seid.'),
  ('zh', 'common.booking_panel.cancel_fee_note', '在行程开始前取消需支付 5% 的取消费（{fee}）。如果 {name} 同意，你将获得 {amount} 退款，并收回你的选择次数。'),
  ('zh', 'common.booking_panel.meetup_prompt', '无法通过你们的位置确认你们已经见面。在一起时请点击“确认见面”。'),
  ('it', 'common.booking_panel.cancel_fee_note', 'Annullare prima dell’inizio del viaggio comporta una penale del 5% ({fee}). Se {name} accetta, ti rimborsiamo {amount} e riavrai la tua scelta.'),
  ('it', 'common.booking_panel.meetup_prompt', 'Dalle vostre posizioni non possiamo confermare che vi siete incontrati. Tocca «Conferma incontro» quando siete insieme.'),
  ('ja', 'common.booking_panel.cancel_fee_note', '旅の開始前にキャンセルすると、5% のキャンセル料（{fee}）がかかります。{name} さんが同意すると {amount} が返金され、ピックも戻ります。'),
  ('ja', 'common.booking_panel.meetup_prompt', '位置情報からは合流を確認できません。一緒になったら「合流を確認」をタップしてください。'),
  ('ru', 'common.booking_panel.cancel_fee_note', 'Отмена до начала поездки — комиссия за отмену 5 % ({fee}). Если {name} согласится, вам вернут {amount}, а выбор вернётся в пакет.'),
  ('ru', 'common.booking_panel.meetup_prompt', 'По вашим геоданным нельзя подтвердить, что вы встретились. Нажмите «Подтвердить встречу», когда будете вместе.'),
  ('ko', 'common.booking_panel.cancel_fee_note', '여행 시작 전에 취소하면 5% 취소 수수료({fee})가 부과됩니다. {name}님이 동의하면 {amount}이(가) 환불되고 선택권도 돌려받습니다.'),
  ('ko', 'common.booking_panel.meetup_prompt', '위치 정보로는 만났는지 확인할 수 없습니다. 함께 있을 때 “만남 확인”을 눌러 주세요.'),
  ('ar', 'common.booking_panel.cancel_fee_note', 'الإلغاء قبل بدء الرحلة عليه رسوم إلغاء بنسبة 5% ({fee}). إذا وافق {name}، يُعاد إليك {amount} وتسترد اختيارك.'),
  ('ar', 'common.booking_panel.meetup_prompt', 'لا يمكننا التأكد من موقعيكما أنكما التقيتما. اضغط «تأكيد اللقاء» عندما تكونان معًا.'),
  ('tr', 'common.booking_panel.cancel_fee_note', 'Gezi başlamadan önce iptal etmenin %5 iptal ücreti vardır ({fee}). {name} kabul ederse {amount} iade edilir ve seçim hakkınız geri verilir.'),
  ('tr', 'common.booking_panel.meetup_prompt', 'Konumlarınızdan buluştuğunuzu doğrulayamıyoruz. Birlikteyken “Buluşmayı onayla”ya dokunun.'),
  ('pt', 'common.booking_panel.cancel_fee_note', 'Cancelar antes do início da viagem tem uma taxa de cancelamento de 5% ({fee}). Se {name} aceitar, é reembolsado {amount} e recupera a sua escolha.'),
  ('pt', 'common.booking_panel.meetup_prompt', 'Não conseguimos confirmar pelas vossas localizações que se encontraram. Toque em «Confirmar encontro» quando estiverem juntos.'),
  ('el', 'common.booking_panel.cancel_fee_note', 'Η ακύρωση πριν από την έναρξη του ταξιδιού έχει χρέωση ακύρωσης 5% ({fee}). Αν συμφωνήσει {name}, σας επιστρέφεται {amount} και παίρνετε πίσω την επιλογή σας.'),
  ('el', 'common.booking_panel.meetup_prompt', 'Δεν μπορούμε να επιβεβαιώσουμε από τις τοποθεσίες σας ότι συναντηθήκατε. Πατήστε «Επιβεβαίωση συνάντησης» όταν είστε μαζί.'),
  ('th', 'common.booking_panel.cancel_fee_note', 'การยกเลิกก่อนเริ่มทริปมีค่าธรรมเนียมการยกเลิก 5% ({fee}) หาก {name} ตกลง คุณจะได้รับเงินคืน {amount} และได้สิทธิ์เลือกคืน'),
  ('th', 'common.booking_panel.meetup_prompt', 'เราไม่สามารถยืนยันจากตำแหน่งของคุณว่าพบกันแล้ว แตะ “ยืนยันการพบกัน” เมื่ออยู่ด้วยกัน'),
  ('uk', 'common.booking_panel.cancel_fee_note', 'Скасування до початку поїздки — комісія за скасування 5 % ({fee}). Якщо {name} погодиться, вам повернуть {amount}, а вибір повернеться в пакет.'),
  ('uk', 'common.booking_panel.meetup_prompt', 'За вашими геоданими не можна підтвердити, що ви зустрілися. Натисніть «Підтвердити зустріч», коли будете разом.')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
