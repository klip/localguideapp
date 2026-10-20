<?php
// The auth service's browser-facing entry point: `POST /auth/<action>` on the
// app's own origin, with the sign-in cookies — see classes/AuthHttp.php.
// (index.php is the internal one the gateway calls.)
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/classes/AuthHttp.php';

(new AuthHttp(new AuthProcessor(new db(), new RedisClient())))->serve();
