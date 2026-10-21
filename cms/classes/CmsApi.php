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
 * Phase 6 actions: me, staffList, staffInvite, staffUpdate, staffResendInvite,
 * auditLog. The content editor (phase 7), users (8) and moderation (9) add theirs.
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
            [$status, $body] = [400, ['error' => $e->getMessage()]];
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
