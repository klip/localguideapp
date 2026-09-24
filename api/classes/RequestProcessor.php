<?php

/**
 * Single entry point for front-to-back requests: resolves an `action` from
 * the query string or JSON body, dispatches it, and writes a JSON response.
 * Keeps HTTP plumbing (input parsing, status codes, error shape) out of the
 * data-access classes and keeps `index.php` a one-liner.
 */
class RequestProcessor
{
    /** @var db */
    private $db;
    /** @var Users */
    private $users;
    /** @var Payments */
    private $payments;
    /** @var Selections */
    private $selections;
    /** @var Profiles */
    private $profiles;
    /** @var Session */
    private $sessions;
    /** @var Reviews */
    private $reviews;
    /** @var array|null memoized parsed request body */
    private $input;

    public function __construct(db $db)
    {
        $this->db = $db;
        $this->users = new Users($db);
        $this->payments = new Payments($db);
        $this->selections = new Selections($db, $this->users, $this->payments);
        $this->profiles = new Profiles($db);
        $this->sessions = new Session($db);
        $this->reviews = new Reviews($db);
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            switch ($this->resolveAction()) {
                case 'register':
                    $this->respond($this->actionRegister());
                    break;

                case 'login':
                    $this->respond($this->actionLogin());
                    break;

                case 'guides':
                    $this->respond(['guides' => $this->users->searchByRole('guide', $this->readFilters(), $this->profiles, $this->optionalUserId())]);
                    break;

                case 'visitors':
                    $this->respond(['visitors' => $this->users->searchByRole('guest', $this->readFilters(), $this->profiles, $this->optionalUserId())]);
                    break;

                case 'updateProfile':
                    $this->respond($this->actionUpdateProfile());
                    break;

                case 'attributeCategories':
                    $this->respond(['categories' => $this->profiles->getCategories()]);
                    break;

                case 'myProfile':
                    $this->respond($this->users->getFullAccount($this->requireUserId(), $this->profiles));
                    break;

                case 'updateAccount':
                    $this->respond($this->actionUpdateAccount());
                    break;

                case 'changePassword':
                    $this->respond($this->actionChangePassword());
                    break;

                case 'unlockPack':
                    $this->respond($this->actionUnlockPack());
                    break;

                case 'paymentState':
                    $this->respond($this->actionPaymentState());
                    break;

                case 'decide':
                    $this->respond($this->actionDecide());
                    break;

                case 'revokeSelection':
                    $this->respond($this->actionRevokeSelection());
                    break;

                case 'matches':
                    $this->respond(['matches' => $this->selections->getActiveMatches($this->requireUserId())]);
                    break;

                case 'matchOverview':
                    $this->respond(['selections' => $this->selections->getOverview($this->requireUserId())]);
                    break;

                case 'logout':
                    $this->respond($this->actionLogout());
                    break;

                case 'session':
                    $this->respond($this->actionSession());
                    break;

                // Reviews and public profiles (see `Reviews`, 013_reviews.sql).
                case 'publicProfile':
                    $this->respond($this->actionPublicProfile());
                    break;

                case 'reviews':
                    $this->respond($this->actionReviews());
                    break;

                case 'submitReview':
                    $this->respond($this->actionSubmitReview());
                    break;

                case 'deleteReview':
                    $this->respond($this->actionDeleteReview());
                    break;

                default:
                    $this->respond(['error' => 'Unknown action.'], 404);
            }
        } catch (AuthenticationException $e) {
            $this->respond(['error' => $e->getMessage()], 401);
        } catch (RuntimeException $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            $this->db->writeLog($e->getMessage());
            $this->respond(['error' => 'Unexpected server error.'], 500);
        }
    }

    private function resolveAction(): string
    {
        if (!empty($_GET['action'])) {
            return (string) $_GET['action'];
        }

        $body = $this->readInput();
        return isset($body['action']) ? (string) $body['action'] : '';
    }

    /** Parses $_GET (for GET requests) or a JSON body (for everything else), once per request. */
    private function readInput(): array
    {
        if ($this->input !== null) {
            return $this->input;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $this->input = $_GET;
            return $this->input;
        }

        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $this->input = is_array($decoded) ? $decoded : $_POST;
        return $this->input;
    }

    /**
     * Pulls the `Users::searchByRole()` filter shape out of the request body —
     * every key optional. Categories are addressed by key rather than named
     * here, so a category a user invents is filterable without a code change:
     * `attributes` is a `{ categoryKey: values[] }` map, `ranges` a
     * `{ categoryKey: [[start, end], ...] }` one, and `ageRanges` the same
     * span shape against an age computed from `date_of_birth`.
     */
    private function readFilters(): array
    {
        $input = $this->readInput();

        $attributes = [];
        foreach ((array) ($input['attributes'] ?? []) as $categoryKey => $values) {
            if (is_array($values) && $values) {
                $attributes[(string) $categoryKey] = array_values($values);
            }
        }

        $ranges = [];
        foreach ((array) ($input['ranges'] ?? []) as $categoryKey => $spans) {
            if (is_array($spans) && $spans) {
                $ranges[(string) $categoryKey] = array_values($spans);
            }
        }

        return [
            // Gender is the one profile column still filtered directly: it's a
            // human constant, not a category (see 010_profile_attributes.sql).
            'gender' => isset($input['gender']) ? (string) $input['gender'] : null,
            'attributes' => $attributes,
            'ageRanges' => is_array($input['ageRanges'] ?? null) ? array_values($input['ageRanges']) : [],
            'ranges' => $ranges,
            // Not a filter as such: opts out of hiding candidates the caller
            // already decided on (see `Users::searchByRole()`).
            'includeDecided' => !empty($input['includeDecided']),
        ];
    }

    /** data: URI images from ImageCropper.vue max out around 480x480 JPEG — a few hundred KB is generous headroom. */
    private const MAX_IMAGE_BASE64_LENGTH = 2_000_000;

    private function actionRegister(): array
    {
        $input = $this->readInput();

        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? 'guest');
        $name = isset($input['name']) ? trim((string) $input['name']) : null;
        $image = isset($input['image']) ? $this->validateImage((string) $input['image']) : null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        $userId = $this->users->register($email, $password, $role, $name, $image);
        $sessionHash = $this->sessions->create($userId);

        return ['id' => $userId, 'email' => $email, 'role' => $role, 'sessionHash' => $sessionHash];
    }

    /**
     * Only accepts a `data:image/(png|jpeg|webp);base64,...` string within a
     * sane size cap — this lands straight in `users.image` with no other
     * processing, so it's the one place guarding against something huge or
     * not-actually-an-image-data-URI getting through.
     */
    private function validateImage(string $image): string
    {
        if ($image === '') {
            return '';
        }

        if (strlen($image) > self::MAX_IMAGE_BASE64_LENGTH) {
            throw new RuntimeException('Image is too large.');
        }

        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/]+=*$/', $image)) {
            throw new RuntimeException('Image must be a PNG, JPEG or WebP data URL.');
        }

        return $image;
    }

    private function actionLogin(): array
    {
        $input = $this->readInput();

        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $user = $this->users->verifyCredentials($email, $password);
        if (!$user) {
            throw new RuntimeException('Invalid email or password.');
        }

        $sessionHash = $this->sessions->create((int) $user['id']);

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $this->users->getRoleName((int) $user['id']),
            'sessionHash' => $sessionHash,
        ];
    }

    private function actionUnlockPack(): array
    {
        $userId = $this->requireUserId();
        if ($this->users->getRoleName($userId) !== 'guest') {
            throw new RuntimeException('Only guests purchase selection packs.');
        }

        $this->payments->recordPackPurchase($userId);

        return $this->paymentStateFor($userId);
    }

    private function actionPaymentState(): array
    {
        return $this->paymentStateFor($this->requireUserId());
    }

    private function paymentStateFor(int $userId): array
    {
        $capacity = $this->payments->getCapacity($userId);
        $picksUsed = $this->selections->getPicksUsed($userId);
        // The ids as well as the count: the client's shortlist store is
        // in-memory, so this is what it re-seeds from after a page reload.
        $decisions = $this->selections->getDecisionIdsFor($userId);

        return [
            'packsUnlocked' => Payments::PACK_SIZE > 0 ? intdiv($capacity, Payments::PACK_SIZE) : 0,
            'capacity' => $capacity,
            'picksUsed' => $picksUsed,
            'selectionsLeft' => max(0, $capacity - $picksUsed),
            'pickedIds' => $decisions['picked'],
            'passedIds' => $decisions['passed'],
        ];
    }

    private function actionDecide(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $targetId = (int) ($input['targetId'] ?? 0);
        $decision = (string) ($input['decision'] ?? '');

        if (!$targetId) {
            throw new RuntimeException('targetId is required.');
        }

        return $this->selections->decide($userId, $targetId, $decision);
    }

    private function actionRevokeSelection(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $targetId = (int) ($input['targetId'] ?? 0);

        if (!$targetId) {
            throw new RuntimeException('targetId is required.');
        }

        return $this->selections->revoke($userId, $targetId);
    }

    /**
     * Sets whichever profile fields are present in the request — see
     * `Profiles::setProfile()`/`setAttributeValues()`/`setRanges()`.
     * Every field is optional and independent (a request can update just
     * `dateOfBirth`, just one category, or everything at once). `attributes`
     * is a `{ [categoryKey]: string[] }` map — one entry per dynamic
     * category (`languages`, `interests`, `food_allergies`, ...; see
     * `Profiles::getCategories()`) — replacing that category's full
     * selection; an absent category key is left untouched. A category key
     * not yet in the DB is created on the fly (see `Profiles::
     * setAttributeValues()`), using `newCategoryLabels[categoryKey]` as its
     * display label if the client supplied one (the key the client sends
     * for a brand-new category is a slug it generated from that label —
     * see `ProfilePage.vue`'s combobox) — falling back to the key itself
     * otherwise. `ProfilePage.vue` is the client.
     */
    private function actionUpdateProfile(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();

        $columnMap = [
            'dateOfBirth' => 'date_of_birth',
            'gender' => 'gender',
            'headline' => 'headline',
            'bio' => 'bio',
            'priceAmount' => 'price_amount',
            'priceLabel' => 'price_label',
            'priceNote' => 'price_note',
            'includes' => 'includes',
            'party' => 'party',
            'durationHours' => 'duration_hours',
        ];

        $fields = [];
        foreach ($columnMap as $inputKey => $column) {
            if (array_key_exists($inputKey, $input)) {
                $fields[$column] = $input[$inputKey];
            }
        }

        if (isset($fields['gender']) && !in_array($fields['gender'], ['male', 'female'], true)) {
            throw new RuntimeException('gender must be "male" or "female".');
        }
        if (isset($fields['date_of_birth']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fields['date_of_birth'])) {
            throw new RuntimeException('dateOfBirth must be a YYYY-MM-DD date.');
        }

        $this->profiles->setProfile($userId, $fields);

        if (is_array($input['attributes'] ?? null)) {
            $newCategoryLabels = is_array($input['newCategoryLabels'] ?? null) ? $input['newCategoryLabels'] : [];
            foreach ($input['attributes'] as $categoryKey => $values) {
                if (is_array($values)) {
                    $categoryKey = (string) $categoryKey;
                    $label = isset($newCategoryLabels[$categoryKey]) ? (string) $newCategoryLabels[$categoryKey] : null;
                    $this->profiles->setAttributeValues($userId, $categoryKey, $values, $label);
                }
            }
        }
        // `range` categories (hours of the day). An empty array for a category
        // clears it, which means "unconstrained" rather than "unchanged" —
        // see `Profiles::setRanges()`.
        foreach ((array) ($input['ranges'] ?? []) as $categoryKey => $spans) {
            if (is_array($spans)) {
                $this->profiles->setRanges($userId, (string) $categoryKey, $spans);
            }
        }

        return ['ok' => true];
    }

    /**
     * Updates account-level fields on `users` — name, email, phone, 2FA
     * delivery channel, and (reusing `validateImage()`) the profile photo.
     * `phone` arrives already fully formed (e.g. "+350 56002731") — any
     * country-code picker is a `ProfilePage.vue`-only concern, not something
     * this API knows about. Unlike `Profiles::setProfile()`'s fields,
     * `email`/`twoFactorMethod` need cross-row validation (uniqueness, a
     * phone on file), so that happens here rather than in `Users::
     * updateAccount()`.
     */
    private function actionUpdateAccount(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();

        $fields = [];

        if (isset($input['name'])) {
            $fields['name'] = trim((string) $input['name']);
        }
        if (isset($input['phone'])) {
            $fields['phone'] = trim((string) $input['phone']);
        }
        if (array_key_exists('image', $input)) {
            $fields['image'] = $this->validateImage((string) ($input['image'] ?? ''));
        }

        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid email is required.');
            }

            $existing = $this->users->findByEmail($email);
            if ($existing && (int) $existing['id'] !== $userId) {
                throw new RuntimeException('That email is already registered to another account.');
            }

            $fields['email'] = $email;
        }

        if (isset($input['twoFactorMethod'])) {
            $method = (string) $input['twoFactorMethod'];
            // 'none' is still a valid *stored* value (legacy accounts that
            // haven't picked a channel yet — see 008_profile_updates.sql)
            // but is deliberately not an acceptable *choice* here: two-factor
            // delivery is a required field on `ProfilePage.vue` going forward,
            // not an optional one.
            if (!in_array($method, ['email', 'sms', 'whatsapp'], true)) {
                throw new RuntimeException('twoFactorMethod must be "email", "sms" or "whatsapp".');
            }

            $phoneOnFile = $fields['phone'] ?? $this->users->getPhone($userId);
            if (in_array($method, ['sms', 'whatsapp'], true) && empty($phoneOnFile)) {
                throw new RuntimeException('Add a phone number before enabling SMS or WhatsApp two-factor.');
            }

            $fields['two_factor_method'] = $method;
        }

        $this->users->updateAccount($userId, $fields);

        return ['ok' => true];
    }

    /** Verifies `currentPassword` before setting `newPassword` — see `Users::verifyPasswordFor()`/`setPassword()`. */
    private function actionChangePassword(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();

        $currentPassword = (string) ($input['currentPassword'] ?? '');
        $newPassword = (string) ($input['newPassword'] ?? '');

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        if (!$this->users->verifyPasswordFor($userId, $currentPassword)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $this->users->setPassword($userId, $newPassword);

        return ['ok' => true];
    }

    /**
     * One guest or guide by id, without contact details — what both public
     * profile pages load (see `Users::getPublicProfile()`). Works signed out.
     */
    private function actionPublicProfile(): array
    {
        $userId = (int) ($this->readInput()['userId'] ?? 0);
        $profile = $userId > 0 ? $this->users->getPublicProfile($userId, $this->profiles) : null;
        if (!$profile) {
            throw new RuntimeException('Profile not found.');
        }

        return $profile;
    }

    /**
     * A page of `userId`'s reviews with the summary header (see
     * `Reviews::listFor()`), plus `viewer`: whether the caller may review
     * this person and their existing review if so. Works signed out (`viewer`
     * then says no). `limit` defaults to 20 and is capped there — the "Show
     * all" modal pages through by `offset`.
     */
    private function actionReviews(): array
    {
        $input = $this->readInput();
        $subjectId = (int) ($input['userId'] ?? 0);
        if ($subjectId <= 0) {
            throw new RuntimeException('userId is required.');
        }

        $limit = (int) ($input['limit'] ?? Reviews::MAX_PAGE_SIZE);
        $offset = (int) ($input['offset'] ?? 0);

        return $this->reviews->listFor($subjectId, $limit, $offset) + [
            'viewer' => $this->reviews->viewerStateFor($this->optionalUserId(), $subjectId),
        ];
    }

    /** Creates or updates the caller's review of `subjectId` — every rule lives in `Reviews::submit()`. */
    private function actionSubmitReview(): array
    {
        $input = $this->readInput();
        $authorId = $this->requireUserId();
        $subjectId = (int) ($input['subjectId'] ?? 0);
        if ($subjectId <= 0) {
            throw new RuntimeException('subjectId is required.');
        }

        $comment = $input['comment'] ?? null;
        if ($comment !== null && !is_string($comment)) {
            throw new RuntimeException('Comment must be text.');
        }

        return $this->reviews->submit($authorId, $subjectId, $input['rating'] ?? null, $comment);
    }

    /** Deletes one of the caller's own reviews. */
    private function actionDeleteReview(): array
    {
        $authorId = $this->requireUserId();
        $reviewId = (int) ($this->readInput()['reviewId'] ?? 0);
        if ($reviewId <= 0) {
            throw new RuntimeException('reviewId is required.');
        }

        $this->reviews->delete($authorId, $reviewId);

        return ['ok' => true];
    }

    /**
     * Resolves the calling user's id from `sessionHash` (see `Session::
     * resolve()`) — the source of identity for every authenticated action.
     * A valid, non-expired hash gets its 5-minute inactivity window slid
     * forward as a side effect of this call. Throws `AuthenticationException`
     * (→ HTTP 401) rather than the generic `RuntimeException` (→ 400) other
     * validation failures use, so the client can tell "you're logged out"
     * apart from an ordinary bad-input error and react to it (see
     * `plugins/api.ts`'s `call()`).
     */
    private function requireUserId(): int
    {
        $hash = (string) ($this->readInput()['sessionHash'] ?? '');
        $userId = $hash !== '' ? $this->sessions->resolve($hash) : null;
        if (!$userId) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }

        return $userId;
    }

    /**
     * The caller's id when the request carried a session, `null` when it
     * didn't — for actions that still work signed out but personalise
     * themselves for a signed-in caller (the `guides`/`visitors` decks,
     * which hide anyone the caller already decided on; see
     * `Users::searchByRole()`).
     *
     * A hash that is present but invalid still throws (→ 401) rather than
     * quietly falling back to the anonymous view, so an expired session
     * signs the user out here exactly like it does on every other action.
     */
    private function optionalUserId(): ?int
    {
        $hash = (string) ($this->readInput()['sessionHash'] ?? '');

        return $hash === '' ? null : $this->requireUserId();
    }

    /** Destroys the current session, if any. Never throws for a missing/unknown hash — there's simply nothing to do. */
    private function actionLogout(): array
    {
        $hash = (string) ($this->readInput()['sessionHash'] ?? '');
        if ($hash !== '') {
            $this->sessions->destroy($hash);
        }

        return ['ok' => true];
    }

    /**
     * Validates the current session and returns the same `AuthResult` shape
     * `register`/`login` do (including the same, unrotated `sessionHash`) —
     * what `App.vue` calls on boot to confirm a locally-cached session (see
     * `stores/auth.ts`) is still valid server-side, and to slide its
     * inactivity window forward just by the app having been opened.
     */
    private function actionSession(): array
    {
        $userId = $this->requireUserId();
        $user = $this->users->findById($userId);
        if (!$user) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }

        return [
            'id' => $userId,
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $this->users->getRoleName($userId),
            'sessionHash' => (string) ($this->readInput()['sessionHash'] ?? ''),
        ];
    }

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }
}
