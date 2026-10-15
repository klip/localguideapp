-- 029: UI text for the contact filter (see lib/ContactFilter.php, 028) —
-- the error a refused save shows and the hint under the profile's text
-- fields. Re-runnable (upserts).

SET NAMES utf8mb4;

INSERT INTO `translations` (`language`, `key`, `value`) VALUES
  ('en', 'common.api.contact_details', 'Contact details (phone numbers, emails, links or social media handles) can’t be shared here. They’re exchanged once a trip is confirmed.'),
  ('es', 'common.api.contact_details', 'Aquí no se pueden compartir datos de contacto (teléfonos, correos, enlaces ni usuarios de redes sociales). Se intercambian cuando se confirma un viaje.'),
  ('fr', 'common.api.contact_details', 'Les coordonnées (numéros de téléphone, e-mails, liens ou pseudos de réseaux sociaux) ne peuvent pas être partagées ici. Elles sont échangées une fois le voyage confirmé.'),
  ('de', 'common.api.contact_details', 'Kontaktdaten (Telefonnummern, E-Mails, Links oder Social-Media-Namen) können hier nicht geteilt werden. Sie werden ausgetauscht, sobald eine Tour bestätigt ist.'),
  ('zh', 'common.api.contact_details', '此处不能分享联系方式（电话号码、电子邮件、链接或社交媒体账号）。行程确认后会自动交换。'),
  ('it', 'common.api.contact_details', 'Qui non è possibile condividere recapiti (numeri di telefono, email, link o nomi utente social). Vengono scambiati quando il viaggio è confermato.'),
  ('ja', 'common.api.contact_details', 'ここでは連絡先（電話番号、メールアドレス、リンク、SNSのアカウント）は共有できません。ツアーが確定すると交換されます。'),
  ('ru', 'common.api.contact_details', 'Здесь нельзя указывать контактные данные (номера телефонов, email, ссылки или аккаунты в соцсетях). Ими обмениваются после подтверждения поездки.'),
  ('ko', 'common.api.contact_details', '여기에는 연락처(전화번호, 이메일, 링크, SNS 계정)를 공유할 수 없습니다. 투어가 확정되면 교환됩니다.'),
  ('ar', 'common.api.contact_details', 'لا يمكن مشاركة بيانات الاتصال (أرقام الهاتف أو البريد الإلكتروني أو الروابط أو حسابات التواصل الاجتماعي) هنا. يتم تبادلها بعد تأكيد الرحلة.'),
  ('tr', 'common.api.contact_details', 'İletişim bilgileri (telefon numaraları, e-postalar, bağlantılar veya sosyal medya kullanıcı adları) burada paylaşılamaz. Tur onaylandığında paylaşılır.'),
  ('pt', 'common.api.contact_details', 'Não é possível partilhar contactos aqui (números de telefone, e-mails, links ou perfis de redes sociais). São trocados quando a viagem é confirmada.'),
  ('el', 'common.api.contact_details', 'Τα στοιχεία επικοινωνίας (τηλέφωνα, email, σύνδεσμοι ή λογαριασμοί κοινωνικών δικτύων) δεν μπορούν να κοινοποιηθούν εδώ. Ανταλλάσσονται μόλις επιβεβαιωθεί το ταξίδι.'),
  ('th', 'common.api.contact_details', 'ไม่สามารถแชร์ข้อมูลติดต่อ (เบอร์โทร อีเมล ลิงก์ หรือบัญชีโซเชียลมีเดีย) ที่นี่ได้ ข้อมูลจะถูกแลกเปลี่ยนเมื่อยืนยันทริปแล้ว'),
  ('uk', 'common.api.contact_details', 'Тут не можна ділитися контактними даними (номерами телефонів, email, посиланнями чи акаунтами в соцмережах). Ними обмінюються після підтвердження поїздки.'),
  ('en', 'profile.details.contact_hint', 'Please don’t include phone numbers, emails, links or social media handles — contact details are shared automatically once a trip is confirmed.'),
  ('es', 'profile.details.contact_hint', 'No incluyas teléfonos, correos, enlaces ni usuarios de redes sociales: los datos de contacto se comparten automáticamente cuando se confirma un viaje.'),
  ('fr', 'profile.details.contact_hint', 'N’indiquez pas de numéros de téléphone, d’e-mails, de liens ni de pseudos de réseaux sociaux : les coordonnées sont partagées automatiquement une fois le voyage confirmé.'),
  ('de', 'profile.details.contact_hint', 'Bitte keine Telefonnummern, E-Mails, Links oder Social-Media-Namen angeben – Kontaktdaten werden automatisch geteilt, sobald eine Tour bestätigt ist.'),
  ('zh', 'profile.details.contact_hint', '请勿填写电话号码、电子邮件、链接或社交媒体账号——行程确认后，联系方式会自动分享。'),
  ('it', 'profile.details.contact_hint', 'Non inserire numeri di telefono, email, link o nomi utente social: i recapiti vengono condivisi automaticamente quando il viaggio è confermato.'),
  ('ja', 'profile.details.contact_hint', '電話番号、メールアドレス、リンク、SNSのアカウントは書かないでください。連絡先はツアーが確定すると自動的に共有されます。'),
  ('ru', 'profile.details.contact_hint', 'Не указывайте номера телефонов, email, ссылки или аккаунты в соцсетях — контакты передаются автоматически после подтверждения поездки.'),
  ('ko', 'profile.details.contact_hint', '전화번호, 이메일, 링크, SNS 계정은 적지 마세요. 연락처는 투어가 확정되면 자동으로 공유됩니다.'),
  ('ar', 'profile.details.contact_hint', 'يُرجى عدم كتابة أرقام الهاتف أو البريد الإلكتروني أو الروابط أو حسابات التواصل الاجتماعي — تتم مشاركة بيانات الاتصال تلقائيًا بعد تأكيد الرحلة.'),
  ('tr', 'profile.details.contact_hint', 'Lütfen telefon numarası, e-posta, bağlantı veya sosyal medya kullanıcı adı yazmayın — iletişim bilgileri tur onaylandığında otomatik olarak paylaşılır.'),
  ('pt', 'profile.details.contact_hint', 'Não inclua números de telefone, e-mails, links ou perfis de redes sociais — os contactos são partilhados automaticamente quando a viagem é confirmada.'),
  ('el', 'profile.details.contact_hint', 'Μην προσθέτετε τηλέφωνα, email, συνδέσμους ή λογαριασμούς κοινωνικών δικτύων — τα στοιχεία επικοινωνίας κοινοποιούνται αυτόματα μόλις επιβεβαιωθεί το ταξίδι.'),
  ('th', 'profile.details.contact_hint', 'โปรดอย่าใส่เบอร์โทร อีเมล ลิงก์ หรือบัญชีโซเชียลมีเดีย — ข้อมูลติดต่อจะถูกแชร์โดยอัตโนมัติเมื่อยืนยันทริปแล้ว'),
  ('uk', 'profile.details.contact_hint', 'Не вказуйте номери телефонів, email, посилання чи акаунти в соцмережах — контакти передаються автоматично після підтвердження поїздки.')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
