<?php
// Staff sign-in for the CMS: `POST /auth/<action>` on the CMS's own origin,
// with the CMS's sign-in cookie — see classes/CmsAuthHttp.php.
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/classes/CmsAuthHttp.php';

$db = new db();
$redis = new RedisClient();
(new CmsAuthHttp(new Staff($db, new MailQueue($db, $redis)), new StaffTokens($db, $redis)))->serve();
