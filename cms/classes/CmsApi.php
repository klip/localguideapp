<?php

/**
 * The CMS's API (docs/auth-cms-plan.md, phase 6): `POST /api` on the CMS's
 * own origin (admin.belikelocal.club; localhost:5174 in development, proxied
 * by the admin app's Vite to nginx :8084 → `cms/index.php`), body
 * `{ action, ...payload }`, answered with the JSON and HTTP status.
 *
 * Every request needs a staff access token (`Authorization: Bearer`, `aud:
 * cms`, verified with the public key and checked against the revocation list
 * — `StaffTokens::verify()`), an allowed origin and a JSON body. The member
 * is read fresh from the database each time, so a role change or a disabled
 * account takes effect at once. Each action names the capability it needs
 * (`Permissions`); every change is written to the audit log (`Audit`).
 *
 * Actions: me, staffList, staffInvite, staffUpdate, staffResendInvite,
 * auditLog (phase 6); contentLayout, contentBlock, contentSave,
 * contentAddKey, contentHistory, contentRevert (phase 7, `ContentEditor`).
 * Users (8) and moderation (9) add theirs.
 */
final class CmsApi
{
    /** action → capability it needs (null: any signed-in member). */
    private const ACTIONS = [
        'me' => null,
        'staffList' => 'staff.manage',
        'staffInvite' => 'staff.manage',
        'staffUpdate' => 'staff.manage',
        'staffResendInvite' => 'staff.manage',
        'auditLog' => null,
        'contentLayout' => 'content.edit',
        'contentBlock' => 'content.edit',
        'contentSave' => 'content.edit',
        'contentAddKey' => 'content.edit',
        'contentHistory' => 'content.edit',
        'contentRevert' => 'content.edit',
        'searchUsers' => 'users.view',
        'userDetail' => 'users.view',
        'userEdit' => 'users.edit',
        'userFreeze' => 'users.freeze',
        'userUnfreeze' => 'users.freeze',
        'bookingDecision' => 'refunds',
        'placeSearch' => 'users.view',
        'pageMap' => 'pages.manage',
        'routeSave' => 'pages.manage',
        'routeDelete' => 'pages.manage',
    ];

    /** @var db */
    private $db;
    /** @var RedisClient */
    private $redis;
    /** @var Staff */
    private $staff;
    /** @var StaffTokens */
    private $tokens;
    /** @var Audit */
    private $audit;
    /** @var ContentEditor */
    private $content;
    /** @var AppMap */
    private $appMap;
    /** @var UserAdmin */
    private $users;
    /** @var UserEditor */
    private $userEditor;
    /** @var UserFreezes */
    private $freezes;
    /** @var array the signed-in member */
    private $me = [];
    /** @var string */
    private $ip = '';

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->redis = $redis;
        $this->staff = new Staff($db, new MailQueue($db, $redis));
        $this->tokens = new StaffTokens($db, $redis);
        $this->audit = new Audit($db);
        $this->appMap = new AppMap($db, $redis);
        $this->users = new UserAdmin($db);
        $this->userEditor = new UserEditor($db, $redis);
        $this->freezes = new UserFreezes($db, $redis);
        $this->content = new ContentEditor($db, $this->appMap);
    }

    public function serve(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path === '/healthz') {
            self::respond(200, ['ok' => true]);
            return;
        }
        if ($path !== '/api' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::respond(404, ['error' => 'Not found.']);
            return;
        }
        if (!Origins::isAllowedCms((string) ($_SERVER['HTTP_ORIGIN'] ?? ''))) {
            self::respond(403, ['error' => 'Forbidden.']);
            return;
        }
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            self::respond(415, ['error' => 'Expected JSON.']);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
        $action = (string) ($input['action'] ?? '');
        Log::context(['request_id' => bin2hex(random_bytes(8)), 'action' => 'cms.' . $action]);
        $this->ip = ClientIp::resolve((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

        try {
            [$status, $body] = $this->handle($action, $input);
        } catch (RuntimeException $e) {
            // `ContentEditor` pins a message to one field as `{field, error}`.
            $pinned = json_decode($e->getMessage(), true);
            [$status, $body] = [400, is_array($pinned) && isset($pinned['error'], $pinned['field'])
                ? ['error' => (string) $pinned['error'], 'field' => (string) $pinned['field']]
                : ['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            [$status, $body] = [500, ['error' => 'Unexpected server error.']];
        }

        Log::info('Request', ['status' => $status]);
        self::respond($status, $body);
    }

    private function handle(string $action, array $input): array
    {
        if (!array_key_exists($action, self::ACTIONS)) {
            return [404, ['error' => 'Unknown action.']];
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $claims = strncmp($authorization, 'Bearer ', 7) === 0 ? StaffTokens::verify(substr($authorization, 7), $this->redis) : null;
        $me = $claims ? $this->staff->find((int) $claims['staffId']) : null;
        if (!$me || $me['disabled_at'] !== null) {
            return [401, ['error' => 'Your CMS session has ended. Please sign in again.']];
        }
        $this->me = $me;
        Log::context(['staff_id' => (int) $me['id']]);

        if (!Permissions::allows((string) $me['role'], self::ACTIONS[$action])) {
            return [403, ['error' => 'Your role can’t do that.']];
        }

        switch ($action) {
            case 'me':
                return [200, $this->staff->present($me) + ['capabilities' => Permissions::capabilitiesOf((string) $me['role'])]];
            case 'staffList':
                return [200, ['staff' => $this->staff->all()]];
            case 'staffInvite':
                return [200, $this->staffInvite($input)];
            case 'staffUpdate':
                return [200, $this->staffUpdate($input)];
            case 'staffResendInvite':
                return [200, $this->staffResendInvite($input)];
            case 'auditLog':
                return [200, $this->auditLog($input)];
            case 'contentLayout':
                return [200, $this->content->layout()];
            case 'contentBlock':
                return [200, $this->content->block((string) ($input['prefix'] ?? ''), (string) ($input['language'] ?? 'en'))];
            case 'contentSave':
                return [200, $this->contentSave($input)];
            case 'contentAddKey':
                return [200, $this->contentAddKey($input)];
            case 'contentHistory':
                return [200, ['history' => $this->content->history((string) ($input['prefix'] ?? ''), isset($input['key']) ? (string) $input['key'] : null)]];
            case 'contentRevert':
                return [200, $this->contentRevert($input)];
            case 'placeSearch':
                return [200, ['places' => (new Geocoder($this->redis))->search((string) ($input['query'] ?? ''), 'en', null)]];
            case 'userFreeze':
            case 'userUnfreeze':
            case 'bookingDecision':
                return [200, $this->userFreeze($action, $input)];
            case 'userEdit':
                return [200, $this->userEdit($input)];
            case 'userDetail':
                return [200, $this->users->detail((int) ($input['id'] ?? 0), Permissions::allows((string) $this->me['role'], 'users.security'))];
            case 'searchUsers':
                return [200, $this->users->search($input)];
            case 'pageMap':
                return [200, $this->pageMap()];
            case 'routeSave':
                return [200, $this->routeSave($input)];
            case 'routeDelete':
                return [200, $this->routeDelete($input)];
        }

        return [404, ['error' => 'Unknown action.']];
    }

    private function staffInvite(array $input): array
    {
        $role = (string) ($input['role'] ?? '');
        $id = $this->staff->create((string) ($input['email'] ?? ''), (string) ($input['name'] ?? ''), $role, (int) $this->me['id']);
        $this->staff->createSetupLink($id, Staff::INVITE_TTL_SECONDS, 'invite');
        $created = $this->staff->present($this->staff->find($id));
        $this->audit->record($this->me, 'staff.invite', 'staff', (string) $id, null, $created, $this->ip);

        return ['staff' => $created];
    }

    private function staffUpdate(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $target = $this->staff->find($id);
        if (!$target) {
            throw new RuntimeException('No such staff member.');
        }
        $before = $this->staff->present($target);
        $role = isset($input['role']) ? (string) $input['role'] : null;
        $disabled = isset($input['disabled']) ? (bool) $input['disabled'] : null;

        if ($id === (int) $this->me['id'] && (($role !== null && $role !== $target['role']) || $disabled)) {
            throw new RuntimeException('You can’t change your own role or disable yourself — ask another admin.');
        }
        $losesAdmin = $target['role'] === 'admin' && $target['disabled_at'] === null
            && (($role !== null && $role !== 'admin') || $disabled);
        if ($losesAdmin && $this->staff->countActiveAdmins() <= 1) {
            throw new RuntimeException('There must always be at least one active admin.');
        }

        if ($role !== null && $role !== $target['role']) {
            $this->staff->setRole($id, $role);
        }
        if ($disabled !== null && $disabled !== ($target['disabled_at'] !== null)) {
            $this->staff->setDisabled($id, $disabled);
        }
        $after = $this->staff->present($this->staff->find($id));
        if ($after === $before) {
            return ['staff' => $after];
        }
        // New rights (or none) from now: end their sign-ins at once.
        $this->tokens->revokeAllFor($id, $disabled ? 'disabled' : 'role_changed');
        $this->audit->record($this->me, $disabled ? 'staff.disable' : ($disabled === false ? 'staff.enable' : 'staff.role'), 'staff', (string) $id, $before, $after, $this->ip);

        return ['staff' => $after];
    }

    private function staffResendInvite(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $target = $this->staff->find($id);
        if (!$target || $target['disabled_at'] !== null) {
            throw new RuntimeException('No such active staff member.');
        }
        $this->staff->createSetupLink($id, Staff::INVITE_TTL_SECONDS, 'invite');
        $this->audit->record($this->me, 'staff.invite_resent', 'staff', (string) $id, null, null, $this->ip);

        return ['ok' => true];
    }

    private function contentSave(array $input): array
    {
        $prefix = (string) ($input['prefix'] ?? '');
        $language = (string) ($input['language'] ?? '');
        $changes = $this->content->save($prefix, $language, (array) ($input['values'] ?? []), $this->me);
        if ($changes) {
            $this->audit->record($this->me, 'content.save', 'translations', $prefix . ' · ' . $language,
                array_map(static fn(array $c) => $c[0], $changes), array_map(static fn(array $c) => $c[1], $changes), $this->ip);
        }

        return ['changed' => count($changes)] + $this->content->block($prefix, $language);
    }

    private function contentAddKey(array $input): array
    {
        $prefix = (string) ($input['prefix'] ?? '');
        $key = $this->content->addKey($prefix, (string) ($input['name'] ?? ''), (string) ($input['english'] ?? ''), $this->me);
        $this->audit->record($this->me, 'content.add_key', 'translations', $key, null, ['en' => (string) ($input['english'] ?? '')], $this->ip);

        return ['key' => $key] + $this->content->block($prefix, (string) ($input['language'] ?? 'en'));
    }

    private function contentRevert(array $input): array
    {
        [$language, $key, $old, $new] = $this->content->revert((int) ($input['historyId'] ?? 0), $this->me);
        $this->audit->record($this->me, 'content.revert', 'translations', $key . ' · ' . $language, [$key => $old], [$key => $new], $this->ip);

        return ['language' => $language, 'key' => $key, 'value' => $new];
    }

    /**
     * The Pages screen: every route (not the frame around every page, which
     * has no address) without its text blocks — those are the code's — and
     * the page components in use.
     */
    private function pageMap(): array
    {
        $routes = array_values(array_filter($this->appMap->full(), static fn(array $r): bool => $r['kind'] !== 'shell'));
        $components = array_values(array_unique(array_filter(array_column($routes, 'component'))));
        sort($components);
        $routes = array_map(static fn(array $r): array => array_diff_key($r, ['blocks' => true]), $routes);

        return ['routes' => $routes, 'components' => $components];
    }

    private function routeSave(array $input): array
    {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $before = null;
        foreach ($id ? $this->appMap->full() : [] as $route) {
            if ($route['id'] === $id) {
                $before = self::routeAuditView($route);
            }
        }
        $route = $this->appMap->saveRoute($input, (string) $this->me['email']);
        $this->audit->record($this->me, $id ? 'pages.route_update' : 'pages.route_create', 'route', $route['name'],
            $before, self::routeAuditView($route), $this->ip);

        return ['route' => $route] + $this->pageMap();
    }

    private function routeDelete(array $input): array
    {
        $route = $this->appMap->deleteRoute((int) ($input['id'] ?? 0));
        $this->audit->record($this->me, 'pages.route_delete', 'route', $route['name'], self::routeAuditView($route), null, $this->ip);

        return $this->pageMap();
    }

    /**
     * One change to an app user (`op`, see `UserEditor`), audited as
     * `users.<op>`; answers with the user page's fresh data.
     */
    private function userEdit(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $op = (string) ($input['op'] ?? '');
        $e = $this->userEditor;
        $me = $this->me;
        $ops = [
            'account' => fn() => $e->updateAccount($id, (array) ($input['account'] ?? [])),
            'roleText' => fn() => $e->setRoleText($id, (string) ($input['role'] ?? ''), $input),
            'profile' => fn() => $e->updateProfile($id, (array) ($input['profile'] ?? [])),
            'addRole' => fn() => $e->addRole($id, (string) ($input['role'] ?? '')),
            'removeRole' => fn() => $e->removeRole($id, (string) ($input['role'] ?? '')),
            'verification' => fn() => $e->setVerification($id, isset($input['method']) && $input['method'] !== '' ? (string) $input['method'] : null),
            'addSanction' => fn() => $e->addSanction($id, $input, $me),
            'liftSanction' => fn() => $e->liftSanction($id, (int) ($input['sanctionId'] ?? 0), $me),
            'addAdjustment' => fn() => $e->addAdjustment($id, $input, $me),
            'liftAdjustment' => fn() => $e->liftAdjustment($id, (int) ($input['adjustmentId'] ?? 0), $me),
            'addBadge' => fn() => $e->addBadge($id, $input, $me),
            'revokeBadge' => fn() => $e->revokeBadge($id, (int) ($input['badgeId'] ?? 0), $me),
            'addBonus' => fn() => $e->addBonus($id, $input, $me),
            'extendBonus' => fn() => $e->extendBonus($id, (int) ($input['bonusId'] ?? 0), (int) ($input['days'] ?? 0)),
            'revokeBonus' => fn() => $e->revokeBonus($id, (int) ($input['bonusId'] ?? 0)),
        ];
        if (!isset($ops[$op])) {
            throw new RuntimeException('Unknown change.');
        }
        [$before, $after] = $ops[$op]();
        $this->audit->record($this->me, 'users.' . $op, 'user', (string) $id, $before, $after, $this->ip);

        return $this->users->detail($id, Permissions::allows((string) $this->me['role'], 'users.security'));
    }

    /** Freezing, lifting a freeze, and deciding a booking left open (`UserFreezes`), audited. */
    private function userFreeze(string $action, array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        switch ($action) {
            case 'userFreeze':
                [$before, $after] = $this->freezes->freeze($id, $input, $this->me);
                $audit = 'users.freeze';
                break;
            case 'userUnfreeze':
                [$before, $after] = $this->freezes->lift($id, (int) ($input['freezeId'] ?? 0), (string) ($input['reason'] ?? ''), $this->me);
                $audit = 'users.unfreeze';
                break;
            default:
                [$before, $after] = $this->freezes->decideBooking($id, (int) ($input['bookingId'] ?? 0), $input['refund'] ?? null, (string) ($input['note'] ?? ''));
                $audit = 'users.booking_cancel';
        }
        $this->audit->record($this->me, $audit, 'user', (string) $id, $before, $after, $this->ip);

        return ['result' => $after] + $this->users->detail($id, Permissions::allows((string) $this->me['role'], 'users.security'));
    }

    /** A route's settings without its blocks or timestamps, for the audit log. */
    private static function routeAuditView(array $route): array
    {
        return array_diff_key($route, array_flip(['blocks', 'updatedAt', 'updatedBy', 'id']));
    }

    private function auditLog(array $input): array
    {
        $role = (string) $this->me['role'];
        $scope = Permissions::allows($role, 'audit.all') ? 'all'
            : (Permissions::allows($role, 'audit.operations') ? 'operations' : 'own');
        $beforeId = isset($input['beforeId']) ? (int) $input['beforeId'] : null;

        // `filter`, not `action` — that's the request's own action name.
        return $this->audit->page($scope, (int) $this->me['id'], $beforeId, isset($input['filter']) ? (string) $input['filter'] : null)
            + ['scope' => $scope];
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body);
    }
}
