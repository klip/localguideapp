-- 023: sessions moved to Redis, owned by the auth service
-- (`auth/classes/Session.php`); the MySQL table is no longer read or written.
-- Applying this signs everyone out once (sessions only last 5 idle minutes
-- anyway). Applied 2026-10-14. Re-runnable.

DROP TABLE IF EXISTS `sessions`;
