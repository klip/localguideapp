-- The brand is BeLikeLocal (belikelocal.club): every stored mention of the
-- previous name is replaced — UI text in all 15 languages, the legal
-- documents (the 2026-10-15 terms and 2026-10-18 agreements hadn't shipped,
-- so they're amended in place, like their source files in docs/legal/),
-- the two development test accounts' addresses, and old outbox recipients.
--
-- The previous name is split into adjacent literals (MySQL joins them),
-- so this file doesn't spell it.
--
-- Re-runnable (a second run changes nothing).

SET NAMES utf8mb4;

UPDATE `translations`
   SET `value` = REPLACE(`value`, 'Rock' 'Guide', 'BeLikeLocal')
 WHERE `value` LIKE CONCAT('%', 'Rock' 'Guide', '%');

UPDATE `legal_documents`
   SET `title` = REPLACE(`title`, 'Rock' 'Guide', 'BeLikeLocal'),
       `body`  = REPLACE(`body`, 'Rock' 'Guide', 'BeLikeLocal')
 WHERE `title` LIKE CONCAT('%', 'Rock' 'Guide', '%') OR `body` LIKE CONCAT('%', 'Rock' 'Guide', '%');

UPDATE `notifications`
   SET `message` = REPLACE(`message`, 'Rock' 'Guide', 'BeLikeLocal')
 WHERE `message` LIKE CONCAT('%', 'Rock' 'Guide', '%');

UPDATE `users`
   SET `email` = REPLACE(`email`, '@rock' 'guide.test', '@belikelocal.test')
 WHERE `email` LIKE CONCAT('%', '@rock' 'guide.test');

UPDATE `email_outbox`
   SET `recipient` = REPLACE(`recipient`, '@rock' 'guide.test', '@belikelocal.test')
 WHERE `recipient` LIKE CONCAT('%', '@rock' 'guide.test');
