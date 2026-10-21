<?php

/**
 * Writes the database's English UI text (what the CMS content editor
 * changes) back into `client/src/i18n/en.json` — the copy bundled with the
 * app for tests, a failed fetch and untranslated keys. Run it before a
 * release so the fallback matches what editors wrote:
 *
 *   docker exec lemp-rg-api php /var/www/localguideapp/api/bin/pull-english.php [--check]
 *
 * `--check` changes nothing and exits 1 when the file is out of date.
 * Keys that exist only in the file (added in code, not yet in the database)
 * are kept, and listed, so nothing a developer just wrote is lost.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';

$check = in_array('--check', array_slice($argv, 1), true);
$file = dirname(__DIR__, 2) . '/client/src/i18n/en.json';

$current = json_decode((string) file_get_contents($file), true);
if (!is_array($current)) {
    fwrite(STDERR, "Can't read {$file}.\n");
    exit(1);
}

$db = new db();
$rows = (array) $db->db_GetArray('SELECT `key`, `value` FROM `translations` WHERE `language` = "en"');
$next = $current;
$changed = [];
foreach ($rows as $row) {
    $key = (string) $row['key'];
    $value = (string) $row['value'];
    if (($current[$key] ?? null) !== $value) {
        $changed[] = $key;
        $next[$key] = $value;
    }
}
$inDb = array_flip(array_map(static fn(array $r): string => (string) $r['key'], $rows));
$fileOnly = array_values(array_filter(array_keys($current), static fn(string $k): bool => !isset($inDb[$k])));
ksort($next, SORT_STRING);

foreach ($changed as $key) {
    echo ($check ? 'out of date: ' : 'updated: ') . $key . "\n";
}
foreach ($fileOnly as $key) {
    echo "only in en.json (add it to the database): {$key}\n";
}

if ($check) {
    exit($changed ? 1 : 0);
}
if ($changed) {
    $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // PHP indents with 4 spaces; the file uses 2.
    $json = preg_replace_callback('/^( +)/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), (string) $json);
    file_put_contents($file, $json . "\n");
}
echo count($changed) . " text(s) pulled into en.json.\n";
