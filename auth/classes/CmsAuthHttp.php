<?php

/**
 * Staff sign-in for the CMS (docs/auth-cms-plan.md, phase 6): `POST
 * /auth/<action>` on the **CMS's own origin** (admin.belikelocal.club;
 * localhost:5174 in development, proxied by the admin app's Vite to
 * `auth/cms.php`). The app's `AuthHttp` for staff, with its own cookie
 * (`__Host-rg_cms_refresh`, so app and staff sign-ins never touch), its own
 * accounts (`Staff`) and tokens (`StaffTokens`, `aud: cms`).
 *
 *   login            email + password → always a code challenge (mandatory 2FA)
 *   verifyCode       challengeToken + code → access token + staff; sets the cookie
 *   refresh          → a new access token; rotates the cookie; 401 when over
 *   logout           ends this sign-in
 *   setPassword      a set-password link's token + password (invite, reset)
 *   requestReset     emails a set-password link (always "ok")
 *
 * Same CSRF rules as `AuthHttp`: an allowed origin (`Origins::isAllowedCms()`)
 * and a JSON body; the cookie is HttpOnly, Secure, SameSite=Strict, __Host-.
 */
final class CmsAuthHttp
{
    private const ACTIONS = ['login', 'verifyCode', 'refresh', 'logout', 'setPassword', 'requestReset'];
    private const COOKIE = '__Host-rg_cms_refresh';

    /** @var Staff */
    private $staff;
    /** @var StaffTokens */
    private $tokens;

    public function __construct(Staff $staff, StaffTokens $tokens)
    {
        $this->staff = $staff;
        $this->tokens = $tokens;
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
        $ip = ClientIp::resolve((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        Log::context(['request_id' => bin2hex(random_bytes(8)), 'action' => 'cms.' . $action]);

        try {
            [$status, $body] = $this->dispatch($action, $input, $ip, $userAgent);
        } catch (AuthenticationException $e) {
            self::clearCookie();
            [$status, $body] = [401, ['error' => $e->getMessage()]];
        } catch (RuntimeException $e) {
            [$status, $body] = [400, ['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            [$status, $body] = [500, ['error' => 'Unexpected server error.']];
        }

        Log::info('Request', ['status' => $status]);
        self::respond($status, $body);
    }

    private function dispatch(string $action, array $input, string $ip, string $userAgent): array
    {
        switch ($action) {
            case 'login':
                $staff = $this->staff->verifyPassword((string) ($input['email'] ?? ''), (string) ($input['password'] ?? ''));
                if (!$staff) {
                    Log::info('Staff sign-in failed', ['to' => Log::maskEmail((string) ($input['email'] ?? ''))]);
                    throw new RuntimeException('Invalid email or password.');
                }
                return [200, $this->staff->startSignIn($staff)];

            case 'verifyCode':
                $staffId = $this->staff->verifyCode((string) ($input['challengeToken'] ?? ''), (string) ($input['code'] ?? ''));
                $staff = $this->staff->find($staffId);
                if (!$staff || $staff['disabled_at'] !== null) {
                    throw new RuntimeException('This account can’t sign in.');
                }
                $issued = $this->tokens->issue($staffId, $ip, $userAgent);
                self::setCookie($issued['refreshToken'], time() + StaffTokens::SIGN_IN_TTL_SECONDS);
                Log::info('Staff signed in', ['staff_id' => $staffId]);
                return [200, $this->signedIn($staff, $issued['sid'])];

            case 'refresh':
                $rotated = $this->tokens->rotate((string) ($_COOKIE[self::COOKIE] ?? ''), $ip, $userAgent);
                $staff = $this->staff->find($rotated['staffId']);
                if (!$staff || $staff['disabled_at'] !== null) {
                    $this->tokens->revokeAllFor($rotated['staffId'], 'disabled');
                    throw new AuthenticationException('Your CMS session has ended. Please sign in again.');
                }
                // The cookie keeps the sign-in's original end (12 hours from signing in).
                self::setCookie($rotated['refreshToken'], time() + StaffTokens::SIGN_IN_TTL_SECONDS);
                return [200, $this->signedIn($staff, $rotated['sid'])];

            case 'logout':
                $this->tokens->revokeByToken((string) ($_COOKIE[self::COOKIE] ?? ''), 'logout');
                self::clearCookie();
                return [200, ['ok' => true]];

            case 'setPassword':
                $staffId = $this->staff->setPasswordWithLink((string) ($input['token'] ?? ''), (string) ($input['password'] ?? ''));
                // A new password ends every existing sign-in.
                $this->tokens->revokeAllFor($staffId, 'password_set');
                Log::info('Staff password set', ['staff_id' => $staffId]);
                return [200, ['ok' => true]];

            case 'requestReset':
                $staff = $this->staff->findByEmail((string) ($input['email'] ?? ''));
                if ($staff && $staff['disabled_at'] === null) {
                    $this->staff->createSetupLink((int) $staff['id'], Staff::RESET_TTL_SECONDS, 'reset');
                }
                return [200, ['ok' => true]];
        }

        return [404, ['error' => 'Unknown action.']];
    }

    private function signedIn(array $staff, string $sid): array
    {
        return $this->tokens->access((int) $staff['id'], $sid, (string) $staff['role']) + [
            'staff' => $this->staff->present($staff),
        ];
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function clearCookie(): void
    {
        self::setCookie('', 1);
    }

    private static function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body);
    }
}
