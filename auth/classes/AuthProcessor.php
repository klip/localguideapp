<?php

/**
 * The auth service: accounts, passwords, two-factor login, password resets
 * and sessions. It owns `creds`, `auth_challenges`, `trusted_devices` and the
 * sessions in Redis; the API never sees a session hash — the gateway asks
 * this service who the caller is (`resolve`) and passes the API a user id.
 *
 * Client actions (routed here by the gateway, payload as the client sent it,
 * `sessionHash` included): register, login, verifyTwoFactor, resendTwoFactor,
 * requestPasswordReset, resetPassword, forgetTrustedDevices, changePassword,
 * logout, session.
 *
 * Gateway-only actions (the gateway never routes them for a client):
 *   resolve   { sessionHash }  → { userId: int|null }   slides the session
 *   peekMany  { hashes: [] }   → { live: { hash: userId } }   doesn't slide
 */
class AuthProcessor
{
    /** @var db */
    private $db;
    /** @var Accounts */
    private $accounts;
    /** @var Session */
    private $sessions;
    /** @var AccountSecurity */
    private $security;
    /** @var LegalDocuments */
    private $legal;
    /** @var array */
    private $input = [];
    /** @var array{userId: ?int, userAgent: string, requestId: string} */
    private $context = ['userId' => null, 'userAgent' => '', 'requestId' => ''];

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->accounts = new Accounts($db);
        $this->sessions = new Session($redis);
        $this->security = new AccountSecurity($db, $this->accounts, new MailQueue($db, $redis), $this->sessions);
        $this->legal = new LegalDocuments($db);
    }

    /**
     * Runs one request and returns `[httpStatus, body]` — see `ServiceHttp`.
     *
     * @return array{0: int, 1: array}
     */
    public function process(array $input, array $context): array
    {
        $this->input = $input;
        $this->context = $context + $this->context;

        try {
            switch ((string) ($input['action'] ?? '')) {
                case 'register':
                    return [200, $this->actionRegister()];
                case 'login':
                    return [200, $this->actionLogin()];
                case 'verifyTwoFactor':
                    return [200, $this->actionVerifyTwoFactor()];
                case 'resendTwoFactor':
                    return [200, $this->security->resendLoginCode((string) ($input['challengeToken'] ?? ''))];
                case 'requestPasswordReset':
                    return [200, $this->actionRequestPasswordReset()];
                case 'resetPassword':
                    $this->security->resetPassword((string) ($input['token'] ?? ''), (string) ($input['newPassword'] ?? ''));
                    return [200, ['ok' => true]];
                case 'forgetTrustedDevices':
                    $this->security->forgetTrustedDevices($this->requireUserId());
                    return [200, ['ok' => true]];
                case 'changePassword':
                    return [200, $this->actionChangePassword()];
                case 'logout':
                    $this->sessions->destroy($this->sessionHash());
                    return [200, ['ok' => true]];
                case 'session':
                    return [200, $this->actionSession()];

                case 'resolve':
                    return [200, ['userId' => $this->sessions->resolve($this->sessionHash())]];
                case 'peekMany':
                    $hashes = array_map('strval', array_filter((array) ($input['hashes'] ?? []), 'is_string'));
                    return [200, ['live' => (object) $this->sessions->peekMany($hashes)]];

                default:
                    return [404, ['error' => 'Unknown action.']];
            }
        } catch (AuthenticationException $e) {
            return [401, ['error' => $e->getMessage()]];
        } catch (ContactDetailsException $e) {
            return [400, $e->toBody()];
        } catch (RuntimeException $e) {
            return [400, ['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            return [500, ['error' => 'Unexpected server error.']];
        }
    }

    private function actionRegister(): array
    {
        $email = trim((string) ($this->input['email'] ?? ''));
        $password = (string) ($this->input['password'] ?? '');
        $role = (string) ($this->input['role'] ?? 'guest');
        $name = isset($this->input['name']) ? trim((string) $this->input['name']) : null;
        $image = isset($this->input['image']) ? ImageData::validate((string) $this->input['image']) : null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        // A name is shown on every deck card, so it can't carry a phone number.
        ContactFilter::assertClean($name, 'name');

        // The account exists only together with its acceptance of the terms
        // in force: `termsVersion` is the version the registration modal
        // showed, so a document published in between is refused (the client
        // fetches it again) rather than recorded as accepted unseen.
        $termsVersion = (string) ($this->input['termsVersion'] ?? '');
        $currentTerms = $this->legal->currentVersion(LegalDocuments::TERMS);
        if ($currentTerms === null || $termsVersion !== $currentTerms) {
            throw new RuntimeException('Please accept the current terms and conditions.');
        }
        $language = (string) ($this->input['language'] ?? 'en');
        $language = preg_match('/^[a-z]{2}$/', $language) ? $language : 'en';

        $this->db->db_Execute('START TRANSACTION');
        try {
            $userId = $this->accounts->register($email, $password, $role, $name, $image);
            $this->legal->recordAcceptance($userId, LegalDocuments::TERMS, $currentTerms, $language, (string) $this->context['userAgent']);
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
        Log::info('Account registered', ['user_id' => $userId, 'role' => $role, 'terms_version' => $currentTerms]);

        return ['id' => $userId, 'email' => $email, 'role' => $role, 'sessionHash' => $this->sessions->create($userId)];
    }

    private function actionLogin(): array
    {
        $email = trim((string) ($this->input['email'] ?? ''));
        $password = (string) ($this->input['password'] ?? '');

        $user = $this->accounts->verifyCredentials($email, $password);
        if (!$user) {
            Log::info('Login failed', ['to' => Log::maskEmail($email)]);
            throw new RuntimeException('Invalid email or password.');
        }

        // With two-factor on, the password only earns a challenge; the
        // session waits for `verifyTwoFactor` — unless this browser sent a
        // device token the account trusted within the last 30 days.
        $deviceToken = (string) ($this->input['deviceToken'] ?? '');
        if (
            AccountSecurity::requiresTwoFactor($user)
            && !$this->security->isTrustedDevice((int) $user['id'], $deviceToken)
        ) {
            return $this->security->startLogin($user);
        }

        return $this->signIn($user);
    }

    /** Creates a session for `$user` (a `users` row) and returns the `AuthResult` shape. */
    private function signIn(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $this->accounts->getRoleName((int) $user['id']),
            'sessionHash' => $this->sessions->create((int) $user['id']),
        ];
    }

    /** Second login step: the emailed code for a challenge from `login` — see `AccountSecurity::verifyLogin()`. */
    private function actionVerifyTwoFactor(): array
    {
        $userId = $this->security->verifyLogin(
            (string) ($this->input['challengeToken'] ?? ''),
            (string) ($this->input['code'] ?? '')
        );

        $user = $this->accounts->findById($userId);
        if (!$user) {
            throw new RuntimeException('This code has expired. Log in again to get a new one.');
        }

        $result = $this->signIn($user);
        // "Don't ask on this device for 30 days" — the token comes back once,
        // here, for the browser to keep. The user agent is the browser's,
        // which the gateway took from the socket handshake.
        if (!empty($this->input['rememberDevice'])) {
            $result += $this->security->trustDevice($userId, $this->context['userAgent']);
        }

        return $result;
    }

    /** Always `ok` — whether the email has an account is never revealed. */
    private function actionRequestPasswordReset(): array
    {
        $email = trim((string) ($this->input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }

        $this->security->requestPasswordReset($email);

        return ['ok' => true];
    }

    /** Verifies `currentPassword` before setting `newPassword`, then emails a notice. */
    private function actionChangePassword(): array
    {
        $userId = $this->requireUserId();
        $currentPassword = (string) ($this->input['currentPassword'] ?? '');
        $newPassword = (string) ($this->input['newPassword'] ?? '');

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        if (!$this->accounts->verifyPasswordFor($userId, $currentPassword)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $this->accounts->setPassword($userId, $newPassword);
        $user = $this->accounts->findById($userId);
        if ($user) {
            $this->security->notifyPasswordChanged($user);
        }

        return ['ok' => true];
    }

    /**
     * Validates the current session (sliding it) and returns the same
     * `AuthResult` shape `register`/`login` do, with the same, unrotated
     * hash — what `App.vue` calls on boot.
     */
    private function actionSession(): array
    {
        $userId = $this->requireUserId();
        $user = $this->accounts->findById($userId);
        if (!$user) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }

        return [
            'id' => $userId,
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $this->accounts->getRoleName($userId),
            'sessionHash' => $this->sessionHash(),
        ];
    }

    /** The caller's id from their `sessionHash` (sliding it); 401 without a live one. */
    private function requireUserId(): int
    {
        $userId = $this->sessions->resolve($this->sessionHash());
        if (!$userId) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }
        Log::context(['user_id' => $userId]);

        return $userId;
    }

    private function sessionHash(): string
    {
        return (string) ($this->input['sessionHash'] ?? '');
    }
}
