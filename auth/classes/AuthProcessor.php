<?php

/**
 * The auth service: accounts, passwords, two-factor login, password resets,
 * sign-ins (`Tokens`), devices and risk. It owns `creds`, `auth_challenges`,
 * `trusted_devices`, `refresh_tokens`, `devices`, `login_events` and
 * `account_links`.
 *
 * Signing in — register, login, verifyTwoFactor — happens only through
 * `AuthHttp` (`POST /auth/*`, which sets the refresh cookie and issues the
 * tokens); it passes `signIn: true`, and without it those actions are
 * refused. Everything else arrives from the gateway over internal HTTP, the
 * caller (whose access token the gateway verified) as `context.userId`, with
 * the token's `sid` and `did`: resendTwoFactor, requestPasswordReset,
 * resetPassword, forgetTrustedDevices, changePassword, addRole,
 * secureAccount, myDevices, signOutDevice.
 */
class AuthProcessor
{
    /** @var db */
    private $db;
    /** @var Accounts */
    private $accounts;
    /** @var AccountSecurity */
    private $security;
    /** @var LegalDocuments */
    private $legal;
    /** @var Tokens */
    private $tokens;
    /** @var Devices */
    private $devices;
    /** @var Risk */
    private $risk;
    /** @var array */
    private $input = [];
    /**
     * `signIn: true` — called by `AuthHttp`, the only way to sign in.
     * `stepUp` (from `AuthHttp`, when `Risk` found the attempt unusual) makes
     * `login` ask for an emailed code whatever the account's settings.
     *
     * @var array{userId: ?int, sid: ?string, did: ?int, userAgent: string, clientIp: string, requestId: string, signIn: bool, stepUp: ?string}
     */
    private $context = ['userId' => null, 'sid' => null, 'did' => null, 'userAgent' => '', 'clientIp' => '', 'requestId' => '', 'signIn' => false, 'stepUp' => null];

    /** Only `AuthHttp` may run these: they need the refresh cookie it sets. */
    private const SIGN_IN_ACTIONS = ['register', 'login', 'verifyTwoFactor'];

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->accounts = new Accounts($db);
        $this->security = new AccountSecurity($db, $this->accounts, new MailQueue($db, $redis));
        $this->legal = new LegalDocuments($db);
        $this->tokens = new Tokens($db, $this->accounts, $redis);
        $this->devices = new Devices($db, new GeoIp($redis));
        $this->risk = new Risk($db);
    }

    public function risk(): Risk
    {
        return $this->risk;
    }

    public function security(): AccountSecurity
    {
        return $this->security;
    }

    public function devices(): Devices
    {
        return $this->devices;
    }

    public function tokens(): Tokens
    {
        return $this->tokens;
    }

    public function accounts(): Accounts
    {
        return $this->accounts;
    }

    /** Ends every sign-in of the account: its refresh tokens and the access tokens already out. */
    public function signOutEverywhere(int $userId, string $reason): void
    {
        $this->tokens->revokeAllFor($userId, $reason);
        Log::info('Signed out everywhere', ['user_id' => $userId, 'reason' => $reason]);
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
        $action = (string) ($input['action'] ?? '');
        if (in_array($action, self::SIGN_IN_ACTIONS, true) && !$this->context['signIn']) {
            return [404, ['error' => 'Unknown action.']];
        }

        try {
            switch ($action) {
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
                    $userId = $this->security->resetPassword((string) ($input['token'] ?? ''), (string) ($input['newPassword'] ?? ''));
                    // Signed out everywhere, the access tokens already out included.
                    $this->tokens->revokeAllFor($userId, 'password_reset');
                    return [200, ['ok' => true]];
                case 'forgetTrustedDevices':
                    $this->security->forgetTrustedDevices($this->requireUserId());
                    return [200, ['ok' => true]];
                case 'changePassword':
                    return [200, $this->actionChangePassword()];
                case 'addRole':
                    return [200, $this->actionAddRole()];
                case 'secureAccount':
                    return [200, $this->actionSecureAccount()];
                case 'myDevices':
                    return [200, $this->actionMyDevices()];
                case 'signOutDevice':
                    return [200, $this->actionSignOutDevice()];

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
        // …and of the starting role's agreement (guide or traveller), same rule.
        $agreement = $this->legal->assertRoleAgreement(
            $role,
            (string) ($this->input['agreementSlug'] ?? ''),
            (string) ($this->input['agreementVersion'] ?? '')
        );
        $language = (string) ($this->input['language'] ?? 'en');
        $language = preg_match('/^[a-z]{2}$/', $language) ? $language : 'en';

        // A browser a frozen account has used can't open a new one — the
        // obvious way round a suspension (docs/auth-cms-plan.md, §2).
        $deviceHash = (string) ($this->context['deviceHash'] ?? '');
        if ($deviceHash !== '' && $this->db->db_GetRow(
            'SELECT 1 FROM `devices` d WHERE d.`device_id` = "' . $this->db->db_Escape($deviceHash) . '" AND '
            . Freezes::isFrozenSql('d.`user_id`') . ' LIMIT 1'
        )) {
            Log::warning('Registration from a frozen account’s browser refused');
            throw new RuntimeException('New accounts can’t be created from this browser. Contact support if you think this is a mistake.');
        }

        $this->db->db_Execute('START TRANSACTION');
        try {
            $userId = $this->accounts->register($email, $password, $role, $name, $image);
            $this->legal->recordAcceptance($userId, LegalDocuments::TERMS, $currentTerms, $language, (string) $this->context['userAgent']);
            $this->legal->recordAcceptance($userId, LegalDocuments::ROLE_AGREEMENTS[$role], $agreement, $language, (string) $this->context['userAgent']);
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
        Log::info('Account registered', ['user_id' => $userId, 'role' => $role, 'terms_version' => $currentTerms]);

        return ['id' => $userId, 'email' => $email, 'role' => $role, 'roles' => [$role]];
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
        // Only after the password matched, so a guess can't tell a frozen account exists.
        self::assertNotFrozen($this->db, (int) $user['id']);

        // With two-factor on, the password only earns a challenge; the
        // sign-in waits for `verifyTwoFactor` — unless this browser sent a
        // device token the account trusted within the last 30 days.
        $deviceToken = (string) ($this->input['deviceToken'] ?? '');
        // An unusual sign-in (`Risk::STEP_UP`) needs the code too, even with
        // two-factor off or a remembered browser.
        if ($this->context['stepUp']) {
            return $this->security->startLogin($user, (string) $this->context['stepUp']);
        }
        if (
            AccountSecurity::requiresTwoFactor($user)
            && !$this->security->isTrustedDevice((int) $user['id'], $deviceToken)
        ) {
            return $this->security->startLogin($user);
        }

        return $this->signIn($user);
    }

    /**
     * Refuses a frozen account (`user_freezes`, set by staff in the CMS):
     * "suspended until …", or permanently. A 400, not a 401 — the client
     * shows it on the login form.
     */
    public static function assertNotFrozen(db $db, int $userId): void
    {
        $freeze = Freezes::activeFor($db, $userId);
        if ($freeze === null) {
            return;
        }
        Log::info('Sign-in refused: account frozen', ['user_id' => $userId]);
        throw new RuntimeException($freeze['until'] !== null
            ? 'Your account is suspended until ' . gmdate('j M Y, H:i', (int) strtotime($freeze['until'])) . ' UTC. Contact support if you think this is a mistake.'
            : 'Your account is suspended. Contact support if you think this is a mistake.');
    }

    /** The `AuthResult` shape for `$user` (a `users` row); `AuthHttp` adds the tokens. */
    private function signIn(array $user): array
    {
        return $this->userSummary($user);
    }

    /** `id, email, name, role, roles` — the `AuthResult` without credentials. */
    public function userSummary(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $this->accounts->getRoleName((int) $user['id']),
            'roles' => $this->accounts->getRoles((int) $user['id']),
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
        self::assertNotFrozen($this->db, $userId);

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
    /**
     * "Become a guide" / "I want to travel": grants the account its other
     * role, together with the acceptance of that role's agreement — the
     * version the profile page's modal showed (`agreementVersion`). Returns
     * the account's roles.
     */
    private function actionAddRole(): array
    {
        $userId = $this->requireUserId();
        $role = (string) ($this->input['role'] ?? '');
        if (!in_array($role, Roles::APP_ROLES, true)) {
            throw new RuntimeException('Role must be "guest" or "guide".');
        }
        $agreement = $this->legal->assertRoleAgreement(
            $role,
            (string) ($this->input['agreementSlug'] ?? ''),
            (string) ($this->input['agreementVersion'] ?? '')
        );
        $language = (string) ($this->input['language'] ?? 'en');
        $language = preg_match('/^[a-z]{2}$/', $language) ? $language : 'en';

        $this->db->db_Execute('START TRANSACTION');
        try {
            $this->accounts->addRole($userId, $role);
            $this->legal->recordAcceptance($userId, LegalDocuments::ROLE_AGREEMENTS[$role], $agreement, $language, (string) $this->context['userAgent']);
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
        Log::info('Role added', ['user_id' => $userId, 'role' => $role, 'agreement_version' => $agreement]);

        $result = ['roles' => $this->accounts->getRoles($userId)];
        // Signed in with tokens: a new access token, so its `roles` claim
        // includes the new role straight away.
        if ($this->context['sid']) {
            $result += $this->tokens->access($userId, (string) $this->context['sid'], $this->context['did']);
        }

        return $result;
    }

    /**
     * "Not you? Secure your account" — the link in a new-sign-in email: ends
     * every sign-in, forgets every remembered browser, and hands back a
     * one-time reset token so the page can go straight to "choose a new
     * password". Whoever holds the link proved they read the account's email.
     */
    private function actionSecureAccount(): array
    {
        $userId = $this->security->redeemSecureLink((string) ($this->input['token'] ?? ''));
        $this->signOutEverywhere($userId, 'not_me');
        $this->security->forgetTrustedDevices($userId);
        Log::warning('Account secured from a new-sign-in email', ['user_id' => $userId]);

        return ['resetToken' => $this->security->createResetToken($userId)];
    }

    /** "Your devices": the browsers signed in now, this one marked. */
    private function actionMyDevices(): array
    {
        $userId = $this->requireUserId();
        $current = $this->context['did'];

        return ['devices' => array_map(
            static fn(array $device): array => $device + ['current' => $current !== null && $device['id'] === $current],
            $this->devices->signedInFor($userId)
        )];
    }

    /** Signs one of the account's browsers out — every sign-in made from it, at once. */
    private function actionSignOutDevice(): array
    {
        $userId = $this->requireUserId();
        $deviceRowId = (int) ($this->input['deviceId'] ?? 0);
        $hash = $deviceRowId > 0 ? $this->devices->deviceHashOf($userId, $deviceRowId) : null;
        if ($hash === null) {
            throw new RuntimeException('That device isn’t signed in to your account.');
        }

        $this->tokens->revokeDevice($userId, $hash, 'device_signed_out');
        $this->devices->record('device_signed_out', $userId, ['id' => $deviceRowId], $hash,
            (string) $this->context['clientIp'], (string) $this->context['userAgent']);
        Log::info('Device signed out', ['user_id' => $userId, 'device' => $deviceRowId]);

        return ['ok' => true, 'current' => $deviceRowId === $this->context['did']];
    }

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

    /** The caller's id, from the gateway (which verified their access token); 401 without one. */
    private function requireUserId(): int
    {
        $userId = $this->context['userId'];
        if (!$userId) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }
        Log::context(['user_id' => $userId]);

        return $userId;
    }
}
