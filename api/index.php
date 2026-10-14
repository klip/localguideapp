<?php
// The API service's HTTP entry point — internal only, called by the gateway
// (see lib/ServiceHttp.php for the contract).
require_once __DIR__.'/bootstrap.php';
Log::install('api');

ServiceHttp::serve(static function (array $input, array $context): array {
    return (new RequestProcessor(new db(), new RedisClient()))->process($input, $context);
});
