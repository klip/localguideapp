<?php

// Every class the auth service is made of (see `AuthProcessor`).
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/classes/Auth.php';
require_once __DIR__.'/classes/Session.php';
require_once __DIR__.'/classes/Tokens.php';
require_once __DIR__.'/classes/GeoIp.php';
require_once __DIR__.'/classes/Devices.php';
require_once __DIR__.'/classes/Risk.php';
require_once __DIR__.'/classes/Accounts.php';
require_once __DIR__.'/classes/AccountSecurity.php';
require_once __DIR__.'/classes/AuthProcessor.php';

Log::install('auth');
