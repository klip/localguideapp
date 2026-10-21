<?php

/**
 * Writes the page map the CMS owns (`app_routes`, `app_route_blocks` —
 * `AppMap::forApp()`) into `client/src/router/appMap.json`: the copy bundled
 * with the app, used when the live map can't be fetched at start-up, by
 * tests, and by `yarn i18n:check`. The database stays the source; run this
 * after changing pages in the CMS and before a release:
 *
 *   docker exec lemp-rg-api php /var/www/localguideapp/api/bin/pull-app-map.php [--check]
 *
 * `--check` changes nothing and exits 1 when the file is out of date.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

$check = in_array('--check', array_slice($argv, 1), true);
$file = dirname(__DIR__, 2) . '/client/src/router/appMap.json';

$map = (new AppMap(new db()))->forApp();
$json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// PHP indents with 4 spaces; the client's files use 2.
$json = preg_replace_callback('/^( +)/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), (string) $json) . "\n";

$current = is_readable($file) ? (string) file_get_contents($file) : '';
if ($check) {
    echo $current === $json ? "appMap.json is up to date ({$map['version']}).\n" : "appMap.json is out of date — run pull-app-map.php.\n";
    exit($current === $json ? 0 : 1);
}
file_put_contents($file, $json);
echo "Wrote client/src/router/appMap.json ({$map['version']}, " . count($map['routes']) . " routes).\n";
