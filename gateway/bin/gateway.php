<?php

/**
 * The WebSocket gateway (container `lemp-ws`): every client request and push
 * goes through it — see `Gateway` for the protocol. It has no database
 * access; it calls the API and auth services over internal HTTP and listens
 * to Redis for pushes, so it can be scaled out freely.
 *
 *   php /var/www/localguideapp/gateway/bin/gateway.php
 *
 * Environment: API_URL, AUTH_URL (the services' internal base URLs),
 * INTERNAL_TOKEN (shared with them), REDIS_*, WS_LISTEN (default
 * tcp://0.0.0.0:8090). nginx proxies `ws(s)://api.belikelocal.club/ws` to it.
 * Long-running: restart it after changing its PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../../lib/Log.php';
require_once __DIR__.'/../../lib/Resp.php';
require_once __DIR__.'/../../lib/Origins.php';
require_once __DIR__.'/../../lib/ClientIp.php';
require_once __DIR__.'/../../lib/Jwt.php';
require_once __DIR__.'/../../lib/RedisClient.php';
require_once __DIR__.'/../../lib/Revocations.php';
require_once __DIR__.'/../classes/StreamWatcher.php';
require_once __DIR__.'/../classes/WebSocketServer.php';
require_once __DIR__.'/../classes/HttpClient.php';
require_once __DIR__.'/../classes/RedisSubscriber.php';
require_once __DIR__.'/../classes/Gateway.php';

Log::install('gateway');
set_time_limit(0);

$address = getenv('WS_LISTEN') ?: 'tcp://0.0.0.0:8090';
$internalToken = (string) (getenv('INTERNAL_TOKEN') ?: '');
if ($internalToken === '') {
    Log::error('INTERNAL_TOKEN is not set; the services would refuse every request');
    exit(1);
}

// The origins the web app is served from (see Origins). No Origin at all is a
// non-browser client (a script, a test) — allowed, since nothing here rides on
// cookies: every request carries its own access token (or session hash).
$allowOrigin = static function (string $origin): bool {
    return $origin === '' || Origins::isAllowed($origin);
};

$gateway = new Gateway(
    rtrim(getenv('API_URL') ?: 'http://nginx:8081', '/') . '/',
    rtrim(getenv('AUTH_URL') ?: 'http://nginx:8082', '/') . '/',
    $internalToken
);

Log::info('Gateway listening', ['address' => $address]);
$gateway->run(new WebSocketServer($address, $allowOrigin));
