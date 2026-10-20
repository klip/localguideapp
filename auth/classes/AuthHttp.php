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
        $deviceId = $this->deviceId();

        try {
            switch ($action) {
                case 'refresh':
                    [$status, $body] = $this->refresh($deviceId, $userAgent);
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
                    [$status, $body] = [200, ['ok' => true]];
                    break;
                case 'logout':
                    $this->processor->tokens()->revokeByToken((string) ($_COOKIE[self::REFRESH_COOKIE] ?? ''), 'logout');
                    self::clearCookie(self::REFRESH_COOKIE);
                    [$status, $body] = [200, ['ok' => true]];
                    break;
                default:
                    [$status, $body] = $this->signIn($action, $input, $deviceId, $userAgent, $requestId);
            }
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            [$status, $body] = [500, ['error' => 'Unexpected server error.']];
        }

        Log::info('Request', ['status' => $status]);
        self::respond($status, $body);
    }

    /** register / login / verifyTwoFactor: the account action, then tokens instead of a session. */
    private function signIn(string $action, array $input, string $deviceId, string $userAgent, string $requestId): array
    {
        [$status, $body] = $this->processor->process(
            ['action' => $action] + $input,
            ['userId' => null, 'sid' => null, 'userAgent' => $userAgent, 'requestId' => $requestId, 'withSession' => false]
        );
        // A 2FA challenge, or an error: no sign-in yet.
        if ($status !== 200 || !isset($body['id'])) {
            return [$status, $body];
        }

        $tokens = $this->processor->tokens()->issue((int) $body['id'], $deviceId, $userAgent);
        $this->setRefreshCookie($tokens['refreshToken']);
        unset($tokens['refreshToken']);

        return [200, $body + $tokens];
    }

    private function refresh(string $deviceId, string $userAgent): array
    {
        try {
            $tokens = $this->processor->tokens()->rotate((string) ($_COOKIE[self::REFRESH_COOKIE] ?? ''), $deviceId, $userAgent);
        } catch (AuthenticationException $e) {
            self::clearCookie(self::REFRESH_COOKIE);
            return [401, ['error' => $e->getMessage()]];
        }
        Log::context(['user_id' => $tokens['userId']]);

        $user = $this->processor->accounts()->findById($tokens['userId']);
        if (!$user) {
            self::clearCookie(self::REFRESH_COOKIE);
            return [401, ['error' => 'Your session has expired. Please log in again.']];
        }
        $this->setRefreshCookie($tokens['refreshToken']);

        return [200, [
            'accessToken' => $tokens['accessToken'],
            'accessExpiresAt' => $tokens['accessExpiresAt'],
            'user' => $this->processor->userSummary($user),
        ]];
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
