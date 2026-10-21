<?php

// The shared library every BeLikeLocal service is built on (api, auth, gateway,
// mailer). Each service's own bootstrap requires this first, then calls
// `Log::install('<service>')`.
require_once __DIR__.'/Log.php';
require_once __DIR__.'/Resp.php';
require_once __DIR__.'/RedisClient.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/Roles.php';
require_once __DIR__.'/AuthenticationException.php';
require_once __DIR__.'/Jwt.php';
require_once __DIR__.'/Origins.php';
require_once __DIR__.'/ClientIp.php';
require_once __DIR__.'/Revocations.php';
require_once __DIR__.'/Staff.php';
require_once __DIR__.'/StaffTokens.php';
require_once __DIR__.'/ImageData.php';
require_once __DIR__.'/MailQueue.php';
require_once __DIR__.'/LegalDocuments.php';
require_once __DIR__.'/AppMap.php';
require_once __DIR__.'/Freezes.php';
require_once __DIR__.'/ContactFilter.php';
require_once __DIR__.'/Ai.php';
require_once __DIR__.'/ServiceHttp.php';
