<?php

// Every class the CMS service is made of (see `CmsApi`).
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/classes/Permissions.php';
require_once __DIR__.'/classes/Audit.php';
// The language list (Translations::LANGUAGES) is the API service's.
require_once __DIR__.'/../api/classes/Translations.php';
require_once __DIR__.'/../api/classes/Levels.php';
require_once __DIR__.'/../api/classes/Ranking.php';
require_once __DIR__.'/../api/classes/Places.php';
require_once __DIR__.'/classes/ContentEditor.php';
require_once __DIR__.'/classes/UserAdmin.php';
require_once __DIR__.'/classes/UserEditor.php';
require_once __DIR__.'/classes/UserFreezes.php';
require_once __DIR__.'/classes/Moderation.php';
require_once __DIR__.'/classes/CmsApi.php';

Log::install('cms');
