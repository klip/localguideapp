<?php

/**
 * The app's routes (`app_routes`, 079) — managed in the CMS, read by the
 * app. The Vue app builds its router from them at start-up (the `appMap`
 * action, `forApp()`); the CMS's Pages screen edits them.
 *
 * What a page *contains* isn't managed here: its layout is the code's. The
 * list of text blocks each page shows (`app_route_blocks`) is developer-owned
 * — written by migrations, checked against the code by `yarn i18n:check` —
 * and only used to preload a page's text and to draw the content editor's
 * wireframe. Nothing in the CMS changes it.
 *
 * - A route renders a page component the app's code registers
 *   (`client/src/router/pages.ts`) — the map decides where and for whom, the
 *   code what. The app skips a route naming a component it doesn't have.
 * - **Changing a page's path keeps the old one working**: a redirect from
 *   the old path is added (the app's own links are paths in its code).
 * - `system` routes (start, log in, discover, not found, the shell) can't be
 *   removed, disabled or moved — the app's code relies on them.
 * - The app's copy is cached in Redis (`appmap:app`) and dropped on every
 *   change, so an edit reaches the next page load.
 */
final class AppMap
{
    /** Routes the access rules' own redirects lead to: a rule on them could loop or lock people out. */
    private const ALWAYS_OPEN = ['root', 'login', 'not-found'];
    private const CACHE_KEY = 'appmap:app';
    private const CACHE_SECONDS = 3600;

    /** @var db */
    private $db;
    /** @var RedisClient|null */
    private $redis;

    public function __construct(db $db, ?RedisClient $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /**
     * What the app needs: enabled routes and, per route name, its blocks'
     * prefixes (`_shell` for the frame) — for preloading text. Cached.
     */
    public function forApp(): array
    {
        if ($this->redis) {
            try {
                $cached = $this->redis->command('GET', self::CACHE_KEY);
                if (is_string($cached) && ($map = json_decode($cached, true)) !== null) {
                    return $map;
                }
            } catch (RedisException $e) {
                // Fall through to MySQL.
            }
        }

        $routes = [];
        $blocks = ['_shell' => []];
        foreach ($this->routes() as $route) {
            if ($route['kind'] === 'shell') {
                $blocks['_shell'] = array_column($route['blocks'], 'prefix');
                continue;
            }
            if (!$route['enabled']) {
                continue;
            }
            $routes[] = [
                'name' => $route['name'],
                'path' => $route['path'],
                'component' => $route['component'],
                'redirect' => $route['redirect'],
                'props' => $route['passParams'],
                'access' => $route['access'],
                'role' => $route['role'],
            ];
            if ($route['blocks']) {
                $blocks[$route['name']] = array_column($route['blocks'], 'prefix');
            }
        }
        $map = ['routes' => $routes, 'blocks' => $blocks];
        $map = ['version' => substr(sha1((string) json_encode($map)), 0, 12)] + $map;

        if ($this->redis) {
            try {
                $this->redis->command('SET', self::CACHE_KEY, (string) json_encode($map), 'EX', (string) self::CACHE_SECONDS);
            } catch (RedisException $e) {
                // Uncached is fine.
            }
        }

        return $map;
    }

    /** Every route with every detail and its blocks (`area`, `position`) — the CMS's view. */
    public function full(): array
    {
        return $this->routes();
    }

    /**
     * Creates (`id` absent) or updates a route. Returns it. Field errors are
     * thrown as `{ field, error }` JSON (the CMS pins them to the input).
     */
    public function saveRoute(array $input, string $staffEmail): array
    {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $existing = $id ? $this->route($id) : null;
        if ($id && !$existing) {
            throw new RuntimeException('No such route.');
        }
        if ($existing && $existing['kind'] === 'shell') {
            throw new RuntimeException('The frame around every page has no route settings — only blocks.');
        }

        $name = $existing ? $existing['name'] : trim((string) ($input['name'] ?? ''));
        if (!$existing) {
            if (!preg_match('/^[a-z][a-z0-9-]{0,59}$/', $name)) {
                throw self::fieldError('name', 'Lowercase letters, digits and dashes, starting with a letter.');
            }
            if ($this->routeByName($name)) {
                throw self::fieldError('name', 'Another route has that name.');
            }
        }

        $kind = (string) ($input['kind'] ?? ($existing['kind'] ?? 'page'));
        if (!in_array($kind, ['page', 'redirect'], true)) {
            throw self::fieldError('kind', 'A route is a page or a redirect.');
        }

        $path = trim((string) ($input['path'] ?? ($existing['path'] ?? '')));
        if ($existing && $existing['system'] && $path !== $existing['path']) {
            throw self::fieldError('path', 'The app’s code relies on this path; it can’t change.');
        }
        self::assertPath($path, 'path');
        $clash = $this->routeByPath($path);
        if ($clash && (int) $clash['id'] !== $id) {
            throw self::fieldError('path', "“{$clash['title']}” already has that path.");
        }

        $component = null;
        $redirect = null;
        if ($kind === 'page') {
            $component = trim((string) ($input['component'] ?? ''));
            if (!preg_match('/^[A-Z][A-Za-z0-9]{1,58}$/', $component)) {
                throw self::fieldError('component', 'The page component’s name, like DiscoverPage.');
            }
        } else {
            $redirect = trim((string) ($input['redirect'] ?? ''));
            self::assertPath($redirect, 'redirect');
            if ($redirect === $path) {
                throw self::fieldError('redirect', 'A route can’t redirect to itself.');
            }
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 80) {
            throw self::fieldError('title', 'A title of up to 80 characters.');
        }
        $access = (string) ($input['access'] ?? 'public');
        if (!in_array($access, ['public', 'signed_in', 'signed_out'], true)) {
            throw self::fieldError('access', 'Public, signed in or signed out.');
        }
        $role = $input['role'] ?? null;
        $role = $role === '' ? null : $role;
        if ($role !== null && !in_array($role, ['guest', 'guide'], true)) {
            throw self::fieldError('role', 'Traveller, guide, or either.');
        }
        if (in_array($name, self::ALWAYS_OPEN, true) && ($access !== 'public' || $role !== null)) {
            throw self::fieldError('access', 'Everyone must reach this page — a rule here could lock people out.');
        }
        $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
        if ($existing && $existing['system'] && !$enabled) {
            throw self::fieldError('enabled', 'The app can’t work without this route.');
        }

        $fields = [
            'kind' => $kind,
            'path' => $path,
            'component' => $component,
            'redirect' => $redirect,
            'title' => $title,
            'pass_params' => !empty($input['passParams']) ? 1 : 0,
            'access' => $access,
            'role' => $role,
            'enabled' => $enabled ? 1 : 0,
            'position' => (int) ($input['position'] ?? ($existing['position'] ?? $this->nextPosition())),
            'updated_by' => $staffEmail,
        ];
        $set = [];
        foreach ($fields as $column => $value) {
            $set[] = '`' . $column . '` = ' . $this->quote($value);
        }

        $this->db->db_Execute('START TRANSACTION');
        try {
            if ($existing) {
                $this->exec('UPDATE `app_routes` SET ' . implode(', ', $set) . ' WHERE `id` = ' . $id);
                // The old path keeps working: the app's links are paths in its code.
                if ($existing['enabled'] && $existing['path'] !== $path && !$this->routeByPath((string) $existing['path'])) {
                    $this->insertRedirect($name, (string) $existing['path'], $path, $staffEmail);
                }
            } else {
                $this->exec('INSERT INTO `app_routes` SET `name` = ' . $this->quote($name) . ', ' . implode(', ', $set));
                $id = (int) $this->db->db_GetLastId();
            }
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
        $this->forget();

        return $this->route($id);
    }

    /** Removes a route and its blocks. Returns what it was (for the audit log). */
    public function deleteRoute(int $id): array
    {
        $route = $this->route($id);
        if (!$route) {
            throw new RuntimeException('No such route.');
        }
        if ($route['system'] || $route['kind'] === 'shell') {
            throw new RuntimeException('The app’s code relies on this route; it can’t be removed.');
        }
        $this->exec('DELETE FROM `app_routes` WHERE `id` = ' . $id);
        $this->forget();

        return $route;
    }

    /** Every `page.widget` prefix that has English text. */
    public function textPrefixes(): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT DISTINCT SUBSTRING_INDEX(`key`, ".", 2) AS `prefix` FROM `translations` WHERE `language` = "en" ORDER BY `prefix`'
        );

        return array_values(array_map(static fn(array $row): string => (string) $row['prefix'], $rows));
    }

    /** Drops the app's cached copy. */
    public function forget(): void
    {
        if ($this->redis) {
            try {
                $this->redis->command('DEL', self::CACHE_KEY);
            } catch (RedisException $e) {
                Log::warning('App map cache not cleared', ['error' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------- internals

    private function routes(?string $where = null): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT * FROM `app_routes`' . ($where ? ' WHERE ' . $where : '') . ' ORDER BY `kind` = "shell" DESC, `position`, `id`'
        );
        if (!$rows) {
            return [];
        }
        $ids = implode(',', array_map(static fn(array $r): int => (int) $r['id'], $rows));
        $blocks = [];
        foreach ((array) $this->db->db_GetArray(
            'SELECT `route_id`, `prefix`, `area`, `position` FROM `app_route_blocks` WHERE `route_id` IN (' . $ids . ')
             ORDER BY FIELD(`area`, "header", "main", "shared", "footer", "always"), `position`, `prefix`'
        ) as $block) {
            $blocks[(int) $block['route_id']][] = ['prefix' => (string) $block['prefix'], 'area' => (string) $block['area']];
        }

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'kind' => (string) $r['kind'],
            'path' => $r['path'],
            'component' => $r['component'],
            'redirect' => $r['redirect'],
            'title' => (string) $r['title'],
            'passParams' => (bool) $r['pass_params'],
            'access' => (string) $r['access'],
            'role' => $r['role'],
            'enabled' => (bool) $r['enabled'],
            'system' => (bool) $r['system'],
            'position' => (int) $r['position'],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['updated_at'] . ' UTC')),
            'updatedBy' => (string) $r['updated_by'],
            'blocks' => $blocks[(int) $r['id']] ?? [],
        ], $rows);
    }

    private function route(int $id): ?array
    {
        return $this->routes('`id` = ' . $id)[0] ?? null;
    }

    private function routeByName(string $name): ?array
    {
        return $this->db->db_GetRow('SELECT * FROM `app_routes` WHERE `name` = ' . $this->quote($name)) ?: null;
    }

    private function routeByPath(string $path): ?array
    {
        return $this->db->db_GetRow('SELECT * FROM `app_routes` WHERE BINARY `path` = ' . $this->quote($path)) ?: null;
    }

    private function insertRedirect(string $name, string $from, string $to, string $staffEmail): void
    {
        $base = substr($name, 0, 50) . '-moved';
        $candidate = $base;
        for ($n = 2; $this->routeByName($candidate); $n++) {
            $candidate = $base . '-' . $n;
        }
        $this->exec(
            'INSERT INTO `app_routes` (`name`, `kind`, `path`, `redirect`, `title`, `position`, `updated_by`) VALUES ('
            . $this->quote($candidate) . ', "redirect", ' . $this->quote($from) . ', ' . $this->quote($to) . ', '
            . $this->quote(mb_substr('Old path of ' . $name, 0, 80)) . ', ' . $this->nextPosition() . ', ' . $this->quote($staffEmail) . ')'
        );
    }

    private function nextPosition(): int
    {
        return (int) ($this->db->db_GetRow('SELECT COALESCE(MAX(`position`), 0) + 10 AS `p` FROM `app_routes`')['p'] ?? 10);
    }

    private static function assertPath(string $path, string $field): void
    {
        if (!preg_match('#^/[A-Za-z0-9\-._~:/()*?+]*$#', $path) || strlen($path) > 160 || strpos($path, '//') !== false) {
            throw self::fieldError($field, 'A path starting with /, like /trips or /guides/:id.');
        }
    }

    private function exec(string $sql): void
    {
        if (!$this->db->db_Execute($sql)) {
            throw new RuntimeException('Could not save the page map.');
        }
    }

    /** @param mixed $value */
    private function quote($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return '"' . $this->db->db_Escape((string) $value) . '"';
    }

    private static function fieldError(string $field, string $error): RuntimeException
    {
        return new RuntimeException((string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
