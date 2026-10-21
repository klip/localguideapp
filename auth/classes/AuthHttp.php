<?php

/**
 * The auth service's one browser-facing entry point (`auth/public.php`):
 * `POST /auth/<action>` on the **app's own origin** — nginx routes `/auth/` here
 * in production; Vite's dev server proxies it (`client/vite.config.ts`). The
 * actions that start, renew or end a sign-in live here because only an HTTP
 * response can set the HttpOnly cookies they use; everything else stays on
 * the WebSocket. See docs/auth-cms-plan.md.
 *
 *   register, login, verifyTwoFactor  → the usual AuthResult (or a 2FA
 *       challenge) + `accessToken`, `accessExpiresAt`; sets the refresh cookie
 *   refresh  → `{ accessToken, accessExpiresAt, user }`, rotating the refresh
 *       cookie; 401 (and the cookie cleared) when the sign-in is over
 *   logout   → ends this sign-in, clears the refresh cookie
 *   (a login `Risk` finds unusual needs an emailed code first, and the
 *   owner is emailed about a new sign-in — `signIn()`)
 *   logoutEverywhere → ends every sign-in of the account (other devices,
 *       their access tokens too — `Revocations`), clears the cookie; 401
 *       without a live refresh cookie
 *
 * Cookies, both `HttpOnly; Secure; SameSite=Strict; Path=/` with the
 * `__Host-` prefix (no Domain — only this exact host can set or read them):
 *
 *   __Host-rg_refresh  the refresh token, for its 30 days
 *   __Host-rg_device   a random id for this browser (400 days, renewed on
 *                      use); its SHA-256 is stored with each sign-in
 *
 * CSRF: SameSite=Strict keeps other sites from sending the cookies at all;
 * on top of that every request must come with an allowed `Origin` and
 * `Content-Type: application/json` (which a cross-site form can't send
 * without a CORS preflight, and nothing here answers one).
 */
final class AuthHttp
{
    private const ACTIONS = ['register', 'login', 'verifyTwoFactor', 'refresh', 'logout', 'logoutEverywhere'];
    private const REFRESH_COOKIE = '__Host-rg_refresh';
    private const DEVICE_COOKIE = '__Host-rg_device';
    private const DEVICE_TTL_SECONDS = 400 * 86400;

    /** @var AuthProcessor */
    private $processor;

    public function __construct(AuthProcessor $processor)
    {
        $this->processor = $processor;
    }

    public function serve(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $action = preg_match('#^/auth/([A-Za-z]+)$#', $path, $m) ? $m[1] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            self::respond(404, ['error' => 'Unknown action.']);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::respond(405, ['error' => 'POST only.']);
            return;
        }
        if (!Origins::isAllowed((string) ($_SERVER['HTTP_ORIGIN'] ?? ''))) {
            self::respond(403, ['error' => 'Forbidden.']);
            return;
        }
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            self::respond(415, ['error' => 'Expected JSON.']);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        $requestId = bin2hex(random_bytes(8));
        $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        Log::context(['request_id' => $requestId, 'action' => $action]);
        $client = [
            'deviceHash' => $this->deviceId(),
            // What the browser says about itself (`device`, utils/deviceInfo.ts), cleaned up.
            'info' => Devices::normalize($input['device'] ?? null, $userAgent),
            'ip' => ClientIp::resolve((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            'userAgent' => $userAgent,
            'requestId' => $requestId,
        ];
        unset($input['device']);

        try {
            switch ($action) {
                case 'refresh':
                    [$status, $body] = $this->refresh($client);
                    break;
                case 'logoutEverywhere':
                    $userId = $this->processor->tokens()->userForToken((string) ($_COOKIE[self::REFRESH_COOKIE] ?? ''));
                    self::clearCookie(self::REFRESH_COOKIE);
                    if ($userId === null) {
                        [$status, $body] = [401, ['error' => 'Your session has expired. Please log in again.']];
                        break;
                    }
                    Log::context(['user_id' => $userId]);
                    $this->processor->signOutEverywhere($userId, 'logout_everywhere');
                    $this->record('logout_everywhere', $userId, $client);
                    [$status, $body] = [200, ['ok' => true]];
                    break;
                case 'logout':
                    $cookie = (string) ($_COOKIE[self::REFRESH_COOKIE] ?? '');
                    $userId = $this->processor->tokens()->userForToken($cookie);
                    $this->processor->tokens()->revokeByToken($cookie, 'logout');
                    self::clearCookie(self::REFRESH_COOKIE);
                    if ($userId !== null) {
                        $this->record('logout', $userId, $client);
                    }
                    [$status, $body] = [200, ['ok' => true]];
                    break;
                default:
                    [$status, $body] = $this->signIn($action, $input, $client);
            }
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            [$status, $body] = [500, ['error' => 'Unexpected server error.']];
        }

        Log::info('Request', ['status' => $status]);
        self::respond($status, $body);
    }

    /**
     * register / login / verifyTwoFactor: the account action, then tokens
     * instead of a session — with `Risk` deciding whether a login needs an
     * emailed code first, and whether the owner gets a "new sign-in" email.
     */
    private function signIn(string $action, array $input, array $client): array
    {
        $devices = $this->processor->devices();
        $described = $devices->describe($client['info'], $client['ip']);

        // Scored before the password is checked, but it only matters once the
        // password was right (`AuthProcessor::actionLogin()`).
        $risk = null;
        $stepUp = null;
        $email = trim((string) ($input['email'] ?? ''));
        $known = $action === 'login' && $email !== '' ? $this->processor->accounts()->findByEmail($email) : null;
        if ($known) {
            $risk = $this->processor->risk()->assess((int) $known['id'], $client['deviceHash'], $described);
            $stepUp = $risk['score'] >= Risk::STEP_UP ? 'unfamiliar_device' : null;
        }

        [$status, $body] = $this->processor->process(
            ['action' => $action] + $input,
            ['userId' => null, 'sid' => null, 'did' => null, 'userAgent' => $client['userAgent'],
             'clientIp' => $client['ip'], 'requestId' => $client['requestId'], 'signIn' => true, 'stepUp' => $stepUp, 'deviceHash' => $client['deviceHash']]
        );

        if ($action === 'login' && !isset($body['id'])) {
            // A wrong password (on any address — its keyed hash is kept), or
            // the password right and a code on its way.
            $event = $status === 200 ? 'two_factor_required' : 'login_failed';
            $devices->record($event, $known ? (int) $known['id'] : null, $described,
                $client['deviceHash'], $client['ip'], $client['userAgent'], $email, $event === 'two_factor_required' ? $risk : null);
        }
        // A 2FA challenge, or an error: no sign-in yet.
        if ($status !== 200 || !isset($body['id'])) {
            return [$status, $body];
        }

        $userId = (int) $body['id'];
        // After a code, score it now — before this browser becomes "known".
        if ($action === 'verifyTwoFactor') {
            $risk = $this->processor->risk()->assess($userId, $client['deviceHash'], $described);
        }

        $device = $devices->touch($userId, $client['deviceHash'], $client['info'], $client['ip']);
        $tokens = $this->processor->tokens()->issue($userId, $client['deviceHash'], $client['userAgent']);
        $this->setRefreshCookie($tokens['refreshToken']);
        $devices->record(
            ['register' => 'register', 'login' => 'login', 'verifyTwoFactor' => 'two_factor_ok'][$action],
            $userId, $device, $client['deviceHash'], $client['ip'], $client['userAgent'], null, $risk
        );
        $this->processor->risk()->linkAccounts($userId, $client['deviceHash'], $device);

        if ($risk !== null && $risk['score'] >= Risk::NOTIFY) {
            $user = $this->processor->accounts()->findById($userId);
            if ($user) {
                $this->processor->security()->notifyNewSignIn($user, Devices::label($client['info']), $device['place']);
            }
        }

        return [200, $body + $this->processor->tokens()->access($userId, $tokens['sid'], $device['id'])];
    }

    private function refresh(array $client): array
    {
        $tokens = $this->processor->tokens();
        try {
            $rotated = $tokens->rotate((string) ($_COOKIE[self::REFRESH_COOKIE] ?? ''), $client['deviceHash'], $client['userAgent']);
        } catch (AuthenticationException $e) {
            self::clearCookie(self::REFRESH_COOKIE);
            if ($tokens->lastFailure === 'reuse') {
                $this->record('reuse_detected', (int) $tokens->lastFailureUserId, $client);
                $user = $this->processor->accounts()->findById((int) $tokens->lastFailureUserId);
                if ($user) {
                    $this->processor->security()->notifyTokenReuse($user);
                }
            }
            return [401, ['error' => $e->getMessage()]];
        }
        Log::context(['user_id' => $rotated['userId']]);

        $user = $this->processor->accounts()->findById($rotated['userId']);
        if (!$user) {
            self::clearCookie(self::REFRESH_COOKIE);
            return [401, ['error' => 'Your session has expired. Please log in again.']];
        }
        $this->setRefreshCookie($rotated['refreshToken']);

        // Every refresh updates the device; only one from a new address or a
        // changed browser makes the history (it'd be one row per quarter hour).
        $device = $this->processor->devices()->touch($rotated['userId'], $client['deviceHash'], $client['info'], $client['ip']);
        if ($device['changed']) {
            $this->processor->devices()->record('refresh', $rotated['userId'], $device,
                $client['deviceHash'], $client['ip'], $client['userAgent']);
        }

        return [200, $tokens->access($rotated['userId'], $rotated['sid'], $device['id']) + [
            'user' => $this->processor->userSummary($user),
        ]];
    }

    /**
     * A history row for an account action that isn't using the device (a
     * logout, a reused token) — so the device row itself is left as it was.
     */
    private function record(string $event, int $userId, array $client): void
    {
        $devices = $this->processor->devices();
        $device = $devices->describe($client['info'], $client['ip'])
            + ['id' => $devices->idFor($userId, $client['deviceHash'])];
        $devices->record($event, $userId, $device, $client['deviceHash'], $client['ip'], $client['userAgent']);
    }

    /** SHA-256 of this browser's device cookie, setting (or renewing) the cookie. */
    private function deviceId(): string
    {
        $value = (string) ($_COOKIE[self::DEVICE_COOKIE] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $value)) {
            $value = Jwt::base64url(random_bytes(32));
        }
        self::setCookie(self::DEVICE_COOKIE, $value, time() + self::DEVICE_TTL_SECONDS);

        return hash('sha256', $value);
    }

    private function setRefreshCookie(string $token): void
    {
        self::setCookie(self::REFRESH_COOKIE, $token, time() + Tokens::REFRESH_TTL_SECONDS);
    }

    private static function clearCookie(string $name): void
    {
        self::setCookie($name, '', 1);
    }

    private static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body);
    }
}
