<?php
require_once __DIR__.'/classes/db.php';
require_once __DIR__.'/classes/AuthenticationException.php';
require_once __DIR__.'/classes/Auth.php';
require_once __DIR__.'/classes/Session.php';
require_once __DIR__.'/classes/Users.php';
require_once __DIR__.'/classes/Payments.php';
require_once __DIR__.'/classes/Mailer.php';
require_once __DIR__.'/classes/Notifications.php';
require_once __DIR__.'/classes/Bookings.php';
require_once __DIR__.'/classes/Selections.php';
require_once __DIR__.'/classes/Profiles.php';
require_once __DIR__.'/classes/RequestProcessor.php';

$processor = new RequestProcessor(new db());
$processor->handle();
