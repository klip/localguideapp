<?php

// Every class the API service is made of, for its two entry points:
// `index.php` (internal HTTP, called by the gateway) and `bin/scheduler.php`.
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/classes/Ranking.php';
require_once __DIR__.'/classes/Levels.php';
require_once __DIR__.'/classes/Users.php';
require_once __DIR__.'/classes/Payments.php';
require_once __DIR__.'/classes/Badges.php';
require_once __DIR__.'/classes/Push.php';
require_once __DIR__.'/classes/Notifications.php';
require_once __DIR__.'/classes/Bookings.php';
require_once __DIR__.'/classes/Selections.php';
require_once __DIR__.'/classes/Places.php';
require_once __DIR__.'/classes/Geocoder.php';
require_once __DIR__.'/classes/Profiles.php';
require_once __DIR__.'/classes/Reviews.php';
require_once __DIR__.'/classes/Messages.php';
require_once __DIR__.'/classes/Complaints.php';
require_once __DIR__.'/classes/Translations.php';
require_once __DIR__.'/classes/RequestProcessor.php';
