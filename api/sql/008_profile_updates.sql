-- Three profile-page changes:
--
-- 1. `two_factor_method` gains 'whatsapp' as a deliverable channel.
--    'none' stays in the enum (existing rows already hold it, e.g. accounts
--    that haven't visited the profile page yet) but the client no longer
--    offers it as a choice, and `RequestProcessor::actionUpdateAccount()`
--    rejects it going forward — see CLAUDE.md. No data is rewritten here.
--
-- 2. `user_profiles.age` becomes `date_of_birth` (a real date, shown behind
--    a calendar picker instead of a bare number). Confirmed 0 rows in
--    `user_profiles` (checked 2026-09-05) — safe to drop/add outright, same
--    as 007's table replacement.
--
-- 3. Phone stays exactly as it already was — a single combined string in
--    `users.phone` (e.g. "+35056002731"). The country-code picker added to
--    the profile page is frontend-only: it splits that string apart for
--    editing and concatenates it back together before saving. No schema
--    change needed for it.

ALTER TABLE `users`
  MODIFY COLUMN `two_factor_method` ENUM('none','email','sms','whatsapp') NOT NULL DEFAULT 'none';

ALTER TABLE `user_profiles`
  DROP COLUMN `age`,
  ADD COLUMN `date_of_birth` DATE NULL AFTER `user_id`;
