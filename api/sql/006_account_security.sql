-- Adds account-level fields the new profile page needs beyond `users.image`:
-- a contact phone number, and which channel a user wants 2FA codes sent to.
-- No OTP-sending infra exists (no SMS gateway, no mail sender) — same
-- "recorded but not actually wired to a live provider" pattern as
-- `payments.status='paid'` in Payments.php — so this column is a stored
-- preference for a future 2FA implementation, not a working feature yet.
--
-- MySQL 8.4 rejects `ADD COLUMN IF NOT EXISTS` (see 004_users_image.sql) —
-- plain ALTERs, safe to run once.

ALTER TABLE `users`
  ADD COLUMN `phone` VARCHAR(30) NULL AFTER `email`,
  ADD COLUMN `two_factor_method` ENUM('none','email','sms') NOT NULL DEFAULT 'none' AFTER `phone`;
