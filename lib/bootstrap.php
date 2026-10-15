<?php

// The shared library every RockGuide service is built on (api, auth, gateway,
// mailer). Each service's own bootstrap requires this first, then calls
// `Log::install('<service>')`.
require_once __DIR__.'/Log.php';
require_once __DIR__.'/Resp.php';
require_once __DIR__.'/RedisClient.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/AuthenticationException.php';
require_once __DIR__.'/ImageData.php';
require_once __DIR__.'/MailQueue.php';
require_once __DIR__.'/LegalDocuments.php';
require_once __DIR__.'/ContactFilter.php';
require_once __DIR__.'/ServiceHttp.php';
