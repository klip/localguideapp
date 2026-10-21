<?php
// The CMS service's entry point: `POST /api` on the CMS's origin — see
// classes/CmsApi.php. (Staff sign-in is the auth service's `auth/cms.php`.)
require_once __DIR__.'/bootstrap.php';

(new CmsApi(new db(), new RedisClient()))->serve();
