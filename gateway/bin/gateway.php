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
 * tcp://0.0.0.0:8090). nginx proxies `ws(s)://api.rockguide.com/ws` to it.
 * Long-running: restart it after changing its PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../../lib/Log.php';
require_once __DIR__.'/../../lib/Resp.php';
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

// The origins the web app is served from. No Origin at all is a non-browser
// client (a script, a test) — allowed, since nothing here rides on cookies:
// every request carries its own sessionHash. WS_EXTRA_ORIGINS (comma-separated,
// exact match) adds more, e.g. a second dev server on another port.
$extraOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('WS_EXTRA_ORIGINS') ?: ''))));
$allowOrigin = static function (string $origin) use ($extraOrigins): bool {
    return $origin === ''
        || in_array(strtolower($origin), array_map('strtolower', $extraOrigins), true)
        || (bool) preg_match('#^https?://((www|admin|api)\.)?(rockguide\.com|localhost:5173)$#i', $origin);
};

$gateway = new Gateway(
    rtrim(getenv('API_URL') ?: 'http://nginx:8081', '/') . '/',
    rtrim(getenv('AUTH_URL') ?: 'http://nginx:8082', '/') . '/',
    $internalToken
);

Log::info('Gateway listening', ['address' => $address]);
$gateway->run(new WebSocketServer($address, $allowOrigin));
