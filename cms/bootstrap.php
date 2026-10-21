<?php

// Every class the CMS service is made of (see `CmsApi`).
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/classes/Permissions.php';
require_once __DIR__.'/classes/Audit.php';
require_once __DIR__.'/classes/CmsApi.php';

Log::install('cms');
