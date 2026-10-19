<?php

/**
 * The API service: every domain action — decks, profiles, matches, bookings,
 * trips, reviews, translations. Internal only: the gateway forwards each
 * client request here over HTTP (`ServiceHttp`) with the caller's user id
 * already resolved by the auth service, so this service never sees a session
 * hash and holds no state between requests.
 *
 * Account actions (register, login, two-factor, password reset/change,
 * logout, session) live in the auth service (`auth/`).
 */
class RequestProcessor
{
    /**
     * Actions that can change someone's menu counters: the caller's and the
     * `targetId`'s are recomputed and pushed when one succeeds (`Push`).
     */
    private const COUNTER_ACTIONS = ['decide', 'revokeSelection', 'payGuideFee', 'cancelBooking', 'bookingAction', 'markSeen'];

    /** @var db */
    private $db;
    /** @var Users */
    private $users;
    /** @var Payments */
    private $payments;
    /** @var Selections */
    private $selections;
    /** @var Bookings */
    private $bookings;
    /** @var Notifications */
    private $notifications;
    /** @var Profiles */
    private $profiles;
    /** @var Geocoder */
    private $geocoder;
    /** @var Reviews */
    private $reviews;
    /** @var Translations */
    private $translations;
    /** @var Badges */
    private $badges;
    /** @var Messages */
    private $messages;
    /** @var Complaints */
    private $complaints;
    /** @var Levels */
    private $levels;
    /** @var Push */
    private $push;
    /** @var array|null the request `process()` is handling */
    private $input;
    /** @var int|null the caller, as the gateway resolved them */
    private $userId;
    /** @var string|null the role the caller acts as — one they hold, checked by the gateway */
    private $actingRole;

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->users = new Users($db);
        $this->payments = new Payments($db);
        $this->badges = new Badges($db);
        $this->push = new Push($redis, $this->badges);
        $this->notifications = new Notifications($db, new MailQueue($db, $redis), $this->push);
        $this->levels = new Levels($db, $this->notifications);
        $this->bookings = new Bookings($db, $this->notifications, $this->levels);
        $this->selections = new Selections($db, $this->users, $this->payments, $this->bookings, $this->levels);
        $this->profiles = new Profiles($db);
        $this->geocoder = new Geocoder($redis);
        $this->reviews = new Reviews($db, $this->levels);
        $this->translations = new Translations($db);
        $this->messages = new Messages($db, $this->push, $this->notifications);
        $this->complaints = new Complaints($db, new MailQueue($db, $redis));
    }

    /**
     * Runs one request — `{ action, ...payload }` — for `$context['userId']`
     * (null when signed out) and returns `[httpStatus, body]`; see
     * `ServiceHttp` for the transport.
     *
     * @return array{0: int, 1: array}
     */
    public function process(array $input, array $context = []): array
    {
        $this->input = $input;
        $this->userId = $context['userId'] ?? null;
        $this->actingRole = $context['actingRole'] ?? null;
        $action = (string) ($input['action'] ?? '');
        ContactFilter::configure($this->db, $this->userId);

        try {
            $result = $this->dispatch($action);
            if ($result[0] === 200 && in_array($action, self::COUNTER_ACTIONS, true)) {
                $this->push->touch((int) $this->userId, (int) ($input['targetId'] ?? 0));
            }

            return $result;
        } catch (AuthenticationException $e) {
            return [401, ['error' => $e->getMessage()]];
        } catch (ContactDetailsException $e) {
            return [400, $e->toBody()];
        } catch (RuntimeException $e) {
            return [400, ['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            Log::exception('Request failed', $e);
            return [500, ['error' => 'Unexpected server error.']];
        } finally {
            // Counters for everyone this request touched — the caller, the
            // other person, anyone a notification went to.
            $this->push->flush();
        }
    }

    /** @return array{0: int, 1: array} */
    private function dispatch(string $action): array
    {
        switch ($action) {
            case 'guides':
                return [200, ['guides' => $this->users->searchByRole('guide', $this->readFilters(), $this->profiles, $this->optionalUserId())]];

            case 'visitors':
                return [200, ['visitors' => $this->users->searchByRole('guest', $this->readFilters(), $this->profiles, $this->optionalUserId())]];

            case 'updateProfile':
                return [200, $this->actionUpdateProfile()];

            case 'attributeCategories':
                return [200, ['categories' => $this->profiles->getCategories()]];

            case 'myProfile':
                $userId = $this->requireUserId();
                return [200, $this->users->getFullAccount($userId, $this->profiles, $this->actingRole())
                    + ['trusted_device_count' => $this->users->trustedDeviceCount($userId)]];

            case 'updateAccount':
                return [200, $this->actionUpdateAccount()];

            case 'unlockPack':
                return [200, $this->actionUnlockPack()];

            case 'paymentState':
                return [200, $this->actionPaymentState()];

            case 'decide':
                return [200, $this->actionDecide()];

            case 'revokeSelection':
                return [200, $this->actionRevokeSelection()];

            case 'matchOverview':
                return [200, ['selections' => $this->selections->getOverview($this->requireUserId(), $this->actingRole())]];

            // Confirmed trips — scheduled, current and past (see `Bookings::tripsFor()`).
            case 'trips':
                $userId = $this->requireUserId();
                return [200, ['trips' => $this->bookings->tripsFor($userId, $this->actingRole())]];

            case 'payGuideFee':
                return [200, $this->actionPayGuideFee()];

            case 'cancelBooking':
                return [200, $this->actionCancelBooking()];

            case 'bookingAction':
                return [200, $this->actionBooking()];

            // Clears the Matches or My trips counter (see `Badges`); the
            // new counts reach the client as a `badges` push.
            case 'markSeen':
                $this->badges->markSeen($this->requireUserId(), (string) ($this->readInput()['section'] ?? ''), $this->actingRole());
                return [200, ['ok' => true]];

            // Reviews and public profiles (see `Reviews`, 013_reviews.sql).
            case 'publicProfile':
                return [200, $this->actionPublicProfile()];

            case 'reviews':
                return [200, $this->actionReviews()];

            case 'submitReview':
                return [200, $this->actionSubmitReview()];

            case 'deleteReview':
                return [200, $this->actionDeleteReview()];

            // Chat between matched people (see `Messages`, 030_messages.sql).
            // `Messages` touches whoever's unread counter changed itself.
            case 'conversations':
                return [200, ['conversations' => $this->messages->conversationsFor($this->requireUserId(), $this->actingRole())]];

            case 'messages':
                $input = $this->readInput();
                $beforeId = (int) ($input['beforeId'] ?? 0);
                return [200, $this->messages->threadWith($this->requireUserId(), $this->actingRole(), $this->requireTargetId(), $beforeId > 0 ? $beforeId : null)];

            case 'sendMessage':
                $body = $this->readInput()['body'] ?? '';
                if (!is_string($body)) {
                    throw new RuntimeException('A message must be text.');
                }
                return [200, $this->messages->send($this->requireUserId(), $this->actingRole(), $this->requireTargetId(), $body)];

            case 'shareLocation':
                $input = $this->readInput();
                return [200, $this->messages->shareLocation(
                    $this->requireUserId(),
                    $this->actingRole(),
                    $this->requireTargetId(),
                    $input['latitude'] ?? null,
                    $input['longitude'] ?? null,
                    $input['accuracy'] ?? null
                )];

            case 'markMessagesRead':
                $this->messages->markReadWith($this->requireUserId(), $this->actingRole(), $this->requireTargetId(), (int) ($this->readInput()['upToId'] ?? 0));
                return [200, ['ok' => true]];

            // Levels, perks, bonus packages and sanctions (see `Levels`, 041_levels.sql).
            case 'myLevel':
                $userId = $this->requireUserId();
                $this->levels->sync($userId);
                return [200, $this->levels->stateFor($userId, $this->actingRole())];

            case 'claimBonus':
                $userId = $this->requireUserId();
                $this->levels->claim($userId, (int) ($this->readInput()['bonusId'] ?? 0));
                return [200, $this->levels->stateFor($userId, $this->actingRole())];

            // One user reporting another (see `Complaints`, 037_complaints.sql).
            case 'reportUser':
                $input = $this->readInput();
                return [200, $this->complaints->file(
                    $this->requireUserId(),
                    (int) ($input['subjectId'] ?? 0),
                    $input['title'] ?? null,
                    $input['description'] ?? null
                )];

            // UI text for one language (see `Translations`, 018_translations.sql).
            case 'translations':
                return [200, $this->actionTranslations()];

            // The terms and conditions (with the privacy notice), fetched only
            // when the registration modal opens — see `LegalDocuments`.
            case 'legalDocument':
                return [200, $this->actionLegalDocument()];

            // Place search (a guide's base, the deck's search point): names ↔
            // coordinates through `Geocoder`. No session needed — the deck
            // works signed out too.
            case 'geocode':
                return [200, ['places' => $this->geocoder->search(
                    (string) ($this->readInput()['query'] ?? ''),
                    $this->readLanguage(),
                    Places::readPoint($this->readInput()['near'] ?? null, 'near')
                )]];

            // Where a traveller is looking for guides (the deck's search
            // point), so guides near there can find them; null clears it.
            case 'setSearchArea':
                $userId = $this->requireUserId();
                if ($this->actingRole() !== 'guest') {
                    throw new RuntimeException('Only a traveller has a search area.');
                }
                $this->profiles->setSearchArea($userId, $this->readPlace($this->readInput()['area'] ?? null, 'area'));
                return [200, ['ok' => true]];

            // A guide's own base — the guide's deck searches near it by default.
            case 'myGuideBase':
                $userId = $this->requireUserId();
                return [200, ['base' => $this->actingRole() === 'guide' ? $this->profiles->getGuideBase($userId) : null]];

            case 'reverseGeocode':
                $point = Places::readPoint($this->readInput(), 'reverseGeocode');
                return [200, ['place' => $this->geocoder->reverse($point['latitude'], $point['longitude'], $this->readLanguage())]];

            // Gateway-only (never routed for a client): what a socket that just
            // signed in should be shown — unread notifications (marked read
            // now) and the current counters.
            case 'pushState':
                $userId = $this->requireUserId();
                return [200, [
                    'notifications' => $this->notifications->takeUnreadFor([$userId])[$userId] ?? [],
                    'badges' => $this->badges->countsFor([$userId])[$userId],
                ]];

            default:
                return [404, ['error' => 'Unknown action.']];
        }
    }

    /** The request's `language` if it's one of ours, else English. */
    private function readLanguage(): string
    {
        $language = (string) ($this->readInput()['language'] ?? 'en');

        return in_array($language, Translations::LANGUAGES, true) ? $language : 'en';
    }

    /** The request currently being processed (see `process()`). */
    private function readInput(): array
    {
        return $this->input ?? [];
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
            // Where to search near (ranks closer people first), and how far
            // at most — only meaningful with `near`.
            'near' => Places::readPoint($input['near'] ?? null, 'near'),
            'maxKm' => $this->readMaxKm($input['maxKm'] ?? null),
        ];
    }

    private function readMaxKm($maxKm): ?int
    {
        if ($maxKm === null) {
            return null;
        }
        if (!is_int($maxKm) || $maxKm < 1 || $maxKm > 20000) {
            throw new RuntimeException('maxKm must be a whole number of kilometres, 1–20000.');
        }

        return $maxKm;
    }

    private function actionUnlockPack(): array
    {
        $userId = $this->requireUserId();
        if ($this->actingRole() !== 'guest') {
            throw new RuntimeException('Only guests purchase selection packs.');
        }

        // A higher level buys more picks per pack (`Levels::picksPerPack()`).
        $this->payments->recordPackPurchase($userId, Levels::picksPerPack($this->levels->effectiveLevel($userId, 'guest')));

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
        // The ids as well as the count: the client's matches store is
        // in-memory, so this is what it re-seeds from after a page reload.
        $decisions = $this->selections->getDecisionIdsFor($userId);

        return [
            'packsUnlocked' => Payments::PACK_SIZE > 0 ? intdiv($capacity, Payments::PACK_SIZE) : 0,
            'capacity' => $capacity,
            'picksUsed' => $picksUsed,
            'selectionsLeft' => max(0, $capacity - $picksUsed),
            'pickedIds' => $decisions['picked'],
            'passedIds' => $decisions['passed'],
            // Picks made during free picks, which don't count against the pack.
            'freePickIds' => $decisions['free'],
            // What the next £5 pack holds, and an active free-picks bonus (`Levels`).
            'packSize' => Levels::picksPerPack($this->levels->effectiveLevel($userId, 'guest')),
            'freePicksUntil' => $this->levels->activeBonusUntil($userId, 'free_picks'),
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

        return $this->selections->decide($userId, $this->actingRole(), $targetId, $decision);
    }

    private function actionRevokeSelection(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $targetId = (int) ($input['targetId'] ?? 0);

        if (!$targetId) {
            throw new RuntimeException('targetId is required.');
        }

        return $this->selections->revoke($userId, $this->actingRole(), $targetId);
    }

    /**
     * The guest pays the fee of a guide they're matched with, choosing when
     * to meet — see `Bookings::pay()`. Unlocks both sides' contact details.
     */
    private function actionPayGuideFee(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $guideId = (int) ($input['targetId'] ?? 0);

        if (!$guideId) {
            throw new RuntimeException('targetId is required.');
        }
        if ($this->actingRole() !== 'guest' || !$this->users->hasRole($guideId, 'guide')) {
            throw new RuntimeException('Only a visitor can pay a guide’s fee.');
        }

        return $this->bookings->pay(
            $userId,
            $guideId,
            (string) ($input['meetingAt'] ?? ''),
            $input['durationHours'] ?? null,
            (string) ($input['location'] ?? ''),
            $input['latitude'] ?? null,
            $input['longitude'] ?? null
        );
    }

    /**
     * Every other step in a booking's life, one `op` per step — see
     * `Bookings`. `targetId` is the other person on the booking; who may do
     * what is checked here by role, the rest by `Bookings` itself.
     */
    private function actionBooking(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $targetId = (int) ($input['targetId'] ?? 0);
        $op = (string) ($input['op'] ?? '');
        $reason = (string) ($input['reason'] ?? '');

        if (!$targetId) {
            throw new RuntimeException('targetId is required.');
        }

        // The acting role picks the booking: two people can be booked both ways.
        $role = $this->actingRole();
        if (!$this->users->hasRole($targetId, Roles::opposite($role))) {
            throw new RuntimeException('Bookings are between a visitor and a guide.');
        }
        [$guestId, $guideId] = $role === 'guest' ? [$userId, $targetId] : [$targetId, $userId];

        $guestOps = ['withdraw', 'requestCancellation', 'withdrawCancellation'];
        $guideOps = ['accept', 'decline', 'approveCancellation', 'refuseCancellation'];
        $eitherOps = ['requestFinish', 'confirmFinish', 'dismissFinish', 'setLocation'];
        if (in_array($op, $guestOps, true) && $role !== 'guest') {
            throw new RuntimeException('Only the visitor can do that.');
        }
        if (in_array($op, $guideOps, true) && $role !== 'guide') {
            throw new RuntimeException('Only the guide can do that.');
        }

        switch ($op) {
            case 'accept':
                return $this->bookings->accept($guideId, $guestId);
            case 'decline':
                return $this->bookings->decline($guideId, $guestId, $reason);
            case 'withdraw':
                return $this->bookings->withdraw($guestId, $guideId);
            case 'requestCancellation':
                return $this->bookings->requestCancellation($guestId, $guideId, $reason);
            case 'withdrawCancellation':
                return $this->bookings->withdrawCancellationRequest($guestId, $guideId);
            case 'approveCancellation':
                return $this->bookings->approveCancellation($guideId, $guestId);
            case 'refuseCancellation':
                return $this->bookings->refuseCancellation($guideId, $guestId, $reason);
            case 'requestFinish':
                return $this->bookings->requestFinish($userId, $guestId, $guideId);
            case 'confirmFinish':
                return $this->bookings->confirmFinish($userId, $guestId, $guideId);
            case 'dismissFinish':
                return $this->bookings->dismissFinish($userId, $guestId, $guideId);
            case 'setLocation':
                return $this->bookings->setLocation(
                    $userId,
                    $guestId,
                    $guideId,
                    (string) ($input['location'] ?? ''),
                    $input['latitude'] ?? null,
                    $input['longitude'] ?? null
                );
        }

        throw new RuntimeException('Unknown booking step "' . $op . '". Expected one of: '
            . implode(', ', array_merge($guideOps, $guestOps, $eitherOps)) . '.');
    }

    /** The guide cancels their booking with a visitor, refunding them — see `Bookings::cancelByGuide()`. */
    private function actionCancelBooking(): array
    {
        $input = $this->readInput();
        $userId = $this->requireUserId();
        $guestId = (int) ($input['targetId'] ?? 0);

        if (!$guestId) {
            throw new RuntimeException('targetId is required.');
        }
        if ($this->actingRole() !== 'guide') {
            throw new RuntimeException('Only the guide can cancel a booking.');
        }

        return $this->bookings->cancelByGuide($userId, $guestId, (string) ($input['reason'] ?? ''));
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
        $this->assertProfileTextClean($input);

        $this->profiles->setProfile($userId, $fields);
        // Headline, description, picks and spans are the acting role's own.
        $role = $this->actingRole();
        $this->profiles->setRoleText($userId, $role, array_intersect_key($input, ['headline' => 1, 'bio' => 1]));

        if (is_array($input['attributes'] ?? null)) {
            $newCategoryLabels = is_array($input['newCategoryLabels'] ?? null) ? $input['newCategoryLabels'] : [];
            foreach ($input['attributes'] as $categoryKey => $values) {
                if (is_array($values)) {
                    $categoryKey = (string) $categoryKey;
                    $label = isset($newCategoryLabels[$categoryKey]) ? (string) $newCategoryLabels[$categoryKey] : null;
                    $this->profiles->setAttributeValues($userId, $role, $categoryKey, $values, $label);
                }
            }
        }
        // `range` categories (hours of the day). An empty array for a category
        // clears it, which means "unconstrained" rather than "unchanged" —
        // see `Profiles::setRanges()`.
        foreach ((array) ($input['ranges'] ?? []) as $categoryKey => $spans) {
            if (is_array($spans)) {
                $this->profiles->setRanges($userId, $role, (string) $categoryKey, $spans);
            }
        }

        // Where a guide works: `base` present sets it (null removes it);
        // `radiusKm` is how far they'll travel, null = flexible.
        if (array_key_exists('base', $input)) {
            if ($role !== 'guide') {
                throw new RuntimeException('Only a guide sets where they guide.');
            }
            $this->profiles->setGuideBase($userId, $this->readPlace($input['base'], 'base'), $this->readRadius($input['radiusKm'] ?? null));
        }

        $this->bookings->onProfileUpdated($userId);

        return ['ok' => true];
    }

    /**
     * A chosen place from a request (`{ latitude, longitude, label,
     * countryCode? }`), or null. Its label is shown to other people, so it's
     * checked for contact details like any profile text.
     *
     * @return array{latitude: float, longitude: float, label: string, countryCode: ?string}|null
     */
    private function readPlace($place, string $field): ?array
    {
        $point = Places::readPoint($place, $field);
        if ($point === null) {
            return null;
        }
        $label = trim(preg_replace('/\s+/u', ' ', (string) ($place['label'] ?? '')));
        if ($label === '' || mb_strlen($label) > 160) {
            throw new RuntimeException($field . ' needs a place name (up to 160 characters).');
        }
        ContactFilter::assertClean($label, $field);
        $country = strtoupper((string) ($place['countryCode'] ?? ''));

        return $point + [
            'label' => $label,
            'countryCode' => preg_match('/^[A-Z]{2}$/', $country) ? $country : null,
        ];
    }

    private function readRadius($radius): ?int
    {
        if ($radius === null || $radius === '') {
            return null;
        }
        if (!is_int($radius) || $radius < 1 || $radius > 1000) {
            throw new RuntimeException('radiusKm must be a whole number of kilometres, 1–1000, or empty for flexible.');
        }

        return $radius;
    }

    /**
     * Everything `updateProfile` would show other people — the free-text
     * fields, and any category or option it would add to the shared lists
     * every profile editor and filter bar suggests from — checked for
     * contact details before anything is written (see `ContactFilter`), so a
     * refused save changes nothing. `field` in the error is the input key
     * (`bio`, `headline`, …), or `attribute` for a category or option.
     */
    private function assertProfileTextClean(array $input): void
    {
        $texts = [];
        foreach (['headline', 'bio', 'priceLabel', 'priceNote', 'includes', 'party'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                ContactFilter::assertClean((string) $input[$key], $key);
                $texts[$key] = (string) $input[$key];
            }
        }
        // Shown together on the profile, so checked together too: a number
        // split between the headline and the bio is still a number.
        ContactFilter::assertCleanTogether($texts);

        $attributeTexts = [];
        foreach ((array) ($input['newCategoryLabels'] ?? []) as $key => $label) {
            $attributeTexts[] = (string) $key;
            $attributeTexts[] = is_scalar($label) ? (string) $label : '';
        }
        foreach ((array) ($input['attributes'] ?? []) as $key => $values) {
            $attributeTexts[] = (string) $key;
            foreach (is_array($values) ? $values : [] as $value) {
                $attributeTexts[] = is_scalar($value) ? (string) $value : '';
            }
        }
        foreach ($attributeTexts as $text) {
            ContactFilter::assertClean($text, 'attribute');
        }
        // Chips sit side by side: "560", "027", "31" read as one number.
        ContactFilter::assertClean(implode("\n", $attributeTexts), 'attribute');
    }

    /**
     * Updates account-level fields on `users` — name, email, phone, 2FA
     * delivery channel, and (through `ImageData::validate()`) the profile photo.
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
            ContactFilter::assertClean($fields['name'], 'name');
        }
        if (isset($input['phone'])) {
            $fields['phone'] = trim((string) $input['phone']);
        }
        if (array_key_exists('image', $input)) {
            $fields['image'] = ImageData::validate((string) ($input['image'] ?? ''));
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
            // not an optional one. SMS/WhatsApp stay in the enum for later,
            // but `AccountSecurity` can only send codes by email for now.
            if (in_array($method, ['sms', 'whatsapp'], true)) {
                throw new RuntimeException('Two-factor codes can only be sent by email for now.');
            }
            if ($method !== 'email') {
                throw new RuntimeException('twoFactorMethod must be "email".');
            }

            $fields['two_factor_method'] = $method;
        }

        $this->users->updateAccount($userId, $fields);
        $this->bookings->onProfileUpdated($userId);

        return ['ok' => true];
    }

    /**
     * One guest or guide by id, without contact details — what both public
     * profile pages load (see `Users::getPublicProfile()`). Works signed out.
     */
    private function actionPublicProfile(): array
    {
        $input = $this->readInput();
        $userId = (int) ($input['userId'] ?? 0);
        // The page asks for the role it shows (a guide's or a traveller's).
        $profile = $userId > 0 ? $this->users->getPublicProfile($userId, $this->profiles, $this->requestedRole($input)) : null;
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

        // Ratings are per role: the reviews of the subject *as* `role`.
        $role = $this->requestedRole($input) ?? 'guide';

        return $this->reviews->listFor($subjectId, $role, $limit, $offset) + [
            'viewer' => $this->reviews->viewerStateFor($this->optionalUserId(), $subjectId, $role),
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

        return $this->reviews->submit($authorId, $subjectId, $this->requestedRole($input) ?? 'guide', $input['rating'] ?? null, $comment);
    }

    /** A `role` field naming the side a person is looked at in (`guest`/`guide`), or null. */
    private function requestedRole(array $input): ?string
    {
        $role = (string) ($input['role'] ?? '');

        return in_array($role, Roles::APP_ROLES, true) ? $role : null;
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
     * Every UI string for `language` — no session needed, the signed-out deck
     * is translated too. `strings` is always a JSON object, even when the
     * language has no rows yet (the client then shows its bundled English).
     */
    /**
     * `scopes` / `except` (optional): key prefixes — `discover`,
     * `common.app_header` — to fetch only, or to leave out. The client asks
     * for the page it opens on first and for the rest in the background.
     */
    private function actionTranslations(): array
    {
        $input = $this->readInput();
        $language = (string) ($input['language'] ?? '');

        return [
            'language' => $language,
            'strings' => (object) $this->translations->forLanguage(
                $language,
                $this->readScopes($input, 'scopes'),
                $this->readScopes($input, 'except')
            ),
        ];
    }

    /** @return string[]|null */
    private function readScopes(array $input, string $field): ?array
    {
        if (!isset($input[$field])) {
            return null;
        }
        if (!is_array($input[$field]) || count($input[$field]) > 200) {
            throw new RuntimeException('Invalid ' . $field . '.');
        }
        $scopes = [];
        foreach ($input[$field] as $scope) {
            if (!is_string($scope) || !preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $scope)) {
                throw new RuntimeException('Invalid ' . $field . '.');
            }
            $scopes[] = $scope;
        }
        return $scopes;
    }

    private function actionLegalDocument(): array
    {
        $input = $this->readInput();
        $language = (string) ($input['language'] ?? 'en');
        if (!in_array($language, Translations::LANGUAGES, true)) {
            throw new RuntimeException('Unsupported language.');
        }

        return (new LegalDocuments($this->db))->current((string) ($input['slug'] ?? ''), $language);
    }

    /**
     * The caller's id, which the gateway resolved from their session through
     * the auth service (and slid forward) before forwarding the request.
     * Throws `AuthenticationException` (→ HTTP 401) rather than the generic
     * `RuntimeException` (→ 400) other validation failures use, so the client
     * can tell "you're logged out" apart from an ordinary bad-input error
     * (see `plugins/api.ts`'s `call()`).
     */
    private function requireUserId(): int
    {
        if (!$this->userId) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }

        return $this->userId;
    }

    /** The request's `targetId` — the other person an action is about. */
    private function requireTargetId(): int
    {
        $targetId = (int) ($this->readInput()['targetId'] ?? 0);
        if ($targetId <= 0) {
            throw new RuntimeException('targetId is required.');
        }

        return $targetId;
    }

    /**
     * The caller's id when the request carried a session, `null` when it
     * didn't — for actions that still work signed out but personalise
     * themselves for a signed-in caller (the `guides`/`visitors` decks,
     * which hide anyone the caller already decided on; see
     * `Users::searchByRole()`). A session that was sent but had expired never
     * gets this far: the gateway answers 401 itself.
     */
    /**
     * The role this request acts as. The gateway sends one the user holds
     * (their first role when the client didn't say); only an account with no
     * app role at all gets here without one.
     */
    private function actingRole(): string
    {
        $this->requireUserId();
        if (!$this->actingRole) {
            throw new RuntimeException('This account has no guest or guide role.');
        }

        return $this->actingRole;
    }

    private function optionalUserId(): ?int
    {
        return $this->userId;
    }}
