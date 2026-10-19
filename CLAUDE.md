# RockGuide

A Tinder-style app for matching visitors with local guides: visitors swipe
through a deck of guides (and vice versa), pick from a purchased pack of
selections, and chat once matched (contact details are never shared). Working name in code/prototype
files is "RockGuide"; the directory and package are named
`localguideapp`, and the GitHub repo is `localguide`.

Git repository, branch `main`.

## Progress so far

**Working end-to-end, against the real backend (nothing mocked in these
paths):** registration/login with real password hashing (`Auth.php`) and
server-side sessions (5-minute sliding expiry, see "Sessions"); role-based
post-register/post-login redirects (guide vs. guest); self-service profile
editing — contact info, 2FA channel, photo, guide/visitor profile fields,
and a user-extensible interest/language/allergy taxonomy — all via
`ProfilePage.vue`; server-side-filtered discovery decks for both sides
(`DiscoverPage.vue`/`GuideDiscoverPage.vue`), both now persisting real
accept/reject decisions through `api.decide()` and able to produce a
genuine mutual match (`selections.active = 1`, with a live "it's a match"
notification on the guide's side); a payment-flow *simulation* that
unlocks selection packs and actually gates a visitor's remaining picks;
a full match-management page for
both roles (`MatchesPage.vue`, `/matches` — see "Match management" below);
a mobile slide-in burger menu (`AppHeader.vue`); public profile pages for
both roles (`/guides/:id`, `/visitors/:id`) with Google-Maps-style reviews
that are the only source of a profile's ★ rating (see "Reviews" below).

**Deliberately fake / not wired to anything real:** `Payments.php` just
inserts a `status = 'paid'` row on `unlockPack` — there is no payment
gateway; the guide's fee (`Bookings::pay()`) and its refund
(`Bookings::cancelByGuide()`) are recorded the same way, no money moves —
see "Guide fees & bookings" below; SMS/WhatsApp two-factor (the options
exist but are disabled — codes only go by email, see "Two-factor login &
password reset");
`admin/` and the `cms_users` table are placeholders for a CMS that doesn't
exist yet (no `cms_creds` table either, so nothing could log into it if it
did). **Email, on the other hand, is real** (`Mailer.php`, Gmail SMTP) once
the `SMTP_*` server params are set — see "Email" under Docker environment.

**Known gaps / inconsistencies** (worth knowing about, not urgent):
- **One gateway process holds about 1,000 sockets** (`stream_select`'s
  descriptor limit) — run more `ws` replicas beyond that. See
  `docs/scaling.md` for this and the rest of the scaling plan.
- **Clock-driven booking changes run every 30 s** (`Bookings::sweep()`, in
  the scheduler service only), so a booking request expires, or a trip starts
  or finishes, up to half a minute late — and a page loaded in that window
  still shows the old state.
- **Services share one database** — `users` in particular is read and
  written by both auth and api. The plan for splitting it is in
  `docs/scaling.md`.
- **Meeting times are free-form**: the guest picks any future date-time
  when paying (up to a year ahead); nothing checks it against the guide's
  working hours (`hour_of_day`), and the guide can only accept or decline,
  not propose another.
- **Trip length is the guest's to set** (optional, when paying). The guide
  sees it before accepting but can't change it.
- **`user_profiles.rating`/`tours` are dead columns** — nothing reads or
  writes them since 013 (ratings come from `reviews`); drop them in a later
  migration.

## Layout

- `client/` — Vue 3 frontend (the actual app). Run all frontend commands from
  here, not the repo root.
- The PHP backend is a set of services (see "Services" below). No
  framework, no Composer — plain scripts + small hand-rolled classes:
  - `gateway/` — the WebSocket gateway every browser talks to.
  - `auth/` — accounts, passwords, 2FA, sessions (Redis).
  - `api/` — every other action, plus the scheduler (`api/bin/scheduler.php`).
  - `mailer/` — the mail worker that sends the email outbox.
  - `lib/` — shared by all of them: `db`, `Log`, `RedisClient`/`Resp`,
    `MailQueue`, `ServiceHttp`, `ImageData`, `AuthenticationException`.
- `deploy/` — config the docker-lemp stack mounts: `php/rockguide.ini`, and
  `observability/` (Loki, Alloy, Grafana provisioning + dashboard).
- `docs/scaling.md` — database load, scaling proposals, the GKE target.
- `admin/` — empty placeholder, presumably a future admin panel.
- Root `node_modules/` has no corresponding root `package.json`; ignore it,
  the real dependency tree lives in `client/`.

## Frontend (`client/`)

Vue 3 + TypeScript + Vite, Pinia for state, vue-router, Sass (with
`@picocss/pico` as the base design system). Package manager is Yarn 4
(`yarn@4.18.0` via corepack) — use `yarn`, not `npm`.

```sh
cd client
yarn dev            # dev server
yarn build           # type-check + production build
yarn test:unit       # Vitest
yarn test:e2e:dev    # Cypress against the dev server
yarn lint            # oxlint --fix, then eslint --fix
yarn format          # oxfmt on src/
```

Formatting: no semicolons, single quotes (`.oxfmtrc.json`). Linting is
primarily `oxlint` (`.oxlintrc.json`), with `eslint` layered on top for rules
oxlint doesn't cover. `oxlint`'s vitest plugin requires an explicit type
parameter on every `vi.fn()` (`vi.fn<Api['register']>()`, not `vi.fn()`) —
otherwise harmless-looking mocks fail lint.

`src/__tests__/` holds Vitest + `@vue/test-utils` component tests
(`App.spec.ts`, `AppHeader.spec.ts` — the mobile drawer's open/close/focus
behavior, `RegisterPage.spec.ts`, `ProfilePage.spec.ts`,
`MatchesPage.spec.ts`, `TripsPage.spec.ts`, `BookingPanel.spec.ts`, `FilterPanel.spec.ts` — the generic filter store and
the category-then-values bar built on it, `Reviews.spec.ts` — the review
section, its "Show all" modal and the write/edit form,
`VisitorProfilePage.spec.ts`, `AccountSecurity.spec.ts` — the login code
step and the forgot/reset password pages, `DiscoverPage.spec.ts` — the
open deck and both modals, `pendingRejects.spec.ts`, `i18n.spec.ts` —
interpolation, plural forms, the English fallback layers and RTL) plus one
plain-module test, `api.spec.ts`, for `plugins/api.ts`'s `call()` itself
(session-hash attachment, 401-triggered auto-logout — see "Sessions"
above; it mocks `fetch` via `vi.stubGlobal` rather than going through a
component). The pattern for a page that calls the API: build a local test
router (routes it actually navigates to/from), the shared `fakeApi()` stub
(`src/__tests__/fakeApi.ts` — every method a typed `vi.fn()`; add a new
`api.*()` method there once, and a spec layers its own defaults as
`fakeApi({ ...defaults, ...overrides })`) provided via `global.provide: {
[API_KEY]: api }` — no real `fetch` involved — and a fresh `createPinia()`
per test, also passed to `setActivePinia()` so a store can be inspected
after mount (`useMessagesStore().items`) the same way the component sees
it. `App.spec.ts` needs the same `[API_KEY]` provide as of this session
feature — `App.vue` calls `useApi()` synchronously in its own setup body
(see "Sessions" above) — even though its one test never triggers a call
(anonymous render, and `App.vue` only calls `api.session()` when a cached
session already exists). Any test that calls `useAuthStore().setUser()`
should also clean up `localStorage` afterward (see `api.spec.ts`) — the
store reads it back on creation, so one test's session can otherwise leak
into the next test's fresh `Pinia` instance.

`@` is aliased to `client/src`.

### Structure

- `src/pages/` — one component per route (Home, Register, Login, Unlock,
  Discover, GuideProfile, VisitorProfile, GuideDiscover,
  Matches, Trips, Profile, ForgotPassword, ResetPassword, NotFound).
- `src/router/index.ts` — documents the mapping from the original static
  prototype's scroll sections to routes; read the comment there before adding
  routes.
- `src/stores/` — Pinia stores: `filters` (discovery filter state, keyed by
  category rather than by named field — see "Discovery filters" below),
  `categories` (the attribute taxonomy, fetched once and shared by the
  filter bar, the profile editor and every card that labels a tag), `matches` (`stores/matches.ts`, `useMatchesStore()` — formerly
  `shortlist`; the paid selection pack:
  `packsUnlocked`, `picks`, `passed` — in-memory, so a reload drops it;
  `App.vue` re-fetches `packsUnlocked` at boot for guests, but `picks`/
  `passed` genuinely start empty again — see below), `auth` (the signed-in user + session hash, set by
  `RegisterPage`/`LoginPage` and persisted to `localStorage` — see
  "Sessions" below), `messages` (global toast queue — see below),
  `guides`/`visitors` (real discovery-deck data — see "Real guide/visitor
  cards" below). Because `matches` doesn't survive a reload the way
  `auth` now does, two places re-sync it from the server: `App.vue` on every
  boot (for a signed-in guest, right after its `api.session()` check — this
  is what makes a purchased pack survive a plain refresh; without it the deck
  showed the paywall and the header read "0 / 0 picked" despite the payments
  existing in the DB), and `LoginPage.vue` right after `api.login()`, so the
  capacity is correct before the first render of `/discover`. Both call
  `api.paymentState()` and feed `matchesStore.applyPaymentState()`.
  **Picks are restored as well as the pack count**: `paymentState` returns
  `pickedIds`/`passedIds` (guide ids, from `Selections::getDecisionIdsFor()`)
  alongside the counts, and `matchesStore.applyPaymentState()` seeds `picks`/
  `passed` from them — so a refresh lands on "2 / 10 picked", not "0 / 10".
  The ids ride on `paymentState` rather than coming from
  `api.matchOverview()` because that endpoint's rows carry the counterpart's
  photo, which is far too much payload for every page load. The store seeds
  the arrays directly rather than through `pick()`/`pass()`, whose capacity
  gate would drop decisions the server has already accepted. Best-effort and non-blocking:
  wrapped in its own try/catch so a failure there doesn't stop the login
  itself or need a toast of its own — worst case the user briefly sees "0
  unlocked" and can revisit `/unlock`. Register never needs the
  equivalent (a brand-new account always has 0 packs).
- `src/types/index.ts` — shared domain types: `Guide`, `Visitor`,
  `DiscoveryFilters`, `AttributeMap`/`RangeMap` (the two open, category-keyed
  maps that replaced every fixed attribute field), `NumericRange`, `Gender`,
  `Decision`. There is no `Activity` union any more — no category is named
  in the types.
- `src/data/guides.ts`, `src/data/visitors.ts` — hardcoded placeholder decks.
  **Only `HomePage.vue`'s hero `PhonePreview` still uses these** (marketing
  decoration, deliberately static) — `DiscoverPage`/`GuideDiscoverPage`/
  `GuideProfilePage` were switched to real data from
  `stores/guides.ts`/`stores/visitors.ts` (or `api.publicProfile()`);
  don't reintroduce the mock import there. Their `ratingAverage`/
  `reviewCount` are made-up numbers, which is fine for a marketing mock.
- `src/data/countryCodes.ts` — a static (not DB-backed) list of country
  dial codes for `ProfilePage.vue`'s phone country-code picker — see
  "Self-service profile editing" below. Not exhaustive; add more entries
  if a real user needs one that's missing.
- `prototype/rockguide-pico-ui-prototype.html` — the original static
  prototype the current routes/screens were derived from; useful as a design
  reference.

### Component structure

`src/pages/` (one per route — see `src/router/index.ts` for the exact
path/name table) and the shared `src/components/` each is built from:

- **`HomePage.vue`** (`/about`) — marketing page; no longer the landing
  page (`/` redirects to `/discover`, see "Open deck & first-pack modal"). Hero (`PillBadge`,
  `PhonePreview` showing the first hardcoded `data/guides.ts` entry — see
  above for why this is the one place still using mock data), a
  three-step "how it works" row (`SectionTitle` + `FeatureCard` × 3), and
  a pricing panel (`PriceBox`). Not signed-in-aware beyond hiding the "I'm
  a guide" CTA once `auth.isAuthenticated`.
- **`RegisterPage.vue`** (`/register`, `?role=guide` for the guide flow) /
  **`LoginPage.vue`** (`/login`) — auth forms sharing `PanelCard` +
  `SectionTitle`; no shared form component between them — each owns its
  own `<form>`, refs, and `formMessagePlacement()` call (see "Talking to
  the API" below).
- **`UnlockPage.vue`** (`/unlock`) — the pack-purchase simulation:
  `PanelCard` + `PriceBox` (`compact`) + `PillBadge`; posts to
  `api.unlockPack()` and routes to `/profile` (first pack, to add contact
  details) or `/discover` (a top-up). `/onboarding` — the prototype's
  never-wired contact form — is now just a router redirect to `/profile`.
- **`DiscoverPage.vue`** (`/discover`, visitor browsing guides) /
  **`GuideDiscoverPage.vue`** (`/guide/discover`, guide browsing visitors)
  — the two discovery decks, deliberately built from the same pieces:
  `PanelCard`, `FilterPanel` (the category-then-values filter form, in a
  `<details>` rail, open on desktop and collapsed on mobile — see "Discovery
  filters"), `ProfileCard` (the
  swipeable card, which itself renders a `TagList` and a `DecisionRow` for
  its accept/reject buttons), `PillBadge` (the header counter/pill).
  `SelectionCounter` is `DiscoverPage.vue`-only (the pack-remaining strip
  — see "Domain model" for why the guide side has no equivalent). Both
  pages pull their deck from `stores/guides.ts`/`stores/visitors.ts`.
- **`GuideProfilePage.vue`** (`/guides/:id`) — the "Full profile →"
  destination linked from a `ProfileCard`; reuses `PanelCard`, `TagList`,
  `PillBadge`, `DecisionRow` at full width instead of a swipe card. Its
  accept/reject mirror `DiscoverPage.vue`'s: local `matchesStore` first, then
  a best-effort `api.decide()` (skipped when signed out). Loads its one
  guide with `api.publicProfile()` (mapped through `stores/guides.ts`'s
  exported `toGuideCard()`), not the guide list, and ends with a
  `ReviewsSection`. A guest's id here reads as "Guide not found".
- **`VisitorProfilePage.vue`** (`/visitors/:id`) — the guest-side
  counterpart, the "Full profile →" target on `GuideDiscoverPage.vue`'s
  card: name, photo, age, gender, party, duration, bio, category tags —
  never contact details — then a `ReviewsSection`. No accept/reject here;
  a guide's id reads as "Visitor not found".
- There is **no `/shortlist` page any more** — it was removed; every
  "View matches" link and the header's picks-pill go to `/matches`.
- **`MatchesPage.vue`** (`/matches`) — match management for both roles
  (see "Match management" below); `SectionTitle`, `PanelCard`, `PillBadge`.
- **`TripsPage.vue`** (`/trips`) — confirmed trips for both roles,
  scheduled / in progress / past (see "My trips" below); `SectionTitle`,
  a `PanelCard` per trip, `PillBadge`, `BookingPanel`.
- **`ProfilePage.vue`** (`/profile`) — the self-service account/profile
  editor (see "Self-service profile editing" below); `PanelCard` × 2,
  `AttributeCombobox` (the phone country-code field, and the whole dynamic
  category/option picker), `ImageCropper` (photo).
- **`NotFoundPage.vue`** (catch-all `/:pathMatch(.*)*`) — a single
  `PanelCard`.

App shell (mounted once, in `App.vue`, around every page — see
`App.vue`'s own boot-time session check under "Sessions" below):
`AppHeader.vue` (the nav — see "Talking to the API" for its role-aware,
auth-aware CTA logic, and "Mobile burger menu" below for the drawer), `MessageToaster.vue` (renders `stores/messages.ts`'s
toast queue), `AppFooter.vue` (uses `BrandMark` in its `as="text"` form, plus
the terms/privacy links), `CookieNotice.vue` and a `TermsDialog` — see
"Terms and conditions".

Shared, purely presentational components (no store/API access of their
own — every one just takes props/emits):
- `PanelCard.vue` — the white elevated card every form/deck sits inside;
  `title`/`subtitle` props or a `#header` slot, plus an optional `#footer`.
- `SectionTitle.vue` — eyebrow (rendered as a `PillBadge`) + heading +
  optional lead, at the top of every page section.
- `PillBadge.vue` — small rounded label; `tone="warm" | "neutral"`.
- `BrandMark.vue` — the "RockGuide" wordmark, as a link (default,
  `AppHeader.vue`) or bare text (`as="text"`, `AppFooter.vue`).
- `TagList.vue` — a wrapping row of soft tag chips.
- `DecisionRow.vue` — the reject/accept button pair every card's footer
  uses; default labels "✕ Reject"/"♥ Interested" — `DiscoverPage.vue`,
  `GuideDiscoverPage.vue` and `GuideProfilePage.vue` all use the defaults,
  no page currently overrides them.
- `ProfileCard.vue` — the swipeable card itself: photo, name/age, a meta
  line, price (guide side only), bio, `TagList`, optional `includes`
  prose, a slot, then its own `DecisionRow`. Deliberately domain-agnostic
  (plain scalar props, not a `Guide`/`Visitor` object) since both decks
  render it with different emphasis.
- `RangePicker.vue` — two sliders, an Add button and the spans collected so
  far as removable chips: the input for a `range` category (a guide's hours
  on `ProfilePage.vue`, hours or age in the filter bar). Adding is explicit
  because a category holds *several* spans. (`FilterChips.vue`, the old
  hardcoded quick-filter row, is gone — the filter bar covers it.)
- `FilterPanel.vue` — the filter form: gender + age on top, then an
  accordion of categories (see "Discovery filters" below). Built from
  `stores/categories.ts`, so it names no category itself; writes straight
  to `stores/filters.ts`.
- `ActiveFilters.vue` — the filters in force as × pills above the deck
  (both Discover pages); reads/writes `stores/filters.ts` only.
- `DualRangeSlider.vue` — one track, two handles (two stacked native range
  inputs), `v-model` a single span; the filter bar's age.
- `BookingPanel.vue` — **not** purely presentational (calls the API
  itself): the whole booking for one matched pair on `MatchesPage.vue` —
  pay, accept/decline, cancel/request cancellation, finish the trip. See
  "Guide fees & bookings".
- `SelectionCounter.vue` — the "N selections left" strip with an optional
  unlock action; see `DiscoverPage.vue` above.
- `AttributeCombobox.vue` — the generic type-ahead picker (see "Dynamic
  profile attributes" below for its full mechanics); also used standalone
  for the phone country-code field (`allow-create="false"`).
- `ImageCropper.vue` — the photo picker/cropper (see "Real guide/visitor
  cards" below for why it only ever renders on `ProfilePage.vue`).
- `FeatureCard.vue` — a numbered explainer tile, `HomePage.vue`'s "how it
  works" row only.
- `MapPinLinks.vue` — "Open in Apple Maps · Open in Google Maps" for a map
  pin, the viewer's own maps app first (a booking's meeting pin).
- `ProtectedNotice.vue` — "Protected by RockGuide" before every payment
  (see "Terms and conditions"); opens the terms at 5.1.
- `PriceBox.vue` — the warm-gradient £5 price panel; full-width on
  `HomePage.vue`, `compact` on `UnlockPage.vue`.
- `PhonePreview.vue` — the hero phone-mock device on `HomePage.vue`;
  purely decorative — its "Pass"/"Interested" labels are `<span>`s, not
  buttons, deliberately not reachable by keyboard or announced as
  interactive.
- `MessageToaster.vue` — renders `stores/messages.ts`'s queue (see
  "Talking to the API" below); mounted once, in `App.vue`.
- `StarRating.vue` (read-only stars, partial fill for averages, one
  `role="img"` label), `ReviewSummary.vue` (average + 5→1 bars),
  `ReviewItem.vue` (one review) — presentational. `ReviewsSection.vue`,
  `ReviewsDialog.vue` and `ReviewForm.vue` are the exception to this list's
  "no API access" rule: they call `useApi()` themselves so both profile
  pages can drop in one `<ReviewsSection>`. See "Reviews" below.

### Talking to the API

- `src/plugins/api.ts` — the request layer, installed as a Vue plugin
  (`app.use(apiPlugin)` in `main.ts`) providing `useApi()` via `inject`/
  `provide` rather than a bare module import. Sends
  `{ action, ...payload }` over the API WebSocket (`plugins/socket.ts`, see
  "Realtime" — not `fetch`) to `VITE_API_BASE_URL` + `/ws` (see
  `.env.development`; defaults to `https://api.rockguide.com` if unset) and
  matches `RequestProcessor`'s actions 1:1: `api.register()`, `api.login()`,
  `api.guides(filters?)`, `api.visitors(filters?)` (filtered server-side —
  see "Real guide/visitor cards" below), `api.updateProfile()`,
  `api.attributeCategories()`, `api.myProfile()`, `api.updateAccount()`,
  `api.changePassword()` (the self-service profile page — see "Self-service
  profile editing" below). Every failure path — network error,
  non-JSON response, or the API's `{ error }` body — normalizes to a thrown
  `ApiError` (`.message`, `.status`; `status` is `0` for requests that never
  got a response at all).
- `src/stores/messages.ts` + `src/components/MessageToaster.vue` — the
  message-presenting layer: any code that catches an `ApiError` (or wants to
  confirm something worked) calls `useMessagesStore().error(text)` /
  `.success(text)` / `.info(text)`; `MessageToaster`, mounted once in
  `App.vue`, renders the queue as auto-dismissing (5s) toasts. Nothing pushes
  to this store automatically — callers decide the wording. A toast can carry
  a `to` (route path, `.info()`'s 5th argument): it then renders as a link —
  tapping it opens that page and closes the toast (notifications and chat
  messages use this, see "In-app notifications"). Page-level toasts stack in a
  column sized to their text; anchored ones still assume a 48 px height. A
  toast's timer pauses while it's hovered or has focus (`pause()`/`resume()`),
  so a keyboard user can reach its link. Each toast also
  carries a `MessagePlacement`, passed as the second arg to `.error()`/
  `.success()`/`.info()` and defaulting to page-level (top-middle of the
  viewport) when omitted — the right default for general/API-level messages
  with no specific subject (e.g. a page's on-mount load failure). Build a non-default one with
  `anchorTo('element' | 'input', el)`, which resolves `el`'s
  `getBoundingClientRect()` **eagerly** (call it right at the triggering
  action, not lazily inside a `.then()`/after a list mutation) since the
  element may not still be around by the time the toast is actually pushed
  (e.g. a row that's filtered out of a list once its action succeeds). `'element'`
  centers the toast on the anchor's top-middle point (e.g. a submitted
  form); `'input'` places it left-aligned directly beneath the anchor,
  matching its width (e.g. a field-specific validation message).
- `src/utils/formMessagePlacement.ts` — `formMessagePlacement(text, refs)`,
  used by `LoginPage.vue`/`RegisterPage.vue` to route a login/register
  failure to the email input, the password input, or the form itself
  (`anchorTo('element', ...)`), by matching `email`/`password` in the
  message text. Text-matching is a workaround, not a pattern to generalize:
  the API returns a plain message string (see `RequestProcessor::
  actionRegister()`/`actionLogin()` for the fixed set this matches against),
  not a structured per-field error — if that ever changes, switch this to
  match on a real field code instead.
- `RegisterPage.vue` / `LoginPage.vue` call `api.register()`/`api.login()`,
  set `useAuthStore()` on success, and push a toast (via
  `formMessagePlacement()` on failure, `anchorTo('element', formRef)` on
  success) — the pattern to follow for wiring up any other form.
  `RegisterPage.vue` reads `route.query.role` (`'guide'` or default
  `'guest'`) to drive both the copy and the `role` sent to
  `api.register()`; the only source of `?role=guide` today is
  `HomePage.vue`'s "I'm a guide" CTA, which is itself
  `v-if="!auth.isAuthenticated"` (hidden once logged in). A guide
  registration lands on `/profile` (their card is empty until they fill it
  in); a visitor lands on `/discover`, whose payment modal asks for the
  first pack. If a page's code chunk fails to load (stale after a deploy or
  a dev-server reload), `router.onError` loads the target as a fresh page
  instead of leaving it blank — at most once per 10 s. `RegisterPage.vue` **doesn't offer a photo picker at all** —
  no `ImageCropper` on this page — a new account is created with no photo,
  and `ImageCropper.vue` only ever runs on `ProfilePage.vue`, after the
  account already exists; don't reintroduce it here.
- **`LoginPage.vue` redirects by role, same as `RegisterPage.vue`**:
  `router.push(user.role === 'guide' ? '/guide/discover' : '/discover')`
  off the `AuthResult` `api.login()` returns. This only works because
  `RequestProcessor::actionLogin()` includes `role` (via
  `Users::getRoleName()`) in that response — it didn't originally (only
  `actionRegister()`/`actionSession()` did), so every login silently fell
  through to the `else` branch and sent guides to the visitor-side
  `/discover` regardless of role; caught by testing the redirect live, not
  by a type error (the field was simply `undefined`, and `AuthResult.role`
  is optional). If `AuthResult` ever drops another action's `role`, expect
  the same silent-fallthrough bug.
- **A signed-in guide never sees `/discover`'s "Guides for you"/pack-paywall
  box** — belt-and-suspenders, not just the login redirect above:
  `AppHeader.vue`'s "Discover" nav link is role-aware (`discoverTo`,
  `/guide/discover` for a guide, `/discover` otherwise), and
  `DiscoverPage.vue` itself redirects (`router.replace('/guide/discover')`)
  in `onMounted()` if `auth.user?.role === 'guide'`, before ever calling
  `guidesStore.load()` — covers a stale bookmark or browser back/forward
  landing a guide there directly, not just the two navigation paths.
- **`AppHeader.vue`'s picks-pill and "Log out" are two independent `v-if`s,
  not a `v-if`/`v-else-if` pair** — the picks-pill (link to `/matches`,
  shown whenever `useMatchesStore().hasAccess`) and "Log out" (shown
  whenever `useAuthStore().isAuthenticated`, regardless of `hasAccess`) can
  and do render together for a visitor who's unlocked a pack. They used to
  be `v-else-if`'d, which meant **any authenticated visitor with
  `hasAccess` had no way to log out at all** — the picks-pill took over
  that slot in the nav and never gave it back. "Log out" calls
  `api.logout()` best-effort, then `auth.clear()`, pushes a success toast,
  and routes home. When signed out, the header shows **both** a "Log in"
  link (`/login`) and the "Get started" CTA (`/register`) side by side —
  previously only "Get started" was shown, so a signed-out returning user
  (including one who just got redirected here by a session-expiry toast,
  see "Sessions" below) had no direct link back to `/login` from the nav,
  only the small "Already registered? Log in" text buried on the register
  page itself. A "Profile" link to `/profile` is a separate, independent
  nav item shown whenever `isAuthenticated` — it doesn't participate in any
  of this, so it's visible alongside whichever combination of the above is
  showing. **"How it works" (`/about#how`) is the mirror image of those** — it
  renders only while signed *out* (`v-if="!isAuthenticated"`), being
  marketing copy a signed-in user is already past; the `#how` section
  itself still exists on `HomePage.vue` for anyone who lands on `/`.
- Payments/matching actions — `api.unlockPack()`, `api.paymentState()`,
  `api.decide()`, `api.revokeSelection()` — no longer take
  an explicit `userId`; the caller's identity comes from the session hash
  `call()` attaches automatically (see "Sessions" below). `UnlockPage.vue` calls `unlockPack()`
  for real and syncs the result into `useMatchesStore().applyPaymentState()`.
  (`api.matches()`/`Selections::getActiveMatches()` were removed with the
  `/shortlist` page, their only caller — `/matches` uses `matchOverview()`.)
  `decide()` **is** now called from
  both discovery decks — `DiscoverPage.vue`'s `accept()`/`reject()` and
  `GuideDiscoverPage.vue`'s `accept()`/`reject()` — now that both read real
  ids from `stores/guides.ts`/`stores/visitors.ts` rather than the old
  mock-id decks; see the Domain model section below for the mutual-match
  notification this unlocked.

### Translations & language switcher (`src/i18n`)

**No UI text is hardcoded** — every string is `t('{page}.{widget}.{key}')`
(`discover.empty.nobody_new_title`, `common.booking_panel.pay`; `page` is the
route's page or `common` for shared components/helpers, whose widget is the
component). Values carry `{placeholders}`; plurals are separate keys with a
CLDR suffix, picked by `tn(key, count)` via `Intl.PluralRules` (`_one`,
`_other`, plus `_few`/`_many` for Russian and `_zero`/`_two`/`_few`/`_many`
for Arabic; a missing form falls back to `_other`).

- **The database is the source of truth, English included** (`translations`
  table, 018 — so a future CMS can edit it). `api.translations(lang)` →
  `Translations::forLanguage()`, no session needed. Lookup order: the
  language's rows → the database's English → `src/i18n/en.json` (a bundled
  copy of the English, used for tests, a slow/failed fetch, and untranslated
  keys) → the key itself (with a dev-mode console warning).
- **15 languages** (`LANGUAGES` in `src/i18n/index.ts`, must match
  `Translations::LANGUAGES`): the top ten languages of international
  travellers merged with the top ten languages of the most-visited
  destinations (UN Tourism data), plus Portuguese and Ukrainian — en, es,
  fr, de, zh, it, ja, ru, ko, ar, tr, pt, el, th, uk. All fully translated; country names come
  from CLDR (`Intl.DisplayNames`). Arabic sets `<html dir="rtl">`.
- **The language is the URL's first segment** (`/es/discover`) — it's the
  router's *base* (`router/index.ts`, `createWebHistory('/es/')`), not a
  route param, so routes and links stay language-free (`to="/matches"`
  renders `/es/matches`) and active-link matching works. A URL naming a
  supported language wins over localStorage (`rockguide.language`) and is
  saved; one without is rewritten in place (saved choice → browser language →
  English) before the router reads it. `main.ts` waits up to 1.5 s for the
  first page's strings before mounting.
- **Loaded in two steps.** At boot only the shell's and the first route's
  text is fetched (`api.translations(lang, { scopes })`); the rest of the
  language (`{ except }`) and the database's English follow in the
  background. A navigation before that lands waits for it up to 1.5 s
  (`ensureScopes()` in a `router.beforeResolve`). Which prefixes a route needs
  is `src/i18n/scopes.json`, generated by `yarn i18n:scopes`
  (`scripts/i18n-scopes.mjs` follows App.vue's and each page's imports and
  collects every `page.widget.` key prefix). **Re-run it after adding text
  to a page** — `i18nScopes.spec.ts` fails while it's stale. `en.json` is a
  lazily loaded chunk now, fetched only when a key is missing from the
  database's text (or a fetch failed); `src/__tests__/setup.ts` preloads it
  for tests.
- **Switching language reloads** under the new prefix (`LanguageSwitcher.vue`
  in the header, options labelled in their own language). A production host
  must serve `index.html` for any `/<lang>/…` path (Vite dev already does).
- `t()` state is module-level (not Pinia), so it works in templates, plain
  helpers and stores with nothing to install. Anything built once at module
  load (a constant array of labels, a prop default) won't follow a language
  change — use a computed/getter (see `HomePage.vue`'s `steps`,
  `AGE_CATEGORY.label`) or a template fallback (`rejectLabel ?? t(...)`).
- **Adding a string**: add the key to `en.json` *and* a row per language to
  the `translations` table (or a new migration); `en.json` alone renders but
  isn't CMS-editable. Card placeholders (`toGuideCard()`) are resolved when a
  deck is fetched, so they switch on the next fetch.
- **Legal text isn't UI text**: the terms live in `legal_documents`, fetched
  on demand — see "Terms and conditions".
- **Not translated** (data or server text, not UI strings): attribute
  category/option labels (`attribute_categories`/`attribute_options` —
  user-created), people's own profile text, API error messages, emails and
  in-app notification copy (`Bookings.php`, `AccountSecurity.php`), and
  `data/guides.ts`'s marketing mock.

### Terms and conditions (registration)

"Continue" on `/register` doesn't create the account: it opens a
`TermsDialog` showing the terms, fetched only then
(`api.legalDocument('terms', language)`), and **"Accept and create account"**
calls `api.register()` with the shown `termsVersion` (+ `language`).
Cancel closes it; a failed load offers Retry and keeps Accept disabled; a
failed registration closes it, toasts as before, and drops the cached terms.

- **Storage** (024): `legal_documents` (slug, version = the date it takes
  effect, language, title, body; the newest published version is in force,
  English if a language lacks a row), `legal_placeholders` (the legal
  entities — operator, data controller, processors, DPO, EU/UK
  representative, supervisory authority, retention, governing law — filled
  once for every language; **all NULL for now**, shown highlighted as
  `[label]` until a lawyer supplies them: `UPDATE legal_placeholders SET
  value = …`), `user_legal_acceptances` (user, version, language, user
  agent, time — proof of consent).
- **Server side**: `lib/LegalDocuments.php`. The auth service refuses
  `register` unless `termsVersion` is the version in force, and writes the
  acceptance in the same transaction as the account.
- **Text**: `docs/legal/terms/<version>/<lang>.md`, all 15 languages, same
  structure and placeholders; `php api/bin/build-legal-sql.php terms
  <version>` generates the seed (025). A new version = a new folder + a new
  generated migration. It's a draft, not legal advice — have it reviewed.
- **Rendering**: `LegalDocument.vue` turns the Markdown subset
  (`utils/legalMarkdown.ts`: `##`/`###`, `- ` lists, paragraphs) into plain
  text nodes — never `v-html` — and is `translate="no"` (browser machine
  translation of a contract can change it).
- **`TermsDialog.vue`** is the one dialog for the terms: a wide
  `ModalDialog`, fetches on first open, Retry on failure, scrolls to a
  `section` ("9", "9.8" — `LegalDocument` tags headings with
  `data-section`), `#footer` slot gets `{ terms }` (default: Close),
  `forget()` drops the copy. Registration renders its own with Cancel/Accept;
  App.vue renders one more, driven by `stores/legal.ts` (`showTerms(section?)`).
- **Footer**: "Terms & conditions" and "Privacy & cookies" (section 9) —
  buttons styled as links, since they open the dialog. They replaced the
  prototype's "Concept UI · Mobile first · Pico CSS" tagline.
- **Cookie notice** (`CookieNotice.vue`, in App.vue): fixed at the foot of
  the page until "OK"; "Privacy notice" opens the dialog at 9.8. The app
  stores only strictly necessary things (localStorage: session, language,
  trusted-device tokens, pending rejects, this notice), which under
  ePrivacy needs telling, not consent — so there's deliberately no
  Accept/Reject. **Adding analytics or anything optional means turning it
  into a real consent choice** (nothing set before yes, Reject as easy as
  Accept, the answer kept) and bumping `NOTICE_VERSION`, which re-shows it
  (`rockguide.cookieNotice` holds the acknowledged version).
- **Protected by RockGuide** (section 5.1, all 15 languages): what paying
  through the platform covers (the refund rules, help with a dispute, real
  reviews) and that arrangements or payments outside it aren't covered.
  Also in the text since 2026-10-16: section 2 says contact details aren't
  shared (chat instead); section 4 obliges users to keep contact and payment
  on the platform; section 6 names the Report button and "lowering your
  level, benefits or place in search results" as a measure; 9.2/9.6 cover
  messages, shared locations and complaints. These were amended **in place**
  in the 2026-10-15 version, which hadn't shipped; once real users have
  accepted a version, changes need a new version folder instead. The
  Markdown subset has no bold — don't use `**`.
- **`ProtectedNotice.vue`** sits right before every payment — `UnlockPage`
  and Discover's payment modal (`kind="pack"`), `BookingPanel`'s pay form
  (`kind="fee"`) — and its "How it works" opens the terms at 5.1
  (`stores/legal.ts`'s `showTerms('5.1')`).
- **Role agreements** (049/050): `guide_agreement` and
  `traveller_agreement`, drafts in all 15 languages under
  `docs/legal/<slug>/2026-10-18/`, built with the same script. Registration
  shows the terms **and** the starting role's agreement in one `TermsDialog`
  (`slugs` prop; the footer slot gets `{ documents }`) and sends both
  versions (`termsVersion`, plus `agreementSlug` + `agreementVersion` — the
  slug because both agreements can share a version date; auth refuses either
  if it isn't that role's document in force,
  `LegalDocuments::assertRoleAgreement()`); adding a
  role later accepts that role's agreement (see "Acting role").
- **Not done yet**: existing accounts never accepted anything (no re-accept
  prompt), nor any role agreement.

### Open deck & first-pack modal (`DiscoverPage.vue`)

`/` redirects to `/discover`, so the deck is the landing page, and it's no
longer behind a paywall — what's gated is "Interested":

- **Signed out**: the first suggested card shows; Interested opens a modal
  (`ModalDialog.vue`) with "Create account" / "Log in". Reject moves on and
  queues the guide id in `localStorage` (`rockguide.pendingRejects`,
  `src/utils/pendingRejects.ts`) — no `decide()` yet. `stores/matches.ts`
  seeds `passed` from that queue and unions it into `applyPaymentState()`,
  so neither a reload nor the login re-sync brings those cards back.
- **Syncing the queue**: `App.vue` calls `syncPendingRejects()` in the
  background whenever `auth.user.id` changes (register/login) and once at
  boot after the session check (a retry). For a guest it sends each as
  `decide('pass')` — skipping guides the account already decided on, so a
  pick made elsewhere isn't overwritten — then clears the queue; a network/
  5xx/401 failure keeps that id for next time, a 400 drops it. A guide
  account just discards the queue; signed out, it's left alone. "Take
  another look" (`resetPassed()`) clears it too.
- **Signed-in guest**: `DiscoverPage` fetches `api.paymentState()` itself on
  mount (alongside the deck — App.vue's boot sync may not have finished),
  and with no pack opens the payment modal straight away. "Not now" closes
  it; Interested reopens it and remembers that card, which is picked
  (`decide('interested')`) as soon as the payment goes through. Payment is
  the same `api.unlockPack()` simulation as `/unlock`, which stays for
  top-ups.
- `ModalDialog.vue` is the generic native-`<dialog>` wrapper (`open`,
  `title`, `close-label`, emits `close`) — same mechanics as
  `ReviewsDialog.vue`.

### Mobile burger menu (`AppHeader.vue`)

Below `lg` (1024px — `DESKTOP_QUERY` in the component must match
`breakpoints.$lg`; it was `md`/760px until "My trips" made a guest's row 7
items) the nav collapses to the logo plus a burger button; the
links open in a drawer that slides in from the right over a dimmed
backdrop. **It's one `.drawer` element styled two ways, not two copies of
the nav:** mobile-first CSS makes it a `position: fixed` off-canvas panel,
and `@include bp.lg` resets it to the old inline row. Add new nav items
once, inside `.drawer > ul.nav`.

- **Log out's position is a requirement, not an accident**: it is always the
  *last* item in `ul.nav` (`AppHeader.spec.ts` asserts that), which puts it
  at the foot of the mobile drawer and at the far right of the desktop bar.
  The `account` class carries it: `margin-top: auto` plus `position: sticky;
  bottom: 0` (on the drawer's `--rg-surface` background) so it stays on
  screen even when the links overflow a short viewport, and `margin-left:
  auto` with `position: static` in the `bp.lg` block for the desktop row.
  Keep the desktop block's `position`/`background` resets if you touch the
  mobile rule — without them the drawer's sticky/opaque styling leaks into
  the top bar.

- **`.topbar`'s blur is on `::before`, not on `.topbar` itself.**
  `backdrop-filter` makes an element the containing block for its
  `position: fixed` descendants, so on `.topbar` it would trap the drawer
  and backdrop inside the 60px header. Don't move it back.
- Closed-state `visibility: hidden` (delayed until the slide-out finishes)
  keeps the off-screen links out of the tab order.
- While open: `body` scroll is locked, focus moves into the drawer and Tab
  cycles inside it. Escape or the ✕ closes it and puts focus back on the
  burger. The backdrop, any link click (even to the current route), a route
  change, or widening past `lg` also close it.
- A "Signed in as …" line shows only in the drawer. "Matches" (`/matches`)
  and "My trips" (`/trips`) sit before "Profile" whenever `isAuthenticated`.

### Match management (`MatchesPage.vue`, `/matches`)

One page for both roles, fully server-backed. It loads `api.matchOverview()`
(`Selections::getOverview()`) and, for guests, `api.paymentState()`, then
sorts every pair into four tabs:

| Tab | Condition | Actions |
| --- | --- | --- |
| Matched | `active = 1` and the latest booking isn't `confirmed` (nothing booked, or a payment waiting on the guide) | paying and accepting/declining are `BookingPanel.vue` (see "Guide fees & bookings"); **Unmatch** → `revokeSelection` unless a booking is pending |
| Likes you | they're `interested`, you haven't decided | **Accept** → `decide('interested')`, **Pass** → `decide('pass')` |
| Awaiting reply | you're `interested`, not active | **Withdraw** → `revokeSelection` (gives a guest their pick back) |
| Passed | you passed | **Reconsider** → `decide('interested')` |

- It opens on Matched if there are any matches, otherwise on "Likes you" if
  anyone is waiting on an answer; once the user clicks a tab, that choice
  sticks. Every action re-fetches the overview rather than patching rows
  locally. Matched rows carry a **Message** button (`/messages/:id`).
- **What the server hides:** `their_decision` is only ever `'interested'`
  or `null`, so a pass from the other side looks like "no answer yet". A
  pair where they passed and you never decided isn't returned at all.
  No contact details, ever — see "No contact details".
- Guests: Accept and Reconsider each cost a pick (enforced server-side by
  `Selections::decide()`). With `selectionsLeft === 0` those buttons turn
  into "Unlock…" links to `/unlock`. Every guest action is mirrored into
  `matchesStore` (`pick`/`pass`/`remove`), so the header's pick counter and
  the `/discover` deck stay consistent within the session.
- **A pair whose latest booking is `confirmed` isn't on this page at all**
  (`isTrip()`) — upcoming, under way or finished, it's a trip and lives on
  `/trips`; the toolbar shows "N confirmed trips · My trips". A free guide's
  match is confirmed the moment it forms, so it goes straight there. "Book
  again" after a finished trip is on the trip card; the new payment brings
  the pair back here as pending until the guide accepts.
- This is where a guide finds a match the visitor completed (see the gap
  under "Progress so far"). The Discover page's "View matches" links and
  the header's picks-pill lead here.

### My trips (`TripsPage.vue`, `/trips`)

Every booking that was ever confirmed, for both roles (`api.trips()` →
`Bookings::tripsFor()`: `confirmed`, or `cancelled` with `accepted_at` set —
pending/declined/withdrawn requests aren't trips). Three tabs by the row's
server-computed `phase`:

| Tab | `phase` | Means |
| --- | --- | --- |
| Scheduled | `scheduled` | confirmed, `trip_status = 'pending'` (a free trip with no date stays here until finished) — soonest first |
| In progress | `current` | confirmed, `trip_status = 'started'` |
| Past | `past` | finished, or cancelled |

- Opens on In progress, else Scheduled, else Past; a clicked tab sticks.
- Each card: the counterpart's name linking to `/guides/:id` or
  `/visitors/:id`, date, duration, location, price ("£X paid"/"£X fee",
  "Free", "· £Y refunded"), a **Message {name}** button while still
  matched, the cancel reason on a cancelled trip, a review link on a
  finished one. Missing values read "Date to be arranged" / "Length not set"
  / "Meeting place not set yet".
- Actions are the same `BookingPanel` as on Matches, with `:summary="false"`
  (the card shows the date/price itself) and `:allow-booking="is_latest"`
  (only the pair's most recent trip offers "Book again"). Cancelled trips
  get no panel.
- **Cancelling a trip dissolves the match on both sides** (both decisions
  back to `NULL`) — guide cancel, an approved guest request, *and* a guest
  cancelling a free trip (`Bookings::cancelFreeByGuest()`, which used to
  clear only the guest's side). So each is back in the other's discovery
  deck, gone from `/matches`, and the trip stays under Past. Every load also
  re-syncs a guest's `matchesStore` from `api.paymentState()`, since a cancel
  hands the pick back.
- `TripRow` reuses `MatchOverviewRow`'s booking field names
  (`booking_status`, `booking_amount`, …) — the shared subset is the
  `BookingView` type `BookingPanel` takes — but `id` is the *booking's*.
- Date/money formatting for both is in `src/utils/bookingTime.ts`.

### Guide fees & bookings

A booking is what a guide sells: a confirmed date the guide has committed
to (`bookings`, `Bookings.php`). Contact details are never part of it — the
pair plans everything in Messages (see "No contact details"). All of it is driven from `BookingPanel.vue`, which sits
under every row on `/matches`' Matched tab (until the booking is confirmed)
and under every non-cancelled card on `/trips` (after), and emits `changed`
after each step so the page re-fetches.

**Lifecycle of a paid booking** (guide with `user_profiles.price_amount > 0`):

| Status | How it gets there | What each side can do |
| --- | --- | --- |
| *(none)* | matched, nothing booked | guest: **Pay £X** — meeting date-time + optional trip length (0.5–24 h) + optional meeting place → `payGuideFee` |
| `pending` | guest paid | guide: **Accept** → `confirmed` (a trip) or **Decline** (optional reason) → `declined`, full refund. Guest: **Withdraw payment** → `withdrawn`, full refund. Nobody answers before the meeting time → `sweep()` declines it, full refund. The match survives all of these, so the guest can try another time. |
| `confirmed` | guide accepted | guide: **Cancel booking** → `cancelled`, fee refunded **+5% if late**. Guest: **Request cancellation** (reason required, only before the trip starts) → guide **Approves** → `cancelled`, refund **fee − 5%**; or **Refuses** → booking stands. Guest can withdraw their request. Either cancel dissolves the match on both sides, which returns the guest's pick. |
| trip `started` | meeting time passed (`sweep()`) | a guide on a started trip is **hidden from the visitor deck** until the trip's end (or 12 h, if no length). |
| trip `finished` | see below | both can review each other; the guest can **Book again**. |

- **Guide acceptance is the point**: the guide commits to a date, so a
  guide can't take a booking and drop it at the last minute without it
  costing them (the late +5%).
- **Late-cancellation extra** (`Bookings::refundAmount()`): the guide's
  cancel refunds the fee **plus 5%** when the meeting is less than 24 hours
  away — or less than **2** hours away if the match is under a day old
  (`selections.matched_at`). `getOverview()` pre-computes it for the guide
  as `refund_if_cancelled`; `refund_if_guest_cancels` is the guest-request
  figure (fee − 5%). The confirm steps show those exact amounts.
- **Finishing a trip**: with both a meeting time and a trip length
  (`auto_finish`), `sweep()` finishes it once `meeting_at + duration_hours`
  has passed and tells both sides to leave a review. Otherwise — a free
  booking, or no length given — either side presses **Mark trip finished**
  and the other **Confirms** (or says **Not yet**, which clears it and tells
  the asker; the asker can **Undo**). Both asking counts as confirming.
- **Free guide** (price 0 or unset — the default): a `confirmed` booking
  opens by itself when the match forms (`Selections::syncActive()` →
  `Bookings::onMatched()`), no payment, no acceptance, no meeting time, so
  it always finishes through the two-sided button. Matches made before
  bookings existed are backfilled silently by
  `Bookings::ensureFreeBookingsFor()`. The guest can cancel a free trip
  ("Cancel trip" on the trip card → `revokeSelection`); the guide is told
  and the match dissolves on both sides.
- **Meeting place** (`bookings.location`, ≤160 chars, free text, and/or a
  map pin `location_lat`/`location_lng`, 043): optional when paying; either
  side can set, change or clear it while the booking is open (`bookingAction`
  op `setLocation` + `location` and/or `latitude`/`longitude` — each call
  replaces both, so send neither to clear), and the other side is notified
  (`request_location` while pending, `trip_location` once confirmed — the
  email carries a Google Maps link to the pin). The pin is dropped like
  chat's "Share my location": "Pin where I am now" in `BookingPanel`'s pay and
  meeting-place forms reads the device once (`composables/useCurrentPosition.ts`,
  shared with chat) and only fills the form — Save sends it. Rows carry it as
  `location_pin: { latitude, longitude } | null` (`Bookings::withPin()`), and
  `MapPinLinks.vue` opens it in the viewer's own maps app (`utils/mapsLink.ts`)
  on the Matches summary line and the trip card; the form control is
  `MeetingPinField.vue`.
- **Unmatching a booked pair** is otherwise refused by `Selections::revoke()`
  — decline/withdraw a pending one, cancel a confirmed one. `BookingPanel`
  and `MatchesPage`'s `canUnmatch()` mirror those rules so the buttons
  don't offer what the server would refuse.
- **One endpoint for every step but paying and the guide's cancel**:
  `api.bookingAction({ targetId, op, reason?, location? })`, `op` one of
  `accept | decline | withdraw | requestCancellation | withdrawCancellation |
  approveCancellation | refuseCancellation | requestFinish | confirmFinish |
  dismissFinish | setLocation`. `RequestProcessor::actionBooking()` checks the caller's
  role per op; `Bookings` checks the booking's state.
- **`Bookings::sweep()`** runs every 30 s in the scheduler service
  (`api/bin/scheduler.php`, behind a Redis lock so only one runs at a
  time): expires unanswered pending bookings, starts trips, finishes trips.
  Requests no longer sweep.
- **Nothing hands out contact details** — not deck cards
  (`Users::searchByRole()`, `ProfileAccount`), public profiles,
  `matchOverview`, `trips` nor any email or notification.
- Nothing is charged or refunded for real (same as pack purchases). Emails
  and notification copy live in `Bookings.php`; times in emails are UTC
  ("… UTC"), in the UI the viewer's own zone.

### Ranking & badges (`Ranking.php`, `StandingBadges.vue`)

Both decks are ordered best-first (`Users::searchByRole()` → `ORDER BY
rank_score DESC, u.id DESC`); the score is computed in the same query and
**never sent to clients** (`Ranking::castRow()` drops it) — they get the facts
behind it as badges instead.

- **Score**: +30 verified identity; reviews as a Bayesian average (5 imaginary
  4.0★ reviews + the real ones, one per *distinct author*) → (avg − 3) × 15;
  5 × the effective level (`Levels`: finished trips counting each *other
  person* once, minus sanctions); +5 photo, +5 bio; +15 for a new account, fading to 0 over 21 days.
- **Anti-farming**: a guest and a free guide (or one person with two
  accounts) matching, finishing, reviewing and re-matching gain nothing after
  the first round — trips and reviews both count distinct people. Many sock
  puppets still could; ID verification (and its +30) is the answer to that.
- **Badges** (`ProfileAccount.verified`, `.top_rated` — ≥ 5 distinct
  reviewers averaging ≥ 4.6 — and `.trips_completed`), rendered by
  `StandingBadges.vue` on the deck card (`ProfileCard`'s `standing` prop) and
  both profile heroes, mapped through `utils/standing.ts`. Each badge's
  explanation is screen-reader text as well as a tooltip.
- **Verification** (034): `users.verified_at` + `verification_method`
  (`id_card | passport | driving_licence`). Set by hand for now
  (`UPDATE users SET verified_at = UTC_TIMESTAMP(), verification_method =
  'passport' WHERE id = …`); the automated document + selfie check is the next
  big change. `ProfilePage.vue` shows the status (`myProfile.verified`).
- Indexes for the two derived tables are in 036; `getPublicProfile()` narrows
  them to the one user. A `user_stats` table is the next step if decks grow.

### Levels, fees & bonus packages (`Levels.php`, `LevelPanel.vue`)

- **Level** from *qualifying* trips — confirmed + finished, and either paid
  through RockGuide or with a person whose identity is verified
  (`Levels::QUALIFYING_TRIP`, shared by the level, the trips badge and the
  ranking), each other person counted once — so a ring of fresh accounts
  finishing free trips with each other earns nothing: 0 New · 1 Starter (1) · 2 Explorer (3) · 3 Pathfinder (6) ·
  4 Trailblazer (12) · 5 Pioneer (24) · 6 Legend (48) · 7 Icon (96).
  `users.level` is the highest level announced — it only goes up, so a level
  is announced once, not again when a sanction expires; `Levels::sync()` runs
  when a trip finishes (`Bookings::markFinished()`) and when a review is
  written, and sends `level_up` / `bonus_granted` notifications (the grant's
  reason is in the text, so two same-size grants aren't deduplicated).
- **Sanctions** (`user_sanctions`, by moderators in SQL, optionally tied to a
  complaint, with an expiry) subtract levels — the *effective* level, used for
  every perk and for the ranking (`Ranking` scores 5 × effective level).
  While sanctioned, **bonuses are suspended**: `claim()` refuses and
  `activeBonusUntil()` returns null, so a running no-fee or free-picks bonus
  doesn't apply (`LevelPanel` says so and hides Claim). `myLevel`'s
  `next_level` is earned + 1, not effective + 1. Since the badge shows the
  effective level next to the trips count, a sanction is inferable on public
  cards — accepted.
- **Visitors** get more picks per £5 pack: 5 + level, at most 10, fixed per
  purchase (`payments.pack_size`; capacity is the sum). Levels 2/4/6 grant
  3/7/14 days of **free picks** — unlimited, marked `selections.free_pick`, not
  counted in `picksUsed`.
- **Guides** pay a platform fee on each paid booking, 15% falling to 5% by
  level (`Levels::commissionRate()`), fixed at `Bookings::pay()` in
  `bookings.commission_rate/commission_amount/guide_payout` and shown only to
  the guide (overview/trips rows, `BookingPanel`, trip cards, the email).
  Levels 4/6 and 10/25/50 distinct reviewers averaging ≥ 4.8★ grant
  **no-fee** days (7/30, and 30 each).
- **Bonus packages** (`bonus_grants`, once per reason) are claimed by the user
  on the profile page (`claimBonus`) — a visitor turns free picks on when
  they're travelling. Claiming while one of the same kind runs queues it
  (in a transaction, the user's grants locked).
- Client: `PaymentState.packSize`/`freePicksUntil`/`freePickIds` feed
  `stores/matches.ts` (capacity now comes from the server; `PACK_SIZE` is only
  the base/marketing number; `freePicksActive` re-evaluates itself when the
  bonus ends); the header and deck show "Free picks on" and the Discover
  counter "Free picks until …";
  `StandingBadges` shows "◆ Level n · Name"; on `/profile`, `LevelPanel` is
  just the level badge at the top right of the page header with a "?" beside
  it — hovering the "?" (or clicking it, for touch and keyboard; Escape or a
  click elsewhere closes) opens a popover with the progress, perks, sanction
  and bonuses to claim.
- No money moves: like packs and fees, the commission is recorded, not taken.

### Reporting a user (`ReportUser.vue`, `Complaints.php`)

A quiet "⚑ Report {name}" link at the foot of both public profile pages
(signed in, someone else's profile) opens a form: a title (≤120) and what
happened (≤2000), with counters. `api.reportUser()` →
`Complaints::file()`:

- Validated server-side: text only, control characters stripped, lengths by
  code point, not yourself, the subject must be a guest or guide.
- One open complaint per reporter about the same person, at most 5 a day per
  reporter — both checked under a per-reporter `GET_LOCK`, so a double submit
  can't slip through. Not run through `ContactFilter`: only moderators read
  it, and a number someone was pestered from is evidence.
- Stored in `complaints` (037: reporter, subject, title, description,
  `status` open → reviewing → resolved | dismissed, `resolution`,
  `resolved_at`); deleting either account sets its id to NULL, so a reported
  user can't erase a complaint. No admin screen yet — moderators resolve in
  SQL (the migration has the statement).
- `COMPLAINTS_EMAIL` (env, optional) gets an email per complaint, at most one
  per reported person every 6 hours — counted from `notified_at` (when an
  email actually went), claimed under a per-subject lock. The person reported
  isn't told.
- `ReportUser.vue` calls the API itself (like `ReviewsSection`).

### No contact details — chat instead

RockGuide **never shares a user's email or phone with another user** — not
after a match, not after a confirmed or finished booking. Matched people plan
the trip in Messages (chat, with "Share my location"); `ContactFilter` keeps
contact details out of everything they write. So: `matchOverview` and
`trips` carry no `email`/`phone`; booking emails and notifications point to
Messages instead of listing contacts; Matches' Matched rows and trip cards
offer a **Message** button. Copy that used to promise contacts after booking
was rewritten (039). A user's own email/phone stay on their profile page —
they're for sign-in, 2FA and our notifications only.

### Messages: chat between matched people (`MessagesPage.vue`, `/messages`)

The channel that replaces swapping phone numbers. `api/classes/Messages.php`,
`messages` (030), one row per message tied to the pair's `selections` row.

- **Who**: a guest and guide can write while their match is active; once it
  dissolves (unmatch, a cancelled trip) the history stays, read-only
  (`can_send: false`). `pairOf()` requires the caller to be one of the pair.
- **Every body is masked** (`ContactFilter::mask()`) at any booking stage —
  the conversation stays on the platform; map links are kept. The banner on
  every conversation says so (`messages.thread.stay_banner`).
- **Live, no polling**: `sendMessage` publishes `{ type: 'message' }` to
  both users (the sender's other tabs too; the recipient's copy carries
  `sender_name` for App.vue's "New message from …" toast, skipped while that
  conversation is open). Reading publishes `{ type: 'messages_read',
  reader_id, up_to_id }` to the other side, which moves its "Seen". The page
  patches its list and thread from pushes instead of refetching (the list
  carries photos).
- **Read means seen**: opening a conversation marks read only up to the
  newest message in the response; a live message is marked read
  (`markMessagesRead` with `upToId`) only while the tab is visible, else once
  it's shown again. Pushes that land while a conversation loads are held and
  merged by id; a slow load for a conversation the user already left is
  dropped.
- **Counter**: "Messages" in the header shows unread messages (`Badges`
  `messages`, `Messages::unreadCounts()`), cleared by reading, not by
  `markSeen`.
- **Email**: only to someone not connected anywhere (`Push::toUser()`
  reached 0 gateways) and only for the first message from that person they
  haven't read — never the text.
- Enter sends only with a fine pointer (a touchscreen keyboard's Enter is a
  new line); "Message" buttons sit on Matches' Matched rows and on trip cards
  while matched.
- **Share my location** (032): a message is `kind: 'text' | 'location'`; a
  location stores the point the sender's device reported (`latitude`,
  `longitude`, `accuracy_m`) — never a link — via `shareLocation`. The button
  asks first (inline "Send your current location to …?"), reads
  `navigator.geolocation.getCurrentPosition()` once (needs HTTPS or
  localhost), and is cancelled if the user leaves the conversation before the
  position arrives (a late permission answer never sends it) or after 60 s.
- **The viewer's own maps app**: `src/utils/mapsLink.ts` reads the *viewer's*
  device — Client Hints, else the User-Agent (iPadOS reporting "Macintosh"
  counts as Apple) — and orders the links: Apple devices → Apple Maps
  (`maps.apple.com/?ll=…&q=…`, opens the Maps app) then Google; Android →
  Google Maps' universal link (opens the app) then `geo:0,0?q=lat,lng(label)`
  (any installed maps app); others → Google then Apple.

### Contact filter (`lib/ContactFilter.php`)

RockGuide never shares contact details (see "No contact details"), so
nothing a person writes for others may carry them. `ContactFilter` finds phone numbers (7+ digits in a
run — spelled-out digits in en/es/fr/de/it/pt, any Unicode digits, "O" for
zero, commas/dots/dashes between groups), emails (real, and "at/dot"
spelled out or bracketed), links (lowercase `name.tld`, `www.`, `http`,
`wa.me`/`t.me`), `@handles`, "IG: name"/"insta name_99"-style ids and "add
me on WhatsApp"/"my number is" invitations.

- **Refused** (`assertClean()` → `ContactDetailsException` → `400 { error,
  code: 'contact_details', field }`) where someone describes themselves:
  `updateProfile`'s text fields and every category/option it would add —
  each alone *and* joined (`assertCleanTogether()`; a number split across
  headline+bio or across three interest chips) — checked before anything
  is written; `name` on `updateAccount` and `register`.
- **Masked** (`mask()` → `[•••]`, the rest kept) where someone writes *to*
  someone: review comments, a booking's meeting place, decline/cancel
  reasons.
- **Allowed on purpose**: map links (Google/Apple Maps) and coordinates —
  a meeting point isn't a contact detail; dates, clock times, year lists,
  money, "1.500.000"-style thousands, "9 - 13 / 16 - 20" hours. Plain
  messenger names ("Instagram photography", "Signal: strong") pass; only a
  name plus an id-looking token counts.
- **Fails closed**: invalid UTF-8 or a regex failure is one `unreadable` hit.
- Every hit is logged (`Contact details blocked|masked`) and recorded in
  `contact_filter_hits` (028: user, field, kinds, outcome — never the text).
- Client: `call()` turns `code: 'contact_details'` into
  `common.api.contact_details` in the viewer's language (`ApiError.code`/
  `.field` carry the rest); `ProfilePage.vue` scrolls to the `data-field`
  input, marks it `aria-invalid` and anchors the toast under it. A hint
  under Bio (`profile.details.contact_hint`) says it up front.
- Tests: `docker exec lemp-php php /var/www/localguideapp/lib/tests/ContactFilterTest.php`
  — keep adding real false positives to `$allowed` and bypasses to
  `$blocked`. Spelled-out digits in other languages aren't covered.

### In-app notifications

`notifications` rows (`Notifications::notify()`, which also queues the
email) are what a user hears about while they weren't looking — currently
`booking_requested`, `booking_accepted`, `booking_declined`,
`booking_withdrawn`, `booking_expired`, `booking_free`, `booking_cancelled`,
`cancel_requested`, `cancel_request_withdrawn`, `cancel_approved`,
`cancel_refused`, `finish_requested`, `finish_refused`, `trip_finished`,
`trip_location` (confirmed trip) / `request_location` (pending request),
plus `match` (a new match with a paid guide, sent to the
side that *didn't* make the completing decision — `Bookings::onMatched()`;
for a free guide `booking_free` covers it, to both sides), `level_up` and
`bonus_granted` (`Levels::sync()`, see "Levels, fees & bonus packages") and
`profile_updated` (in-app only, no email — `Notifications::notifyInApp()`,
sent to everyone actively matched with someone who saved their profile or
contact details; an identical line unread or sent in the last 10 minutes
isn't repeated). Copy about a confirmed trip points to "My trips", copy
about a pending request to "Matches". Each row is **published to Redis**
the moment it's written (`Push`, see "Realtime") and marked read if a
gateway took it; a user who isn't connected gets their unread rows on their
next sign-in (`pushState`). `App.vue` shows each
as a 10 s info toast that **links to what it's about** (`utils/notificationLink.ts`):
pending requests → Matches, confirmed/finished trips → My trips, `match` → the
chat with them, `profile_updated` → their profile, `level_up`/`bonus_granted`
→ `/profile#level` (LevelPanel scrolls itself there with its popover open once the page has loaded);
an unknown kind is a plain toast. The person comes from
`notifications.about_user_id` (043, the optional last argument of `notify()`/
`notifyInApp()` — set for `match`, `profile_updated` and the meeting-place
kinds). A chat message's toast opens that conversation. The copy is English only (server text, not in
`translations`). The contact channel is **email only for now** —
`Notifications::deliver()` is the one place to branch on a preferred
channel when SMS/WhatsApp exist.

### Realtime: the API WebSocket

**Every request goes over one WebSocket** to the gateway, and the same
connection carries the server's pushes. The client protocol is unchanged
from when one PHP process did everything; behind it are services (see
"Services"):

- **Requests**: `{ type: 'request', id, action, sessionHash?, ... }` →
  `{ type: 'response', id, status, body }`. The gateway sends account
  actions to auth as they are; for anything else it first asks auth to
  `resolve` the `sessionHash` (sliding it), answers 401 itself if that
  fails, and forwards the request to the API with `X-User-Id` — the API
  never sees a hash. `status` is the service's HTTP status, so `call()`
  handles 401s exactly as before; an unreachable service is a 503. Requests
  run concurrently. A dropped connection fails in-flight requests with
  status 0 (not retried); requests time out after 30 s (services after 25).
- **Identity for pushes**: `{ type: 'auth', sessionHash }` binds the
  connection to a user (`''` unbinds). `App.vue` sends it whenever
  `auth.sessionHash` changes; the socket re-sends it on every reconnect
  (backoff 1 s → 15 s, only while signed in or with requests waiting). The
  gateway re-checks bound sessions every 20 s (`peekMany`, no sliding), so
  an expired session stops getting pushes.
- **Pushes** go through Redis pub/sub, channel `user:<id>`: the API
  publishes `{ type: 'notification', notification }` as each row is written,
  and `{ type: 'badges', matches, trips }` once per request for everyone it
  touched (`Push::touch()`/`flush()`). Each gateway subscribes for the users
  it holds sockets for and drops a counts message identical to the last.
  On sign-in it fetches `pushState` (unread backlog + counts); after a Redis
  outage it does that for everyone.
- **Menu counters** (`Badges.php`, 020): red bubbles on "Matches" and "My
  trips" in `AppHeader.vue` (beside the label in the mobile drawer, plus a
  dot on the burger), from `stores/badges.ts`. A match counts while it's on
  /matches' Matched tab and the user's `selections.*_seen_at` is older than
  `matched_at`; once its booking is confirmed it leaves that count and
  counts as a trip until `bookings.*_seen_at` passes `accepted_at`
  (unfinished trips only). Opening the page clears it — `api.markSeen()`,
  via `composables/useLiveSection.ts`, which also refetches the open page
  whenever a notification is pushed or its counter goes up.
- Tests: `socket.spec.ts` (the client socket), `api.spec.ts` (through a
  stand-in `WebSocket`, `__tests__/fakeWebSocket.ts`), `App.spec.ts` mocks
  `@/plugins/socket` to fire pushes.

### Real guide/visitor cards, filtered server-side

Discovery-deck filtering runs in SQL, not a JS `.filter()` over an
already-fetched list — see `Users::searchByRole()` under Backend. `src/utils/matching.ts` (the old
client-side `matchesFilters()`) is gone; don't recreate it.

- `src/stores/guides.ts` / `src/stores/visitors.ts` — call
  `api.guides(filters)`/`api.visitors(filters)` (both optional — omit for
  everyone) and map each `ProfileAccount` (see `plugins/api.ts` — the real
  `user_profiles`/attribute-taxonomy data, joined server-side) onto the full
  `Guide`/`Visitor` shape — the taxonomy arrives as two open maps
  (`attributes`, `ranges`) that are copied across untouched, with
  placeholders only for whatever a given account's profile hasn't filled in
  (`age ?? 0`, `bio || "…hasn't added a bio yet."`, `photo` from `image`
  when set else the mock gradient token). State is `byId` (every guide/
  visitor ever fetched, for `find(id)`) plus `currentIds` (just the *last*
  filtered fetch, for `items` — what the deck actually shows) — kept
  separate so a guide picked under one filter stays resolvable by id
  (the Discover matches rail) even after a later, narrower fetch stops including them.
  No `loaded` cache flag — every `load()` call is a real, current fetch;
  callers decide when to call it.
- **These are the one place in the client that calls `useApi()` from
  inside a Pinia store** rather than a page component (every other store
  follows the page-calls-the-api pattern — see `RegisterPage.vue` below) —
  because the fetched-and-mapped list is shared by the deck and its
  matches rail (`hydrateDecided()`). The call is made **once,
  synchronously, in the store's `defineStore(() => {...})` setup body**
  (`const api = useApi()` at the top), *not* inside `load()` itself: Vue's
  `inject()` only works while a component instance is "current", which
  holds for the store's first creation (triggered by a component's
  synchronous `<script setup>` calling `useGuidesStore()`) but does **not**
  hold inside `load()` when it's invoked later from a `setTimeout` —
  which is exactly what `DiscoverPage`/`GuideDiscoverPage`'s debounced
  filter-refetch does. Calling `useApi()` fresh inside `load()` instead
  broke silently in exactly that path (`"useApi() was called without the
  api plugin installed"`, only on the debounced call, not the initial
  `onMounted` one) — caught by testing this in a real browser, not by
  type-checking or unit tests. If you add another store like this, capture
  `useApi()` the same way, once, in the setup body.
- `DiscoverPage.vue`/`GuideDiscoverPage.vue`: an initial `guidesStore.load(filters.value)`
  in `onMounted()`, then a `watch(filters, ..., { deep: true })` calling
  `guidesStore.load()` again through `src/utils/debounce.ts` (350ms) for
  every later change — debounced because a range category's sliders fire on
  every tick of a drag, and each call is a real network request now. Their
  empty-state templates distinguish three cases: still loading
  (`loading && !initialLoadDone`), genuinely nothing in the system yet
  (`initialLoadDone && items.length === 0 && filtersStore.isPristine`), and
  the generic "loosen your filters / nobody left" message (everything
  else, including filtered-to-zero and all-decided) — keep that split if you
  touch those templates. It's **four** cases now: the "nothing in the system
  yet" branch is guarded by `auth.isAuthenticated` and preceded by a
  "Nobody new right now" branch for signed-in users, because the server hides
  cards the caller already decided on (see "Decided cards stay out of the
  deck"), so an empty pristine fetch usually means "you've worked through the
  deck", not "nobody has registered". The signed-out branch keeps the
  original copy, where an empty result really does mean an empty system.
- **`GuideDiscoverPage.vue` uses the exact same one-card-at-a-time deck and
  `ProfileCard`/`DecisionRow` accept/reject buttons as `DiscoverPage.vue`**
  (same default labels, "✕ Reject"/"♥ Interested" — no custom overrides),
  just without that page's pack/paywall framing: no `hasAccess`/`canPick`
  gate, no `SelectionCounter`/unlock CTA, since guides never pay and never
  run out of swipes. The header `PillBadge` instead reads
  `"{{ interested.length }} interested"`, and the right-hand rail mirrors
  `DiscoverPage.vue`'s matches rail ("Interested in" / "Mutual interest
  opens a chat.") — same shared three-column `.discovery-layout`
  both pages use. (An earlier version of this page was a multi-select grid
  with no accept/reject buttons at all — replaced because the visitor and
  guide sides are meant to feel like the same interaction, just without the
  payment gate on the guide's side.)
- **Both `DiscoverPage.vue` and `GuideDiscoverPage.vue` now call
  `api.decide()`** from their `accept()`/`reject()` handlers — previously
  neither did (this file used to say `decide()` "isn't called from any page
  yet"), which is why this only became possible once both pages read real
  ids from `stores/guides.ts`/`stores/visitors.ts` instead of the old mock
  decks. `DiscoverPage.vue`'s `accept()` still gates through
  `matchesStore.pick()`/`canPick` first (the local, client-only pack state —
  see "Domain model" below) and only calls `decide()` if that local pick
  actually went through (`matchesStore.isPicked(id)`), so a guest out of picks
  never records a decision they didn't really make; `reject()` isn't gated
  (a pass never costs anything). Both pages treat a `decide()` failure as
  best-effort — a toast via `useMessagesStore()`, no rollback of the local
  swipe-past-this-card state — since the deck's own local
  `interested`/`passed` (or `matchesStore.picks`/`.passed`) arrays are what
  actually drive "what's left in the deck," not anything fetched back from
  the server. A reload still resets that in-memory list, but the deck no
  longer re-serves those cards: `Users::searchByRole()` drops anyone the
  signed-in caller has already decided on (see "Decided cards stay out of
  the deck" below).
- **`GuideDiscoverPage.vue`'s `accept()` is also where the "it's a match"
  notification lives** — `api.decide()` returns the `selections` row
  (`SelectionRow`, `active: 0 | 1`); `active === 1` means this decision was
  the one that completed a mutual `'interested'` (see
  `Selections::decide()`/`syncActive()` under Backend), and triggers
  `messages.success(...)` telling the guide to say hello in Messages and
  that the trip lands on My trips once the visitor has paid (straight away
  for a free guide) — see "Guide fees & bookings". This only fires on the decision that *completes*
  the match; if the guest is the one who completes it (already had this
  guide's `'interested'` recorded, then the guest swipes right), the guide
  gets no toast — they'll only see it on `/matches` (`MatchesPage.vue`).
- The ★ line (`GuideProfilePage`/`VisitorProfilePage` heroes,
  `DiscoverPage`'s card meta line, all via `utils/reviews.ts`'s
  `formatRating()`) is the real review average and count
  (`ProfileAccount.rating_average`/`review_count` → `Guide.ratingAverage`/
  `reviewCount`), shown only when `reviewCount > 0`. It used to read
  `user_profiles.rating`/`tours`, which anyone could set on themselves
  through `updateProfile` — see "Reviews".
- A guide/visitor can now fill in their own profile — see "Self-service
  profile editing" below — so most fields on a `Guide`/`Visitor` card are
  no longer permanently placeholder text for accounts created through the
  app itself.
- `src/components/ImageCropper.vue` — the photo picker: choose a file, pan
  by dragging, zoom with a slider, all inside a round `200px` viewport; no
  separate "confirm crop" step — `getCroppedDataUrl()` (via `defineExpose`)
  renders whatever's currently shown to a `480×480` JPEG data URL on
  demand. **`ProfilePage.vue` is the only place this renders** — sends the
  result as `image` on `api.updateAccount()` only when a new photo was
  actually cropped (`getCroppedDataUrl() ?? undefined` — omitting the key
  rather than sending `null`/`''` leaves the existing photo alone; see
  `RequestProcessor::actionUpdateAccount()`'s `array_key_exists('image', ...)`
  check). `RegisterPage.vue` does **not** render this component and never
  sends `image` on `api.register()` — adding a photo during registration
  (before the account exists) showed "Add a photo" and its caption on the
  very first screen, ahead of the account even being created, which read as
  more friction than the flow needed; a fresh account now always starts
  with no photo, and the photo step lives entirely on the profile page,
  reachable right after signing up. `RegisterPayload.image` stays optional
  in `plugins/api.ts`/`RequestProcessor::actionRegister()` — nothing
  requires removing that field, `RegisterPage.vue` just no longer sends it.

### Decided cards stay out of the deck

`Users::searchByRole()` takes the caller's id (`RequestProcessor::
optionalUserId()`, from the session hash `call()` already attaches) and
excludes every candidate that user has an existing decision on —
`guest_decision`/`guide_decision` not null, so both `'interested'` and
`'pass'` — plus the caller themselves. Before this, a matched or passed
guide came straight back into `/discover` on the next reload, because the
only record of "already seen" was the client-side `stores/matches.ts`,
which doesn't survive one.

- **Someone who liked *you* but whom you haven't answered is still shown**
  — that's how a match gets completed from the deck. Only your *own*
  decision removes a card.
- **Anonymous callers see everyone**, exactly as before — the exclusion
  needs a session, and `guides`/`visitors` still work without one.
- **`includeDecided: true` opts out**, for `stores/guides.ts`'s
  `hydrateDecided()`, which pulls already-picked guides into the id cache so
  the Discover matches rail can name them after a reload. A page that needs one specific person by id should use
  `api.publicProfile()` instead, as both profile pages now do.
- Filters still apply in both modes; `includeDecided` only governs the
  already-decided exclusion.
- A passed card is gone from the deck for good now — "Reconsider" on
  `MatchesPage.vue` is the way back.

### Self-service profile editing

`ProfilePage.vue` (route `/profile`, linked from `AppHeader.vue` when
signed in) is the account/profile editor the "no profile-editing UI
exists" gap used to point at — a guide or visitor can now fill in their
own contact info, security settings and interests through the app itself,
not just via a seeded API call.

- Three independent sections, each its own save action, mirroring three
  separate backend concerns:
  - **Change password** → `api.changePassword()` → `Users::
    verifyPasswordFor()` + `setPassword()` (`creds`), gated on the current
    password and a client-side "do the two new-password fields match"
    check before the request is even sent. **Nested inside the "Personal &
    contact info" panel, at the very top, behind a collapsed native
    `<details>`** (`<summary>Change password</summary>`, closed by
    default) rather than its own top-level panel — it's still a fully
    independent `<form>`/save action, just visually tucked away since it's
    reached far less often than the fields below it.
  - **Personal & contact info** (name, email, phone + country code, 2FA
    delivery channel, photo) → `api.updateAccount()` → `Users::
    updateAccount()` (the `users` table).
  - **Profile details** (date of birth, gender, headline/bio, guide
    pricing or visitor party/duration, the dynamic category/option picker
    below, and — for guides — the hours they work, via `RangePicker`) → `api.updateProfile()` → `Profiles::
    setProfile()` / `setAttributeValues()` / `setRanges()`
    (`user_profiles` and friends).
  - **DOM order of the three `<form>`s is password → account → profile
    details**, matching `ProfilePage.spec.ts`'s `forms(wrapper)` helper
    (`findAll('form')[0/1/2]`) — the two must stay in sync if either
    changes. This has already broken silently once (a different edit
    briefly reordered the *panels* to account → password → details, before
    password moved inside the account panel entirely) — 8 unrelated-
    looking tests failed (wrong panel's inputs/buttons resolving under
    `forms().details`/`.password`) with no change to the test file itself;
    caught by noticing the failures persisted after reverting an unrelated
    CSS change, then diffing the template against the expected order. The
    `<details>` being closed doesn't hide its `<form>` from
    `@vue/test-utils` queries (jsdom doesn't apply the browser's
    collapsed-`<details>` layout, so the tests can still `setValue`/
    `trigger('submit.prevent')` on it directly, same as before the move).
- On mount, loads `api.myProfile()` (the full self-view — see
  `Users::getFullAccount()` under Backend) and `api.attributeCategories()`
  in parallel, then prefills every form field, the `localCategories` copy,
  and the `selectedAttributes` reactive map (`{ [categoryKey]: string[] }`)
  the interest picker reads/writes.
- **Phone is one combined string in `users.phone` (e.g. "+350 56002731") —
  the country-code split only exists in this component.** `splitPhone()`
  matches the longest known `COUNTRY_CODES` (`src/data/countryCodes.ts`)
  dial code that prefixes the stored string on load (falls back to an
  unselected code + the raw string in the number field if nothing
  matches); `saveAccount()` concatenates the picked code and the typed
  number back into one string before sending. The country-code field
  itself is an `AttributeCombobox` with `allow-create="false"` — there's
  nothing to create, just search.
- **Two-factor delivery is a required choice, not an optional one**: the
  `<select>` has no blank/"don't use" option among its real choices
  (`email`/`sms`/`whatsapp`) and is marked `required`, so the browser
  blocks submission until one is picked (confirmed live — clicking Save
  with nothing chosen focuses the select and sends no request). A legacy
  account whose `two_factor_method` is
  still `'none'` (the column's DB default, pre-dating this requirement —
  see `008_profile_updates.sql`) loads with the select simply unselected
  rather than offering `'none'` as a choice, forcing a real pick on next
  save. `RequestProcessor::actionUpdateAccount()` enforces the same rule
  server-side. **Only email can be picked for now**: SMS/WhatsApp are
  `disabled` "(coming soon)" options and the server rejects them; a stored
  non-email value loads unselected. The choice is live — see "Two-factor
  login & password reset".
- **Gender is a dropdown, male/female (or blank for "prefer not to say")** —
  a human constant, so it stays a `user_profiles` column and a `<select>`
  rather than becoming a category. It's the only dropdown left in either
  the profile's category area or the discovery filter bar.
- **Date of birth is a native `<input type="date">`** (a real calendar
  picker), not a bare number — `dateOfBirth` (ISO `YYYY-MM-DD`) replaces
  the old `age` field throughout; see "Dynamic profile attributes" and
  `date_of_birth` under Database state for how discovery-deck age
  filtering still works off of it.
- **The interest/language/food-allergy picker shows a row only for
  categories with at least one selected value** (chips + a "+" to add
  more), plus "add a new category" as its own control at the very bottom
  of the section — an empty category has no row and isn't visible until
  the user actively reaches for it via that bottom control (or an
  existing row's own "+"), at which point it shows an autofocused option
  combobox in place of "+" and disappears again if nothing gets added
  before it loses focus. Not fixed checkboxes, and not a single shared
  category-then-option combobox. See "Dynamic profile attributes" below
  for the full mechanics (search, autocomplete, per-row expand/collapse,
  and creating a brand-new category/option inline).
- Guide vs. visitor fields are toggled with `isGuide = profile.role ===
  'guide'` (headline/price*/includes and the `range` categories for guides,
  party/duration for visitors; date of birth/bio/categories for both — a
  guide sees each category's `provider_label` where it has one, so
  "Interests" reads as "Specialities") —
  there's no rating field at all — a rating only comes from other
  people's reviews (see "Reviews"); `updateProfile` no longer accepts
  `rating`/`tours`.
- Numeric/enum/date fields (`dateOfBirth`, `gender`, `priceAmount`,
  `durationHours`) are omitted from the `updateProfile` payload entirely
  when unset, rather than sent as `0`/`''` — same "omit to leave alone"
  contract `Profiles::setProfile()` already required (see Backend); text
  fields (headline, bio, price label/note, includes, party) are always
  sent, since an empty string is a valid "cleared" value for those.

### Dynamic profile attributes ("list of lists")

**Everything about a profile except the human constants is a category.**
The constants — name, contact details, date of birth, gender — stay
`users`/`user_profiles` columns, because they're facts a person states
rather than tags they pick. Everything else lives in the generic
`attribute_categories` / `attribute_options` / `user_attribute_values` /
`user_attribute_ranges` taxonomy (see `007_dynamic_attributes.sql`,
`010_profile_attributes.sql` and `Profiles.php` under Backend), and **no
category is named anywhere in code** — not in the types, not in the API
layer, not in the SQL filter builder.

A category has a `kind`: `options` (pick values from its list) or `range`
(add one or more numeric spans between `range_min`/`range_max` —
`hour_of_day` is the first). It may also carry a `provider_label`, which is
how one category reads differently on each side of a match: a guest's
"Interests" is a guide's "Specialities", the same options either way. A category (e.g.
"Food allergies") is a labeled group; each category owns its own list of
options (e.g. "Gluten", "Peanuts") a user can pick from — a genuine "list
of lists." Unlike a typical admin-only taxonomy, **a user can grow this
one themselves from `ProfilePage.vue`** — see below.

- `api.attributeCategories()` → `Profiles::getCategories()` returns every
  category with its options — `ProfilePage.vue` keeps a local, mutable
  copy (`localCategories`) it can append to as the user creates things,
  without waiting on a refetch. **Only categories with at least one
  selected value get a row, though** — see `visibleCategories` below;
  `localCategories` itself (not the filtered view) is what the bottom
  "Add another category" search draws its suggestions from, so an
  empty/hidden category is still reachable.
- **`src/components/AttributeCombobox.vue`** is the generic type-ahead
  picker behind this — filters a `{value,label}[]` suggestion list as you
  type, with an optional "+ Create '&lt;text&gt;'" row when nothing matches
  exactly (`allow-create`, default `true`; the phone country-code field
  sets it `false`). Selecting a suggestion uses `@mousedown.prevent`
  rather than `@click` so the input never blurs (and closes the dropdown)
  before the click registers. It also emits `blur` and accepts an
  `autofocus` prop, both added for the per-row expand/collapse behavior
  below — `autofocus` is an explicit `.focus()` call in `onMounted()` via
  a template ref, **not** the native HTML `autofocus` attribute: that
  attribute wasn't reliably stealing focus away from whatever input the
  user had just clicked (e.g. the bottom "add a category" combobox, which
  keeps focus via its own `@mousedown.prevent` right before this one
  mounts) — caught live in the browser, not by unit tests (jsdom doesn't
  model that heuristic).
- **Picking a suggestion (or creating one) does *not* close the dropdown**
  — `choose()`/`createNew()` only `emit('select', ...)`; only `onBlur()`
  (and Escape) actually set `open = false`. This is what lets a user add
  several options to the same category in one sitting without re-clicking
  "+" between each pick: the parent clears `modelValue` in response to
  `select` and passes a narrowed `suggestions` list (excluding what was
  just picked), so the still-open list simply re-renders with what's left.
  A first version closed the dropdown on every `select`, which made adding
  a second option require blurring and re-expanding the row — caught live,
  not by unit tests (the existing tests only asserted the emitted payload,
  never that the list stayed open across a second pick).
- **The suggestion list renders in normal document flow, not
  `position: absolute`** — it pushes whatever comes after it down the page
  rather than floating over it. Since the dropdown deliberately stays open
  across a selection (previous bullet), an absolutely-positioned list sat
  open indefinitely and could visually cover whatever was below it — on
  `ProfilePage.vue` that was the "Save profile" button, so a click aimed at
  it actually landed on the still-open dropdown instead and silently never
  submitted, which is why custom categories/options appeared not to save.
  Confirmed live in the browser (a screenshot showed the open dropdown
  sitting on top of the button, only its rounded edge peeking out below);
  not something a unit test would catch (jsdom has no real layout/paint to
  detect overlap).
- **A category with zero selected values has no row at all — "empty
  categories should not be visible."** `visibleCategories` (what the
  template actually iterates, instead of `localCategories`) filters to
  categories with `≥1` selected value **or** a key present in
  `manuallyExpandedCategories` (a reactive `Set`) — that set is how an
  otherwise-hidden empty category becomes visible: only while the user is
  actively adding to it, whether that's a **brand-new** category just
  created/picked from the bottom "Add another category" control
  (`expandCategory()` adds its key there) or an **existing** empty one
  reached the same way. While visible-because-expanded, the row shows its
  option combobox (autofocused) instead of a "+"; on that input's `@blur`
  (`collapseCategory()`, which removes the key from the set) the row
  either drops back to chips+"+" (if a value was added — no longer empty,
  so it stays visible on its own merits) or **disappears entirely** (if
  still empty — no value, no expanded flag, filtered out again). Emptiness
  isn't re-checked between add and blur, only at blur time, which is what
  lets adding several options in one sitting work before the row can
  possibly collapse mid-interaction.
- **A category that already has ≥1 selected value never disappears** —
  clicking its "+" only ever toggles chips+"+" vs. chips+combobox for
  that row, the same as before this "hide when empty" behavior existed.
  Removing a chip via its own "×" can still empty a category out entirely
  (deleting the key from `selectedAttributes`, per below) — since that
  also removes the last thing keeping it visible, the row disappears the
  same way an abandoned brand-new category would, unless it's currently
  expanded.
- **Picking or creating a category from the "Add another category"
  control at the very bottom of the section** (below every visible row)
  expands that category the same way clicking its own row's "+" would
  (reveals it if it was hidden, per above) rather than opening some
  separate, one-off input — creating one client-side slugifies the typed
  label into a key (`slugifyCategoryKey()`) and records
  `newCategoryLabels[key] = typedLabel`.
- Removing the last chip in a category deletes that key from
  `selectedAttributes` entirely, so an abandoned/emptied category is never
  sent to the server just to have it recreated as empty.
- On save, `attributes` (`{ [categoryKey]: string[] }`) plus
  `newCategoryLabels` go to `api.updateProfile()`.
  `Profiles::setAttributeValues()` creates the category (using the
  supplied label, or the key itself if none was given) and/or any option
  value that doesn't exist yet (value **and** label both set to the
  trimmed text the user typed — there's no separate label input for a new
  option) rather than silently skipping them, the way the old
  `setLanguages()`/`setActivities()` this replaced used to. This is the
  "create new category/option" capability — no admin step, no separate
  endpoint.
- **Every category is filterable, including one a user just invented.**
  `Users::searchByRole()` takes an `attributes` map keyed by category and
  builds one `EXISTS` per entry, so nothing needs wiring up per category —
  the old "`languages` and `interests` are the two the deck reads" rule,
  and the `LEGACY_FIELD_BY_CATEGORY` map that renamed `interests` to
  `activities` on the way to the client, are both gone.
- **Specialities and Interests are one category, not two concepts** — the
  difference is only who's looking: a guide describes what they offer, a
  guest picks what they want, from the same options. `user_specialties` was
  folded into `interests` by 010 and is no longer read; the guide side just
  renders the category's `provider_label`.

### Reviews

Google-Maps-style grades (1–5) with an optional comment of up to 256
characters, which the guest and the guide of a **finished trip** leave
about each other. Backed by `reviews` (013) and `Reviews.php`; replies are
a later feature and would get their own table.

- **Eligibility is a booking, not a match**: the author and the subject
  must be the guest and guide (either way round) of a `bookings` row with
  `status = 'confirmed'` and `trip_status = 'finished'`
  (`Reviews::eligibleBookingId()`). One review per (booking, author);
  with several finished trips the most recent unreviewed one is used, so
  a second trip earns a second review, else the most recent one (whose
  review is then edited). Nothing sets `trip_status` yet — see "Progress
  so far".
- **Every rule is enforced server-side in `Reviews::submit()`**: grade a
  whole number 1–5 (JSON `true` is rejected, not read as 1), comment
  trimmed, ≤256 by `mb_strlen` (an emoji is one character — the client's
  counter uses `Array.from(text).length` for the same reason, and the
  textarea has no `maxlength`, which would count UTF-16 units), empty →
  `NULL`, no self-review, only the author edits/deletes (`delete()` scopes
  by `author_id`, so someone else's id is just "not found").
- **`ReviewsSection.vue`** sits at the foot of both profile pages: one
  `api.reviews({ userId, limit: 3 })` feeds the `ReviewSummary` header,
  the 3 newest `ReviewItem`s, "Show all N reviews" and — when
  `viewer.canReview` — `ReviewForm`. A save or delete just refetches.
- **`ReviewsDialog.vue`** is a native `<dialog>` opened with
  `showModal()` (the browser supplies the focus trap and Escape), styled by
  Pico's own `dialog > article` rules. It fetches 20 at a time
  (`REVIEWS_PAGE_SIZE`, also the server's cap) and "Load more" asks for the
  next page by offset. Every way of closing it ends in the native `close`
  event, after which the section puts focus back on "Show all". jsdom's
  `<dialog>` is partial, so `Reviews.spec.ts` stubs `showModal()`/`close()`.
- **`ReviewForm.vue`**'s star picker is five native radios in a
  `<fieldset>` — radio-group semantics and arrow keys for free, the stars
  are only their labels. Comments are rendered as text everywhere (never
  `v-html`).
- **Timestamps** come back as ISO 8601 UTC (`2026-09-24T10:00:00Z`, built
  from `UNIX_TIMESTAMP()` in PHP) so `utils/reviews.ts`'s `timeAgo()`
  ("2 weeks ago") never has to guess a time zone.
- **The ★ rating everywhere comes from here**: `Users::searchByRole()` and
  `getPublicProfile()` join a per-subject `COUNT`/`AVG` derived table
  (`REVIEW_SUMMARY_JOIN`) into the same query as the list — no per-card
  lookup — as `rating_average` (one decimal, `null` with none) and
  `review_count`.
- **`db.php` connects as `utf8mb4`** (it was MySQL's 3-byte `utf8`) so a
  comment can carry emoji; every table was already `utf8mb4`.

### Discovery filters

The filter bar (`FilterPanel.vue`) has two parts. At the top, **the
person**: gender (a dropdown) and age (one `DualRangeSlider`, two handles,
16–99), always visible. Below, under "Add a filter", **every category as an
accordion row** — a native `<details>` with Pico's own chevron and a count of
picks; open it and the remaining options are full-width link-style rows
(indented, `+` on the right). **Picking an option moves it out of the list
and into a pill above the deck's cards** (`ActiveFilters.vue`, on both
Discover pages, just before the `ProfileCard`); the pill's × removes the
filter and the option reappears in its category. Gender, age and hour spans
show as pills too. **Every `range` category (hours, distance) is one
`DualRangeSlider`** inside its accordion row, like age: a single span,
applied as it's dragged, and dragging back to the full extent clears it — no
Add button. (`RangePicker`, with its several spans, is the profile page's.)
A range category with `allow_unlimited` (048; `distance` only) also gets an
**"Unlimited" switch** (Pico `role="switch"`), on by default with the
slider muted (`DualRangeSlider`'s `disabled`) — no filter at all. Switching
it off stores the full extent and wakes the slider; for such a category,
dragging back to the ends keeps the range — only the switch clears it. The
switch is derived from the store (on ⇔ no span), so the pill's × and Clear
filters turn it back on. **There's
no apply button** — both decks already refetch (debounced) on every filter
change. "Clear filters" (funnel-with-× icon) shows once anything is set.
Accordion state is the browser's own (uncontrolled `<details>`); every value
lives in `stores/filters.ts`, so collapsing never loses one. The bar sits in a `<details>`
on both decks, open from `lg` (1024px) up — where its summary is a plain,
unclickable heading — and closed by default below, where it's a normal
toggle; crossing the breakpoint resets it (`useMediaQuery(LG_QUERY)` in
`src/composables/useMediaQuery.ts`, bound to `:open`). It's built entirely from
`stores/categories.ts`, so it offers whatever the server has, including a
category a user invented on their profile page five minutes ago.

- **Multi-select options everywhere, with one deliberate exception.** Gender
  is a dropdown (Male / Female / No preference) because it's a human
  constant, not a tag list. There are no other `<select>`s, radios or
  checkboxes in the bar.
- **`range` categories in the bar are a single span** (`filtersStore.
  setRange()`); the store and the server still accept several. `hour_of_day`
  runs 00:00–23:00, `distance` 0–50 km. `utils/formatRange.ts` prints them
  ("09:00–13:00", "0–5 km") — the one place category keys are named.
- **`age` is a category in the UI but not in the database.** There's
  nothing to store per user — it's derived from `date_of_birth` — so
  `stores/categories.ts` injects a synthetic `AGE_CATEGORY` (16–99) and
  `plugins/api.ts` peels it back out into its own `ageRanges` request
  field. Everything else in `ranges` goes to the server as-is. The panel
  keeps it out of the accordion and drives it with `DualRangeSlider`
  (`filtersStore.setRange()` — one span); dragging back to 16–99 clears it.
- **Hours match by overlap, and no stored hours means always available.** A
  guide sets their working hours on their profile; a guest asks for the
  hours they want in the filter. A guide who never set any isn't hidden
  from that filter — see `Users::searchByRole()`.
- Filter semantics: OR within a category, AND across categories, so naming
  two categories narrows the deck rather than widening it.
- `stores/filters.ts` holds it all as `{ gender, attributes: { [category]:
  values }, ranges: { [category]: spans } }` — removing the last value in a
  group drops the key entirely, so an empty group never reaches the wire.

### Domain model

- A **guide** is shown to visitors on the visitor discovery deck; a
  **visitor** is shown to guides on the guide discovery deck — **the two
  decks now use the same interaction model** (one-card-at-a-time,
  `ProfileCard`/`DecisionRow` accept/reject — see `GuideDiscoverPage.vue`
  above). The one asymmetry left is payment: visitors pay per pack and are
  capped by it, guides don't pay and never run out of swipes.
- Visitors pay per pack of 5 selections (`PACK_SIZE` in `stores/matches.ts`,
  £5/pack per the prototype's pricing) rather than getting unlimited swipes.
- A swipe produces a `Decision`: `'pass' | 'interested'`. Both sides now
  persist it via `api.decide()` (see "Real guide/visitor cards" above) —
  `DiscoverPage.vue` alongside its local, client-only `matchesStore.pick()`/
  `.pass()` (which is what actually gates a visitor's remaining picks and
  drives the deck), `GuideDiscoverPage.vue` alongside its own local
  `interested`/`.passed` arrays (no pack to gate, so no equivalent
  capacity check before persisting).
- Server-side, a guest/guide pair becomes an active **match** when both
  sides have independently recorded `'interested'` — see `selections` under
  Database state. `GuideDiscoverPage.vue`'s `accept()` surfaces this the
  moment it happens on the guide's own decision (see the note above).
  From then on the pair can chat (`Messages`); contact details are never
  shared — see "No contact details".
  Either side can revoke (a booked pair has extra rules, same section); revoking only clears *their own* side and deactivates the pair, it
  doesn't touch the other side's decision or delete the row, so the pair can
  re-activate later and the revoking user is immediately free to be matched
  by/with someone else.

### Sessions

The app has real server-side sessions, held in **Redis by the auth
service** (`auth/classes/Session.php`: key `session:<sha256 of hash>` with a
300 s TTL re-armed by `GETEX` on each use, plus a `user_sessions:<id>` set
for signing out everywhere; MySQL's `sessions` table was dropped by 023) — the long-standing "there is no session/token auth yet, so
anyone can act as any user id" gap noted throughout this file's history is
closed. A session is a random hash, created by `register`/`login`, that
expires after **5 minutes of inactivity** — a *sliding* window, not a
fixed TTL: any valid use of the hash bumps its expiry forward, so an
active user is never logged out mid-session, but an idle one is.

- `AuthResult` (returned by `register`/`login`/`session`) now includes
  `sessionHash`. `stores/auth.ts` persists the whole `AuthResult` to
  `localStorage` (`rockguide.session`) on `setUser()`, and restores it
  optimistically when the store is first created — this is what makes
  login **survive a page reload** (it didn't before). `sessionHash` is
  exposed as a computed off of it for `plugins/api.ts` to read.
- `plugins/api.ts`'s `call()` is where the hash actually gets used: it
  reads `useAuthStore().sessionHash` and attaches it as `sessionHash` on
  every outgoing request — **no page or store passes it explicitly**,
  unlike the `userId` fields this replaced (which every call site used to
  thread through by hand; see git history if you need the old shape).
  Reading a Pinia store from a plain function like this — not a component
  or another store's setup body — works because `useXStore()` resolves
  through a module-level "active pinia" set once by `app.use(pinia)` in
  `main.ts`, unlike `useApi()`'s `inject()`, which is scoped to whatever
  component is currently rendering (see the `stores/guides.ts` comment on
  why *that* distinction matters) — so this is safe to call from anywhere,
  including from inside a debounced `setTimeout`.
- **A `401` response means the session was missing, unknown, or expired**
  (`AuthenticationException`, see Backend) — distinct from the generic
  `400` an ordinary validation `RuntimeException` gets. `call()` treats a
  `401` as self-describing: it clears `auth`'s local state right there,
  before the error even reaches the calling page. This is *reactive*
  expiry handling — there's no polling timer anywhere; a stale session
  only actually surfaces the moment something tries to use it.
- **`call()` also pushes the shared "session expired" toast right there,
  on every `401`** — `useMessagesStore().sessionExpired()` (guarded against
  re-firing while one is already showing, since several requests can `401`
  at once, e.g. `ProfilePage.vue`'s parallel `Promise.all` on mount).
  Closing that toast — by its 5s auto-dismiss or its own "✕" button, both
  go through the same `dismiss()` — **routes to `/login`**: once signed out
  involuntarily, whatever the current page was showing is no longer
  actionable. Individual pages still have their own `catch (err) { ... }`
  after an `api.*()` call, but route it through
  `src/utils/reportApiError.ts`'s `reportApiError(err, fallback,
  placement?)` rather than pushing their own toast directly — it no-ops for
  a `401` (since `call()` already surfaced it) and only falls back to a
  page-specific `messages.error(...)` for anything else, so a expired
  session never shows two overlapping "something went wrong" toasts.
  `stores/messages.ts` imports the router singleton directly (`import
  router from '@/router'`) rather than `useRouter()`, since `sessionExpired()`
  and `call()` are both plain functions, not component setup code —
  `useRouter()`'s `inject()` needs a "current" component instance the same
  way `useApi()` does (see the `stores/guides.ts` note above), but the
  router instance itself is just an importable module singleton with no
  such restriction on `.push()`.
- `App.vue` additionally does one **proactive** check on boot
  (`api.session()`), only when a cached session exists: a page reload can
  restore a session from `localStorage` that already expired server-side
  while the tab was closed, and this avoids a misleading logged-in-looking
  header until the user happens to trigger an authenticated action. Its
  catch block does nothing on a `401` now — `call()` already cleared
  `auth.user` and pushed the shared toast above, so there'd be nothing left
  to add; any other failure (a network hiccup) leaves the optimistic
  cached session alone rather than logging the user out over it.
- `AppHeader.vue`'s "Log out" button now calls `api.logout()` (destroys
  the session row server-side) before `auth.clear()` — best-effort, since
  clearing local state is what the click actually needs to accomplish;
  the session would otherwise still self-expire in 5 minutes anyway.
- Nothing rotates a session's hash on use, including a password change —
  changing your password doesn't require logging back in (it does send a
  "your password was changed" email). A password *reset* is different: it
  deletes every session for the account (`Session::destroyAllFor()`).

### Acting role (one account, both roles)

An account can hold **guest and guide** (`user_role`) and acts as one at a
time — the plan and the phases left are in `docs/dual-roles.md`. Phase 1,
the plumbing, is in:

- `AuthResult.roles` lists every role (registration role first; `role` is
  kept and still means the first). `stores/auth.ts` exposes `roles`,
  `activeRole` (the last one chosen via `setActiveRole()` if still held,
  else the first; persisted in `localStorage` as `rockguide.activeRole`) —
  **every page reads `auth.activeRole`, never `auth.user.role`**.
- `call()` sends it as `actingRole` on every signed-in request. The gateway
  gets the user's roles from auth's `resolve`, answers 403 for a role they
  don't hold, defaults to their first, and forwards it as `X-Acting-Role`;
  `ServiceHttp` puts it in the context and `RequestProcessor::actingRole()`
  reads it. **The caller's role is never looked up any more** — other
  people's come from `Users::hasRole()`/`getRoles()` (`Roles::of()`).
- The acting role picks the pair: `Selections::decide()/revoke()`,
  `actionBooking()`, and `Messages` (`pairOf($me, $myRole, $other)`) all
  take it, because two people who both hold both roles can have a pair each
  way — separate matches, bookings and conversations. `getOverview()`,
  `tripsFor()` and `conversationsFor()` list only the pairs on the acting
  side; a counterpart's role is the pair's other side.
- `publicProfile`/`myProfile` carry `roles`; `/guides/:id` and
  `/visitors/:id` resolve for anyone holding that role.
- **Adding a role** (phase 2): the header offers a one-role account
  "Become a guide" / "I want to travel" → `/profile?addRole=guide|guest`,
  where a `TermsDialog` (`:slugs="[agreement]"`) shows that role's agreement;
  Accept → `api.addRole({ role, agreementVersion, language })` (auth:
  `AuthProcessor::actionAddRole()` → `Accounts::addRole()` + the acceptance,
  one transaction) → `auth.setUser({ …roles })` → `setActiveRole(role)` →
  the page re-renders as that role and scrolls to its fields
  (`?focus=details`). The profile page also has its own button for it.
- **Mode switch**: a dual-role account gets a Traveller | Guide toggle in the
  menu (`AppHeader.vue`, `.mode-toggle`). Switching swaps the deck route,
  toasts, and re-renders the current page (`App.vue` keys `<RouterView>` by
  `auth.activeRole`); `App.vue` re-syncs a guest's packs on the switch, and
  the picks pill shows only in traveller mode. The profile page says which
  role's profile is being edited.
- **Per role** (phase 3, 052): **headline and description** live on the
  `user_role` row (`Profiles::setRoleText()`; `user_profiles.headline/bio`
  are gone) — `myProfile`/`updateProfile` use the acting role, the decks
  join the candidate's row as `urx`, `publicProfile` takes `role`.
  **Attribute picks**: `attribute_categories.scope` is `shared` (languages —
  one set), `per_role` (interests/specialities, distance, anything users
  create — a set per role) or `guest`/`guide` (food allergies / hours — that
  role only); `user_attribute_values`/`user_attribute_ranges` carry `role`
  (`any` for shared). Reads go through `Profiles::roleRowsSql()` and
  `categoryVisibleSql()`; the profile page and `FilterPanel` (`target-role`)
  offer only the categories that role has. **Ratings**: `reviews.subject_role`
  — `reviews`/`submitReview` take `role`, eligibility is a finished trip
  where the subject was on that side, deck ★ and ranking count that role's
  reviews only, and `ReviewsSection` shows "As a guide" / "As a traveller"
  tabs for someone who is both (the page's own role first).
- **Levels per role, from paid trips** (phase 4, 054): a guide level and a
  traveller level. Only paid, finished trips earn points (`Levels::PAID_TRIP`,
  every one counts — repeat trips too): 1 per trip as a traveller,
  `1 + 0.5 × min(1, fee / £100)` as a guide (`Levels::pointsSql()`);
  thresholds 1, 3, 6, 12, 24, 48, 96 points. `Ranking::tripsJoin($userId,
  $role)` gives `trips_completed` (every finished trip in that role — free
  ones too, the badge), `paid_trips` and `level_points`; a guide with trips
  but no paid one is `volunteer` — "♡ Volunteer" instead of a level
  (`StandingBadges`, `LevelPanel`). `user_role.level` is the level last
  announced per role (`users.level` is gone); `sync()` runs every role held;
  level bonus reasons are `guest_level_N`/`guide_level_N`. Sanctions stay per
  person. `myLevel` reports the acting role's `points`/`next_level_points`.
- **Polish** (phase 5): **menu counters per role** — `Badges::countsFor()`
  returns `byRole.guest`/`byRole.guide` (the side the user is on in each
  pair; messages via `Messages::unreadCountsByRole()`) with the totals on
  top; `stores/badges.ts` shows the acting role's and `otherRoleNews` puts a
  dot on the other mode's button (and the burger). `markSeen` clears only the
  acting role's side. **Notifications carry `role`** (`notifications.role`,
  056 — the recipient's side; every `Bookings` notify passes `role:`,
  `Levels` too), chat pushes carry it as well, and `notificationLink()` /
  `withRole()` add `?as=<role>` to the toast's link; a global
  `router.beforeEach` switches to that role (if held) and drops the query —
  so any link, an email's included, can open the app in the right mode.

### Two-factor login & password reset

Both by email, both in `auth/classes/AccountSecurity.php`, backed by
`auth_challenges` (016). A challenge has a 64-hex-char token (only its
SHA-256 is stored), is spent once `used_at` is set, and dies at `expires_at`.

- **Who gets 2FA**: any account whose `two_factor_method` isn't `'none'` —
  i.e. everyone who has saved the profile page's contact form (the select
  is required). New registrations start at `'none'`, and registering itself
  never asks for a code.
- **Login, step 1** (`login`): a correct password for a 2FA account returns
  a `TwoFactorChallenge` (`twoFactorRequired, challengeToken, method,
  destination` — the masked email — `expiresInSeconds, resendAfterSeconds`)
  instead of an `AuthResult`, and emails a 6-digit code (in the subject
  too). No session exists yet. Starting a new login spends any older one.
- **Step 2** (`verifyTwoFactor`): `challengeToken` + `code` (spaces
  ignored) → the usual `AuthResult`. 10-minute lifetime, 5 wrong codes per
  challenge (the 5th spends it), errors are `400`s — never `401`, which
  `call()` would treat as a logout. `resendTwoFactor` emails a new code
  (old one stops working) after a 30 s cooldown, at most 3 sends.
- **The code email is queued, not sent inline** (high priority, so it
  jumps the outbox). If it can't be delivered after its retries the login
  still shows the code step; the user asks for another code. A 2FA account
  can't log in while mail is broken.
- **`LoginPage.vue`** swaps its password form for a code form on a
  challenge (`isTwoFactorChallenge()`), with a resend countdown; an error
  mentioning "log in again" sends it back to the password step.
- **Reset** (`requestPasswordReset` → `ForgotPasswordPage.vue`,
  `/forgot-password`): always answers `{ ok: true }`, whether or not the
  email has an account; if it does, emails
  `APP_URL/reset-password?token=…`, valid 30 minutes, newest link only,
  at most 3 emails per account per hour (more are silently dropped).
- **`resetPassword`** (`ResetPasswordPage.vue`, `/reset-password?token=`):
  sets the password, spends every open challenge for the account, deletes
  all its sessions *and* trusted devices, emails a "password was changed"
  notice. The page clears the local session too and routes to `/login`.
- **Remembered devices** (`trusted_devices`, 017): the code step has a
  "Don't ask for a code on this device for 30 days" checkbox
  (`verifyTwoFactor` with `rememberDevice: true` → the `AuthResult` also
  carries `deviceToken` + `trustedUntil`). The browser keeps the token per
  email in `localStorage` (`rockguide.trustedDevices`,
  `src/utils/trustedDevice.ts` — kept out of `rockguide.session`), and
  `login` sends it as `deviceToken`. The server checks it only *after* the
  password matched, and only for that account
  (`AccountSecurity::isTrustedDevice()`), so it skips the code, never the
  password. The 30 days are fixed from when it was ticked — using it
  doesn't extend them. Max 10 devices per account (oldest dropped).
  A login that sent a token but still gets a challenge drops the stale token
  locally. `myProfile` returns `trusted_device_count`; the profile page shows
  it with a "Forget all devices" button (`forgetTrustedDevices`). A
  password *change* leaves devices trusted; a *reset* forgets them.
- **`APP_URL`** (env, defaults to `http://localhost:5173`) is
  where email links point. It's read from the server environment, never
  from the request's Origin/Host — those are attacker-controlled and would
  let someone point a victim's reset link at their own site. Set it for
  production.
- Codes and links are never logged. In development, `MAIL_REDIRECT_TO`
  sends them to one inbox (see "Email").

## Services

| Service | Code | Container | Talks to |
| --- | --- | --- | --- |
| gateway | `gateway/bin/gateway.php` | `lemp-ws` (port 8090) | auth + api over internal HTTP, Redis (subscribe) |
| auth | `auth/index.php` (php-fpm) | `lemp-rg-auth`, via nginx `:8082` | MySQL, Redis (sessions), outbox |
| api | `api/index.php` (php-fpm) | `lemp-rg-api`, via nginx `:8081` | MySQL, Redis (publish), outbox |
| scheduler | `api/bin/scheduler.php` | `lemp-rg-scheduler` | MySQL, Redis |
| mailer | `mailer/bin/mail-worker.php` | `lemp-rg-mailer` | MySQL (outbox), Redis (wake-ups), SMTP |

- **Internal HTTP contract** (`lib/ServiceHttp.php`): `POST /` with the JSON
  payload, `X-Internal-Token` (shared secret `INTERNAL_TOKEN`; missing or
  wrong → 403, unset on the service → 503), `X-User-Id`, `X-Request-Id`,
  `X-Client-User-Agent`; `GET /healthz` → 200. The gateway also answers
  `GET /healthz`. Only `/ws` is public on `api.rockguide.com`; the service
  ports aren't published. The gateway accepts browser origins
  `rockguide.com` (+ www/admin/api) and `localhost:5173`; `WS_EXTRA_ORIGINS`
  (comma-separated, exact) adds more, e.g. a second dev server.
- **Gateway-only actions** — `resolve`, `peekMany` (auth) and `pushState`
  (api) — the gateway refuses them from clients (404).
- **Stateless**: nothing but the gateway's open sockets lives in a
  process. Sessions are in Redis, everything else in MySQL, so every service
  can run as several replicas (the scheduler's Redis lock and the mailer's
  `SKIP LOCKED` claims make those two safe to duplicate too).
- **Long-running PHP**: after changing code, restart what runs it —
  `docker restart lemp-ws` (gateway/), `lemp-rg-scheduler` (api/),
  `lemp-rg-mailer` (mailer/). php-fpm (`lemp-rg-api`, `lemp-rg-auth`)
  picks changes up per request.

### Email outbox

Nothing sends SMTP inside a request. `MailQueue::enqueue()` writes a row to
`email_outbox` (022) and pushes a wake-up on Redis `mail:wake`. The mail
worker claims rows (`FOR UPDATE SKIP LOCKED`, highest `priority` first —
login codes and reset links) and sends them. A failed send is retried **up
to three times** (after 20 s, 60 s, 5 min); then the row is `failed` and an
error is logged. A permanent failure (bad address, 5xx reply, no SMTP
config) gives up at once. Subject and body are blanked once a row is done
(they can hold codes/links), and finished rows are deleted after 30 days.

### Logs: Loki + Alloy + Grafana

Every service logs one JSON object per line to stderr (`lib/Log.php`:
`ts, level, service, msg, request_id, action, user_id, …`); PHP warnings
and uncaught exceptions are captured the same way, and SQL in database
errors has its quoted values blanked. Grafana **Alloy** reads the
containers labelled `rockguide.logs=true` through the Docker socket, turns
`level`/`service` into labels and ships them to **Loki** (7-day
retention). **Grafana** at http://localhost:3000 (anonymous admin, bound to
localhost — development only) has Loki provisioned and a "RockGuide logs"
dashboard: requests by status, p95 time per action, errors/warnings by
service, email sent/retried/failed, and log streams. Example query:
`{app="rockguide", service="gateway"} | json | msg="Request" | status >= 500`.
Never log secrets, codes, links or session hashes; mask emails with
`Log::maskEmail()`.

## Docker environment

This app runs inside the `docker-lemp` compose stack (one level up from this
repo, at `../../docker-compose.yaml` relative to here — i.e.
`~/Docker/docker-lemp/docker-compose.yaml`). Relevant services/containers:

- The RockGuide services above (`ws`, `rg-api`, `rg-auth`, `rg-scheduler`,
  `rg-mailer`), all built from `./php`, with `deploy/php/rockguide.ini`
  (errors logged, never displayed) and `restart: unless-stopped`. Their
  settings — DB_*, PEPPER, SMTP_*, MAIL_*, APP_URL, INTERNAL_TOKEN, REDIS_* —
  come from `docker-lemp/php/localguideapp.env`, outside this repo. Never
  commit it. `docker compose up -d ws rg-api rg-auth rg-scheduler rg-mailer`.
- `redis` / `lemp-redis` — Redis 7.4 with AOF and a password
  (`docker-lemp/php/redis.env`); not published to the host.
- `loki`, `alloy`, `grafana` (`lemp-loki`, `lemp-alloy`, `lemp-grafana`) —
  logs, configured from `deploy/observability/`.
- `php` / `lemp-php` — the stack's shared PHP 8.3-fpm (custom image:
  `mysqli`, `pdo_mysql`, `memcache`), used by the other sites; RockGuide no
  longer runs on it, but it's still the handy place to lint (no
  host-installed PHP):
  ```sh
  docker exec lemp-php php -l /var/www/localguideapp/api/classes/Users.php   # lint
  docker exec lemp-php php -v                                        # version
  ```
  The host repo root (`~/Docker/docker-lemp/www/`) is bind-mounted to
  `/var/www` in every container, so this repo is at
  `/var/www/localguideapp` inside them.
- `nginx` / `lemp-nginx` — `../../nginx/conf.d/api.rockguide.com.conf`
  (ports 80/443) now only proxies `/ws` to the gateway; everything else is
  404. `../../nginx/conf.d/rockguide-internal.conf` serves the api (`:8081`)
  and auth (`:8082`) services on the docker network only, with an 8 MB
  body limit (a photo). Reload after editing:
  `docker exec lemp-nginx nginx -s reload`. Requires
  `127.0.0.1 api.rockguide.com` in `/etc/hosts` on the host.
- `mysql` / `lemp-mysql` — MySQL 8.4. The `guideapp` database and
  `localguideapp` user already exist on this server, pre-dating this task:
  ```sh
  docker exec lemp-mysql mysql -u localguideapp -p'…' guideapp
  ```
- `node` / `lemp-node` — where `client/` commands (`yarn dev`, etc.) actually
  run; same `/var/www` mount.

### Email

`Mailer.php` (mail worker only) reads these from the env file:

```sh
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=<the sending Gmail address>
SMTP_PASS=<16-char Google App Password>
MAIL_FROM_NAME=RockGuide
MAIL_REDIRECT_TO=<your inbox>   # development only
```

- Gmail rejects the normal account password over SMTP. It needs an **App
  Password**: turn on 2-Step Verification for the account, then create one
  at myaccount.google.com/apppasswords. Gmail allows roughly 500 emails a
  day, and `From` must be that Gmail address (`MAIL_FROM` defaults to
  `SMTP_USER`).
- `MAIL_REDIRECT_TO` sends every email to that one address instead, noting
  the real recipient at the top of the body — set it in development, where
  test accounts have made-up addresses; remove it in production.
- Delivery problems are in the logs (`{service="mailer"}`) and in
  `email_outbox.last_error`.

## API reference

Every action the services dispatch — `AuthProcessor` (auth/) for
`register`, `login`, `verifyTwoFactor`, `resendTwoFactor`,
`requestPasswordReset`, `resetPassword`, `forgetTrustedDevices`,
`changePassword`, `logout`, `session`; `RequestProcessor` (api/) for the
rest — and the `src/plugins/api.ts` method that calls it: a 1:1 mapping,
always sent as `{ action, sessionHash?, ...payload }` over the WebSocket
(see "Realtime"); the gateway routes it. "Auth" means the action calls
`requireUserId()` and throws a `401` `AuthenticationException` (see
"Sessions" below) without a valid `sessionHash` — everything else either
takes no identity at all or reads one from the payload (`register`/`login`)
directly. Every response is `200` with a JSON body on success; a thrown
`RuntimeException` maps to `400` (`ContactDetailsException` adds `code:
'contact_details'` and `field` to that body — see "Contact filter"),
`AuthenticationException` to `401`, an
unknown `action` to `404`, anything else (a real bug) to `500` (logged via
`Log::exception()`, generic message to the client); an unreachable service
is a `503` from the gateway.

| Action | Auth | Frontend call | Request fields (beyond `sessionHash`) | Response | Backend |
| --- | --- | --- | --- | --- | --- |
| `register` | no | `api.register(payload)` | `email, password, role, termsVersion, language?, name?, image?` — `termsVersion` must be the terms in force | `AuthResult` (`id, email, role, sessionHash`) | `actionRegister()` → `Users::register()`, `Session::create()` |
| `login` | no | `api.login(payload)` | `email, password, deviceToken?` | `AuthResult` (`id, email, name, role, sessionHash`), or a `TwoFactorChallenge` for a 2FA account | `actionLogin()` → `Users::verifyCredentials()`, then `AccountSecurity::startLogin()` or `signIn()` |
| `verifyTwoFactor` | no | `api.verifyTwoFactor(payload)` | `challengeToken, code, rememberDevice?` | `AuthResult` (+ `deviceToken, trustedUntil` when remembering) | `AccountSecurity::verifyLogin()`, `signIn()`, `trustDevice()` |
| `forgetTrustedDevices` | **yes** | `api.forgetTrustedDevices()` | — | `{ ok: true }` | `AccountSecurity::forgetTrustedDevices()` |
| `resendTwoFactor` | no | `api.resendTwoFactor(payload)` | `challengeToken` | `TwoFactorChallenge` | `AccountSecurity::resendLoginCode()` |
| `requestPasswordReset` | no | `api.requestPasswordReset(payload)` | `email` | `{ ok: true }` always | `AccountSecurity::requestPasswordReset()` |
| `resetPassword` | no | `api.resetPassword(payload)` | `token, newPassword` (≥8) | `{ ok: true }` | `AccountSecurity::resetPassword()` |
| `guides` | optional* | `api.guides(filters?, includeDecided?)` | `gender?, attributes?, ranges?, ageRanges?, includeDecided?` | `{ guides: ProfileAccount[] }` best-first, each with `verified, top_rated, trips_completed` (see "Ranking & badges") | `Users::searchByRole('guide', ...)` |
| `visitors` | optional* | `api.visitors(filters?, includeDecided?)` | same filter shape as `guides` | `{ visitors: ProfileAccount[] }` | `Users::searchByRole('guest', ...)` |
| `attributeCategories` | no | `api.attributeCategories()` | — | `{ categories: AttributeCategory[] }` | `Profiles::getCategories()` |
| `myProfile` | **yes** | `api.myProfile()` | — | `MyProfile` (+ `trusted_device_count`) | `Users::getFullAccount()`, `AccountSecurity::trustedDeviceCount()` |
| `updateProfile` | **yes** | `api.updateProfile(payload)` | `dateOfBirth?, gender?, headline?, bio?, priceAmount?, priceLabel?, priceNote?, includes?, party?, durationHours?, attributes?, newCategoryLabels?, ranges?` — every field independent (no `rating`/`tours` since 013) | `{ ok: true }` | `actionUpdateProfile()` → `Profiles::setProfile()`/`setAttributeValues()`/`setRanges()` |
| `updateAccount` | **yes** | `api.updateAccount(payload)` | `name?, email?, phone?, twoFactorMethod?, image?` | `{ ok: true }` | `actionUpdateAccount()` → `Users::updateAccount()` (+ inline email/2FA validation) |
| `changePassword` | **yes** | `api.changePassword(payload)` | `currentPassword, newPassword` | `{ ok: true }` | `actionChangePassword()` → `Users::verifyPasswordFor()`/`setPassword()` |
| `unlockPack` | **yes** (guest only) | `api.unlockPack()` | — | `PaymentState` (`packsUnlocked, capacity, picksUsed, selectionsLeft, pickedIds, passedIds, freePickIds, packSize, freePicksUntil`) — the pack holds `Levels::picksPerPack()` picks | `actionUnlockPack()` → `Payments::recordPackPurchase()` |
| `paymentState` | **yes** | `api.paymentState()` | — | `PaymentState` | `paymentStateFor()` |
| `decide` | **yes** | `api.decide(payload)` | `targetId, decision` (`'interested' \| 'pass'`) | `SelectionRow` (`id, guest_id, guide_id, guest_decision, guide_decision, active, created_at, updated_at`) | `Selections::decide()` |
| `revokeSelection` | **yes** | `api.revokeSelection(payload)` | `targetId` | `SelectionRow` | `Selections::revoke()` |
| `matchOverview` | **yes** | `api.matchOverview()` | — | `{ selections: MatchOverviewRow[] }` (`id, counterpart_id, my_decision, their_decision, active, matched_at, updated_at, name, image, age, headline, price_label, party, duration_hours, fee_amount, booking_id, booking_status, payment_status, booking_amount, meeting_at, location, location_pin, refund_amount, trip_status, refund_if_cancelled` — a pass from the other side reads as `null`; `email`/`phone` are `null` unless active **and** booked) | `Selections::getOverview()` |
| `trips` | **yes** | `api.trips()` | — | `{ trips: TripRow[] }` (booking `id`, `selection_id, counterpart_id, phase, is_latest, name, image, active, fee_amount, booking_status, payment_status, booking_amount, meeting_at, booking_duration_hours, location, location_pin, refund_amount, trip_status, accepted_at, cancel_reason, cancelled_at, cancel_requested_at, cancel_request_reason, finish_requested_by, finished_at, matched_at, auto_finish, refund_if_cancelled, refund_if_guest_cancels` — newest first) | `Bookings::tripsFor()` |
| `payGuideFee` | **yes** (guest only) | `api.payGuideFee(payload)` | `targetId` (guide), `meetingAt` (ISO date-time, future, ≤1 year), `durationHours?` (0.5–24), `location?` (≤160 chars), `latitude?`/`longitude?` (a pin — both numbers or neither) | `Booking` (`status: 'pending'` — waits for the guide; `location_pin`) | `actionPayGuideFee()` → `Bookings::pay()` |
| `bookingAction` | **yes** (role per `op`) | `api.bookingAction(payload)` | `targetId` (the other person), `op` (see "Guide fees & bookings"), `reason?` (decline, requestCancellation — required there — refuseCancellation), `location?`, `latitude?`/`longitude?` (setLocation: replaces text and pin; neither clears) | `Booking` | `actionBooking()` → `Bookings::accept()/decline()/withdraw()/requestCancellation()/setLocation()/…` |
| `cancelBooking` | **yes** (guide only) | `api.cancelBooking(payload)` | `targetId` (guest), `reason?` | `Booking` (`status: 'cancelled'`, `refund_amount` set if it was paid) | `actionCancelBooking()` → `Bookings::cancelByGuide()` |
| `conversations` | **yes** | `api.conversations()` | — | `{ conversations: Conversation[] }` (`counterpart_id, name, image, role, can_send, unread, last_message`) | `Messages::conversationsFor()` |
| `messages` | **yes** | `api.messages(payload)` | `targetId, beforeId?` | `ChatThread` (`counterpart, can_send, has_more, messages` oldest first, ≤50) — the newest page marks read | `Messages::threadWith()` |
| `sendMessage` | **yes** | `api.sendMessage(payload)` | `targetId, body` (≤2000) | `ChatMessage` (masked) | `Messages::send()` |
| `shareLocation` | **yes** | `api.shareLocation(payload)` | `targetId, latitude` (±90), `longitude` (±180) — numbers — `accuracy?` (m) | `ChatMessage` (`kind: 'location'`, `location`) | `Messages::shareLocation()` |
| `markMessagesRead` | **yes** | `api.markMessagesRead(payload)` | `targetId, upToId` | `{ ok: true }` | `Messages::markReadWith()` |
| `markSeen` | **yes** | `api.markSeen(section)` | `section` (`'matches' \| 'trips'`) | `{ ok: true }` — the new counts arrive as a `badges` push | `Badges::markSeen()` |
| `myLevel` | **yes** | `api.myLevel()` | — | `LevelState` (`level, name, trips_completed, next_level_trips, sanction, picks_per_pack, commission_rate, free_picks_until, no_fee_until, bonuses`) | `Levels::sync()` + `stateFor()` |
| `claimBonus` | **yes** | `api.claimBonus(payload)` | `bonusId` | `LevelState` | `Levels::claim()` |
| `reportUser` | **yes** | `api.reportUser(payload)` | `subjectId, title` (≤120), `description` (≤2000) | `{ id, status: 'open', created_at }` | `Complaints::file()` |
| `logout` | no (no-ops on a missing/unknown hash) | `api.logout()` | — | `{ ok: true }` | `actionLogout()` → `Session::destroy()` |
| `session` | **yes** | `api.session()` | — | `AuthResult` (same shape as `register`/`login`, unrotated `sessionHash`) | `actionSession()` |
| `publicProfile` | optional | `api.publicProfile(userId)` | `userId` | `PublicProfile` (a `ProfileAccount` minus `email`, plus `role`; `400` "Profile not found." for an unknown id or a non-guest/guide) | `Users::getPublicProfile()` |
| `reviews` | optional* | `api.reviews(payload)` | `userId, limit? (default/max 20), offset?` | `ReviewsPage` (`reviews: Review[]` newest first, `total, average, histogram` `{ "5": n, …, "1": n }`, `viewer: { canReview, bookingId, myReview }` — all no/null signed out) | `Reviews::listFor()`/`viewerStateFor()` |
| `submitReview` | **yes** | `api.submitReview(payload)` | `subjectId, rating, comment?` | `Review` (`id, booking_id, author_id, subject_id, rating, comment, created_at, updated_at, author_name, author_image`) | `Reviews::submit()` (upsert) |
| `deleteReview` | **yes** | `api.deleteReview(payload)` | `reviewId` | `{ ok: true }` | `Reviews::delete()` |
| `translations` | no | `api.translations(language, scope?)` | `language` (one of the 15 codes), `scopes?` / `except?` (key prefixes, `page` or `page.widget`) | `{ language, strings: { key: value } }` (always an object; `400` "Unsupported language." otherwise) | `Translations::forLanguage()` |
| `legalDocument` | no | `api.legalDocument(slug, language)` | `slug` (`'terms'`), `language` | `LegalDocumentView` (`slug, version, language, title, body`, placeholders filled) | `LegalDocuments::current()` |

\* `guides`/`visitors`/`reviews` work signed out, but personalise themselves when a
session *is* sent: `RequestProcessor::optionalUserId()` returns the caller's
id (`null` with no `sessionHash`), and a hash that's present but invalid
still 401s rather than silently degrading to the anonymous view.

On the client, every one of these goes through `plugins/api.ts`'s single
`call()` function (see "Talking to the API" above and "Sessions" below for
what it does beyond the plain request/response: attaching `sessionHash`,
normalizing every failure to a thrown `ApiError`, and reacting to a `401`).
There is no endpoint without a frontend caller and no `api.*()` method
without a matching backend `case` — if you add one side, add the other in
the same change.

## Backend (services)

Plain PHP, no framework, no Composer, no autoloading, no namespaces —
classes are pulled in with `require_once` and match the style already
established in `db.php`. Each service has a `bootstrap.php` that requires
`lib/bootstrap.php` and its own classes. Entry points: `api/index.php`,
`auth/index.php` (both `ServiceHttp::serve()`), `gateway/bin/gateway.php`,
`api/bin/scheduler.php`, `mailer/bin/mail-worker.php`.

Shared (`lib/`):
- `lib/db.php` — a `db` class wrapping `mysqli` (connect, escape,
  select/insert/update/replace helpers, a basic `WHERE` builder). No
  prepared statements; escaping is manual via `mysqli_real_escape_string`.
  Every method is written against the pre-PHP-8.1 mysqli contract (a failed
  query returns `false`, check it yourself) — `db_Connect()` explicitly sets
  `mysqli_report(MYSQLI_REPORT_OFF)`. A failed connect throws a plain
  `Exception` (→ logged 500), never prints. Credentials come from the
  environment (`DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME`). `writeLog()` now
  goes to `Log::error()` with quoted SQL values blanked;
  `ensureConnected()` is for long-running workers.
- `lib/Log.php` — JSON-lines logging (see "Logs").
- `lib/RedisClient.php` + `lib/Resp.php` — a blocking RESP2 client
  (commands, pipelines, BRPOP) and the shared parser; no extension needed.
  `RedisException` is deliberately not a `RuntimeException`, so its text is
  never shown to users.
- `lib/MailQueue.php` — the outbox producer (see "Email outbox").
- `lib/ServiceHttp.php` — the internal HTTP contract (see "Services").
- `lib/ImageData.php` — the `data:image/...` validator (register,
  updateAccount).
- `lib/LegalDocuments.php` — the terms in force and their acceptance.
- `lib/ContactFilter.php` — keeps contact details out of shared text (see
  "Contact filter"); `configure()`d per request by both processors.
- `lib/AuthenticationException.php` — mapped to `401` by both processors.
- `lib/Roles.php` — SQL for reading a user's role from `user_role`.

Auth service (`auth/classes/`):
- `AuthProcessor.php` — the auth actions plus gateway-only `resolve`/
  `peekMany`.
- `Accounts.php` — `register()` (now refuses any role but guest/guide —
  it used to accept `admin`), `verifyCredentials()`, `verifyPasswordFor()`,
  `setPassword()`, `findById()`, `getRoleName()`.
- `Auth.php` — password hashing. Combines the plaintext password, a
  per-user salt, and a server-wide pepper (`PEPPER`, never stored in the DB)
  via `hash_hmac()`, then bcrypt. Every hash/verify throws without `PEPPER`.
- `AccountSecurity.php` — 2FA codes, trusted devices, password resets
  (emails queued through `MailQueue`).
- `Session.php` — Redis sessions (see "Sessions").

Gateway (`gateway/classes/`): `WebSocketServer.php` (RFC 6455 + watchers +
`/healthz`), `Gateway.php` (routing, binding, pushes), `HttpClient.php`
(non-blocking HTTP/1.0 to the services), `RedisSubscriber.php`,
`StreamWatcher.php`.

Mailer (`mailer/classes/`): `Mailer.php` (SMTP, throws `MailerException`
with a `permanent` flag), `MailWorker.php` (claims, retries, wipes).

API service (`api/classes/`):
- `api/classes/Users.php` — data access for `users`/`roles` as the API
  needs them: `findByEmail()`, `getRoleName()`, `trustedDeviceCount()` (a
  read of an auth-owned table, until the DB is split).
  `searchByRole(roleName, filters, Profiles $profiles, ?int $viewerId)` is
  the filtered guide/visitor listing (`$viewerId` also hides everyone that
  caller already decided on — see "Decided cards stay out of the deck"):
  builds a `WHERE` clause from `user_profiles` (gender, and an age derived
  from `date_of_birth`) plus one `EXISTS` per category named in the
  `attributes` map and one overlap check per `range` category — no category
  is named in the code, so a category a user invented filters like any
  other. It then calls `Profiles::getAttributesFor()` to attach every
  category's values and spans in one batched pass (not one query per user).
  Every filter is optional and permissive when absent: an unset field never
  excludes, an `attributes` entry excludes a candidate with no matching
  value (matching on overlap, not containment), an age span lets a
  candidate with no date of birth through, and a `range` category lets
  through anyone who stored no spans at all — see that method's docblock
  before changing the filter
  logic. `getFullAccount(userId, Profiles $profiles)` is the unfiltered
  single-user fetch `ProfilePage.vue` loads on mount (`myProfile` action) —
  returns the raw `date_of_birth`, not a computed age, since the edit
  form's calendar input needs the actual date; `updateAccount()`,
  `getPhone()`, `verifyPasswordFor()` and `setPassword()` back that page's
  contact-info and change-password saves. `getPublicProfile(userId,
  Profiles)` is the `publicProfile` action: one guest/guide in the
  `searchByRole()` row shape plus `role`, minus `email` — both it and
  `searchByRole()` read `rating_average`/`review_count` from `reviews`
  (see "Reviews").
- `api/classes/Profiles.php` — data access for the filterable profile
  tables: `setProfile()` (upserts whichever `user_profiles` scalar columns
  are given — date_of_birth/gender/headline/bio/price*/includes/party/
  duration_hours (not `rating`/`tours` any more) — via
  `db_InsertUpdate()`; a column absent
  from the input is left alone, there's no way to write a literal SQL
  `NULL` through `db`'s helpers, same tradeoff as `users.image`),
  `getCategories()` (every `attribute_categories` row with its
  `attribute_options`, for `api.attributeCategories()`),
  `setAttributeValues(userId, categoryKey, values, categoryLabel = null)`
  (replace-the-full-set within one category — **creates** the category
  (using `$categoryLabel`, falling back to the key) and/or any option
  value that doesn't exist yet, rather than skipping it, so
  `ProfilePage.vue`'s "add a new category/option" combobox flow can grow
  the taxonomy without a separate admin step; a newly created option's
  `value` and `label` are both just the trimmed text the user typed),
  `getAttributeValuesFor(userId)` (one user's selections across every
  category, keyed by category `key` — what `ProfilePage.vue` prefills
  from), `setRanges()`/`getRangesFor()` (a user's spans within a
  `range` category — replacing the whole set, where an empty list means
  unconstrained rather than unchanged), and `getAttributesFor(int[]
  $userIds)` (batched lookup for a list of
  search results — what `Users::searchByRole()` uses; reads a every category, returned as
  `attributes`/`ranges` maps keyed by category — no per-category special
  cases left).
- `api/classes/Payments.php` — data access for `payments` (pack purchases).
  No real payment gateway: `recordPackPurchase()` inserts a `status='paid'`
  row directly, called by `RequestProcessor`'s `unlockPack` action. Also
  `getPacksUnlocked()`/`getCapacity()` (the sum of each pack's own
  `pack_size`, which grows with the buyer's level — see "Levels").
- `api/classes/Selections.php` — data access for `selections` (guest/guide
  matching — see Domain model above for the semantics). `decide()` upserts
  one side's decision and re-syncs `active`; guards a guest's capacity
  against `Payments` (a *new* `'interested'` costs a pick, re-affirming an
  existing one doesn't); `revoke()` clears the acting side only;
  `getOverview()` lists every pair the user is part of for
  `MatchesPage.vue`, with the masking described under "Match management"; `getPicksUsed()` feeds `RequestProcessor::paymentStateFor()`.
- `api/classes/Bookings.php` — `bookings`: `pay()` → `accept()`/
  `decline()`/`withdraw()`; `cancelByGuide()`; `requestCancellation()`/
  `withdrawCancellationRequest()`/`approveCancellation()`/
  `refuseCancellation()`; `requestFinish()`/`confirmFinish()`/
  `dismissFinish()`; `sweep()` (the clock-driven transitions);
  `cancelFreeByGuest()`, `onMatched()`/`ensureFreeBookingsFor()` (free
  guides); `refundAmount()` (guide cancel, +5% late) and
  `guestCancelRefund()` (fee − 5%); `openFor()`; `setLocation()`;
  `tripsFor()` (the `/trips` list — see "My trips"). Owns the booking emails'
  wording. See "Guide fees & bookings".
- `api/classes/Notifications.php` — `notify()` (a `notifications` row,
  published through `Push`, + a queued email), `notifyInApp()`,
  `takeUnreadFor()` (the `pushState` backlog).
- `api/classes/Push.php` — Redis publishes to `user:<id>`; `touch()` +
  `flush()` send each touched user's counters once per request.
- `Selections.php` also takes `Bookings` now: `syncActive()` stamps
  `matched_at` and calls `Bookings::onMatched()` on the flip to active;
  `revoke()` enforces the booked-pair rules; `getOverview()` never
  carries contact details.
- `api/classes/Complaints.php` — `file()`: one user reporting another
  (see "Reporting a user").
- `api/classes/Levels.php` — levels, perks, sanctions, bonus packages
  (`sync()`, `stateFor()`, `claim()`, `effectiveLevelSql()`); see "Levels,
  fees & bonus packages".
- `api/classes/Ranking.php` — the deck's order and badges as SQL fragments
  (`tripsJoin()`, `reviewsJoin()`, `columns()`, `ORDER_BY`, `castRow()`); see
  "Ranking & badges".
- `api/classes/Messages.php` — chat (see "Messages"): `send()`,
  `conversationsFor()`, `threadWith()`, `markReadWith()`, static
  `unreadCounts()` (for `Badges`).
- `api/classes/Reviews.php` — data access for `reviews`: `listFor()`
  (a page, newest first, plus `summaryFor()`'s total/average/histogram),
  `viewerStateFor()`, `eligibleBookingId()`, `submit()` (validates and
  upserts), `delete()`. See "Reviews" above.
- `api/classes/RequestProcessor.php` — the API service's dispatcher:
  `process($input, $context)` with the caller's id from the gateway
  (`requireUserId()`/`optionalUserId()` read it — no session lookups here),
  plus the gateway-only `pushState`. Add new endpoints as new `case`s in
  `dispatch()` (or in `AuthProcessor` for account actions) and add the
  action to the gateway's `AUTH_ACTIONS` if it belongs to auth. Account
  actions moved to `AuthProcessor`; the paragraph below still describes the
  remaining actions' validation. Photos (`image` on updateAccount, and
  register in auth) go through `ImageData::validate()`: a
  `data:image/(png|jpe?g|webp);base64,...` string under ~2 MB of base64.
  `readFilters()`
  pulls the `Users::searchByRole()` filter shape (`gender`, plus the
  category-keyed `attributes`/`ranges`/`ageRanges` maps and the
  `includeDecided` opt-out) out of the
  request body for the `guides`/`visitors` actions — every key optional;
  those two cases pass `optionalUserId()` as the viewer. `actionUpdateProfile()` maps
  camelCase request keys (`dateOfBirth`, `priceAmount`, `durationHours`, …)
  onto `user_profiles`' snake_case columns (rejecting a `dateOfBirth` that
  isn't `YYYY-MM-DD`) and forwards an `attributes` `{ [categoryKey]:
  string[] }` map plus an optional `newCategoryLabels` `{ [categoryKey]:
  string }` map (see "Dynamic profile attributes" above) and a
  `ranges` map to `Profiles` — every field independent, a request
  can update just one. `actionUpdateAccount()` updates `users` columns
  (name/email/phone/two-factor channel/photo — `phone` arrives already
  fully formed, e.g. "+350 56002731"; any country-code picker is a
  `ProfilePage.vue`-only concern); unlike `Profiles::setProfile()`'s
  fields, `email` (uniqueness) and `twoFactorMethod` need cross-row
  validation, so that lives here rather than in `Users`. `twoFactorMethod`
  must be `"email"`, `"sms"` or `"whatsapp"` — `"none"` is rejected here
  even though it's still a legal *stored* value (see Database state): 2FA
  delivery is a required field on `ProfilePage.vue` going forward, not an
  optional one — and `sms`/`whatsapp` both require a phone on file.
  `actionChangePassword()` requires the correct `currentPassword` (via
  `Users::verifyPasswordFor()`) before setting `newPassword` — the one
  action that re-confirms a credential rather than trusting `userId` alone.
- `api/sql/schema.sql` — `roles` / `users` / `creds` / `cms_users` table
  definitions. Applied to the live `guideapp` database (2026-09-02),
  replacing an older, incompatible `roles`/`users` shape that predated this
  task (safe only because those tables were still empty).
- `api/sql/002_payments_and_selections.sql` — `payments` / `selections`
  tables (2026-09-02). Additive (`CREATE TABLE IF NOT EXISTS`, never touches
  `users`/`roles`/`creds`), unlike `schema.sql` — safe to re-run, and the
  pattern to follow for future schema changes now that `users` holds real
  data (`schema.sql` itself must never be re-run against the live DB again).
- `api/sql/003_user_role.sql` — `user_role` table (2026-09-03), a log of
  role grants (`user_id`, `role_id`, unique per pair) written by
  `Users::register()` alongside `users.role_id`. Same additive pattern
  as 002. Since 046 it's the only record of a role.
- `api/sql/004_users_image.sql` — adds `users.image` (2026-09-03), a
  `MEDIUMTEXT` holding a `data:image/...;base64,...` string (the output of
  `ImageCropper.vue`), not a file path — there's no upload directory or
  static file serving wired up for this app. Not `CREATE TABLE`, so not
  `IF NOT EXISTS`-guarded like 002/003 — MySQL 8.4 rejects
  `ADD COLUMN IF NOT EXISTS` (unlike MariaDB); rerunning this after it's
  applied just errors safely rather than doing anything silent.
- `api/sql/005_profiles.sql` — `user_profiles` (scalar fields), `languages`/
  `user_languages`, `activities`/`user_activities` (both many-to-many,
  lookup tables seeded to match what were then hardcoded client constants —
  both tables were dropped by 007, and those constants by 010), and `user_specialties`
  (freeform guide tags, no lookup table). What `Users::searchByRole()`
  filters against — see Database state below for the full shape.
- `api/sql/006_account_security.sql` — adds `users.phone` and
  `users.two_factor_method` (2026-09-05). No OTP-sending infra exists (no
  SMS gateway, no mail sender) — same "recorded but not wired to a live
  provider" pattern as `payments.status='paid'` in `Payments.php` — so
  `two_factor_method` is a stored preference for a future 2FA
  implementation, not a working feature yet.
- `api/sql/007_dynamic_attributes.sql` — replaces 005's `languages`/
  `user_languages`/`activities`/`user_activities` with the generic
  `attribute_categories`/`attribute_options`/`user_attribute_values`
  tables (2026-09-05) — see "Dynamic profile attributes ('list of
  lists')" above. Dropped the old tables outright rather than migrating
  them: confirmed 0 rows in all three at the time (no editing UI had ever
  existed to populate them). `Users::searchByRole()` and
  `Profiles::getAttributesFor()` were updated in the same change to read
  the new tables, keeping the exact same filter semantics and the same
  `ProfileAccount.languages`/`.activities` JSON field names at the time —
  both of which 010 later removed in favour of the generic
  `attributes`/`ranges` maps.
- `api/sql/008_profile_updates.sql` — three changes (2026-09-05): widens
  `users.two_factor_method` to add `'whatsapp'` (`'none'` stays in the
  enum for legacy rows — see Database state — but is no longer an
  acceptable *choice*, see `actionUpdateAccount()`); replaces
  `user_profiles.age` with `date_of_birth DATE NULL` (confirmed 0 rows in
  `user_profiles` at the time, safe to drop/add outright, same as 007's
  table replacement); and deliberately does *not* touch `users.phone` —
  the country-code split (see "Self-service profile editing" above) is a
  `ProfilePage.vue`-only concern, not a schema one.
- `api/sql/009_sessions.sql` — the `sessions` table (2026-09-05): backs
  `Session.php`'s sliding 5-minute-inactivity expiry — see "Sessions" above
  for the full feature.
- `api/sql/010_profile_attributes.sql` — finishes what 007 started
  (2026-09-15): `attribute_categories` gains `kind` (`options`/`range`),
  `provider_label` and `range_min`/`range_max`; `user_attribute_ranges` is
  added for per-user spans; `hour_of_day` is seeded as the first `range`
  category; `user_specialties`' 9 live rows are folded into `interests`
  (labels matched case-insensitively against the existing slugs, the typo
  "hicking" normalised to "hiking" first) and the table left in place,
  no longer read — 011 dropped it; `user_profiles.gender`
  becomes `enum('male','female')`, verified NULL for every row first.
- `api/sql/011_drop_user_specialties.sql` — drops `user_specialties`
  (applied 2026-09-24, after its pre-check confirmed all 9 rows had a
  matching `interests` value).
- `api/sql/012_bookings.sql` — `bookings`, `notifications`, and
  `selections.matched_at` (backfilled from `updated_at` for active rows);
  applied 2026-09-24. `bookings` has no FKs on `guest_id`/`guide_id`:
  MySQL refuses a cascading FK on a base column of a stored generated
  column (`open_pair`), so user deletion cascades through `selection_id`.
- `api/sql/013_reviews.sql` — the `reviews` table (2026-09-24, applied):
  one row per (booking, author), FK to `bookings` with `ON DELETE CASCADE`,
  `rating` checked 1–5, `comment VARCHAR(256)` utf8mb4. See "Reviews".
  Leaves `user_profiles.rating`/`tours` in place, unread — drop them later.
- `api/sql/014_booking_lifecycle.sql` — booking statuses `pending`/
  `declined`/`withdrawn`, plus `duration_hours`, `accepted_at`, the guest's
  cancellation request (`cancel_requested_at`, `cancel_request_reason`) and
  the two-sided finish (`finish_requested_by`, `finish_requested_at`,
  `finished_at`); `open_pair` rebuilt so a pending booking counts as open.
  Applied 2026-09-30. Not re-runnable.
- `api/sql/020_seen_markers.sql` — `selections.guest_seen_at`/
  `guide_seen_at` and the same on `bookings`, for the menu counters
  (`Badges.php`); everything existing marked seen. Applied 2026-10-14. Not
  re-runnable.
- `api/sql/021_translations_counters.sql` — `common.app_header.new_count`
  in all 15 languages. Applied 2026-10-14. Re-runnable.
- `api/sql/022_email_outbox.sql` — `email_outbox` (see "Email outbox").
  Applied 2026-10-14. Re-runnable.
- `api/sql/023_drop_sessions.sql` — drops `sessions` (now in Redis).
  Applied 2026-10-14. Re-runnable.
- `api/sql/024_legal_documents.sql` — `legal_documents`,
  `legal_placeholders` (seeded with labels, no values),
  `user_legal_acceptances`. Applied 2026-10-15. Re-runnable.
- `api/sql/025_terms_2026_10_15.sql` — the terms in 15 languages, generated
  by `api/bin/build-legal-sql.php`. Applied 2026-10-15; regenerated and
  re-applied 2026-10-16 (section 5.1 and the no-contacts wording).
  Re-runnable (upserts).
- `api/sql/027_translations_legal_links.sql` — footer links, cookie notice,
  `common.terms_dialog.*` (moved from `register.terms.*`); drops the old
  footer tagline. Applied 2026-10-15. Re-runnable.
- `api/sql/026_translations_terms_modal.sql` — `register.terms.*`.
  Applied 2026-10-15. Re-runnable. **Apply SQL with a utf8mb4 client**
  (these files say `SET NAMES utf8mb4`, or pass
  `--default-character-set=utf8mb4`): without it non-Latin text is stored
  garbled — it happened once with 026.
- `api/sql/018_translations.sql` — the `translations` table (language,
  key, value; unique per language+key) and its seed: every UI string in all
  14 languages (~9,000 rows), generated from `client/src/i18n/en.json` plus
  the translation files. Applied 2026-10-13. Re-runnable, but the seed
  upserts — re-running resets CMS edits to the file's text.
- `api/sql/019_translations_uk.sql` — the Ukrainian rows (669), same
  format as 018. Applied 2026-10-13. Re-runnable, but it upserts too.
- `api/sql/017_trusted_devices.sql` — `trusted_devices` ("remember this
  device" for 2FA). Applied 2026-10-08. Re-runnable.
- `api/sql/016_auth_challenges.sql` — `auth_challenges`, and every
  `two_factor_method` of `sms`/`whatsapp` set to `email`. Applied
  2026-10-05. Re-runnable.
- `api/sql/015_trip_location.sql` — `bookings.location` (VARCHAR(160),
  nullable), and `accepted_at = created_at` for bookings cancelled before
  014 (back then every booking was born confirmed), so `accepted_at IS NOT
  NULL` identifies a trip. Applied 2026-10-05. Not re-runnable.
- `api/sql/028_contact_filter_hits.sql` — `contact_filter_hits` (see
  "Contact filter"). Applied 2026-10-15. Re-runnable.
- `api/sql/030_messages.sql` — `messages` (see "Messages"). Applied
  2026-10-15. Re-runnable.
- `api/sql/031_translations_messages.sql` — chat UI text. Applied
  2026-10-15. Re-runnable.
- `api/sql/032_message_locations.sql` — `messages.kind`, `latitude`,
  `longitude`, `accuracy_m`. Applied 2026-10-15. Not re-runnable.
- `api/sql/034_verification.sql` — `users.verified_at`,
  `users.verification_method`. Applied 2026-10-15. Not re-runnable.
- `api/sql/035_translations_standing.sql` — badge and verification-status
  text. Applied 2026-10-15. Re-runnable.
- `api/sql/036_ranking_indexes.sql` — covering indexes for the ranking.
  Applied 2026-10-15. Not re-runnable.
- `api/sql/037_complaints.sql` — `complaints` (see "Reporting a user").
  Applied 2026-10-15 (foreign keys changed to SET NULL 2026-10-16).
  Re-runnable.
- `api/sql/038_translations_report_user.sql` — report form text. Applied
  2026-10-15. Re-runnable.
- `api/sql/039_translations_no_contacts.sql` — copy reworded for "no
  contact details, chat instead", the report form's intro and counter.
  Applied 2026-10-16. Re-runnable.
- `api/sql/040_translations_protected_notice.sql` — `common.protected_notice.*`.
  Applied 2026-10-16. Re-runnable.
- `api/sql/041_levels.sql` — `user_sanctions`, `bonus_grants`, `users.level`,
  `selections.free_pick`, `bookings.commission_*`/`guide_payout` (see "Levels,
  fees & bonus packages"). Applied 2026-10-16. Not re-runnable.
- `api/sql/042_translations_levels.sql` — level, perk, bonus and fee text.
  Applied 2026-10-16. Re-runnable.
- `api/sql/043_meeting_pin_notification_links.sql` — `bookings.location_lat`/
  `location_lng`, `notifications.about_user_id`. Applied 2026-10-16. Not
  re-runnable.
- `api/sql/044_translations_meeting_pin.sql` — the meeting pin and
  `common.map_links.*`. Applied 2026-10-16. Re-runnable.
- `api/sql/045_translations_filter_panel.sql` — the age slider's handle
  names (`common.filter_panel.age_min`/`age_max`) and the pill row's
  `common.active_filters.*`; deletes the old Edit/Done/Remove and "Show
  matches" filter keys. Applied 2026-10-18. Re-runnable.
- `api/sql/046_drop_users_role_id.sql` — drops `users.role_id` (with its
  index and FK); roles live only in `user_role`, backfilled first (all 11
  accounts already had their row). Applied 2026-10-18. Not re-runnable.
- `api/sql/047_distance_range.sql` — `distance` (a user-made options
  category with one option, "5 km") becomes a `range` category, 0–50 km;
  the one pick became the span 0–5 km. Applied 2026-10-18. Re-runnable.
- `api/sql/048_category_allow_unlimited.sql` — `attribute_categories.
  allow_unlimited` (sent as `allowUnlimited` by `Profiles::getCategories()`),
  set for `distance`; plus `common.filter_panel.unlimited` in 15 languages.
  Applied 2026-10-18. Not re-runnable.
- `api/sql/049_guide_agreement_2026_10_18.sql`,
  `050_traveller_agreement_2026_10_18.sql` — the role agreements in 15
  languages, generated by `api/bin/build-legal-sql.php`. Applied 2026-10-18.
  Re-runnable (upserts).
- `api/sql/051_translations_dual_roles.sql` — mode switch, add-role and the
  register dialog's new intro, 15 languages. Applied 2026-10-18. Re-runnable.
- `api/sql/052_per_role_profile_data.sql` — `user_role.headline/bio` (moved
  from `user_profiles`, dropped there), `attribute_categories.scope`, `role`
  on picks/spans, `reviews.subject_role` (from the booking). Applied
  2026-10-18. Not re-runnable.
- `api/sql/053_translations_review_tabs.sql` — the review tabs. Applied
  2026-10-18. Re-runnable.
- `api/sql/054_levels_per_role.sql` — `user_role.level`, drops `users.level`,
  role-prefixed level bonus reasons. Applied 2026-10-18. Not re-runnable.
- `api/sql/055_translations_levels_per_role.sql` — Volunteer, level points,
  per-role progress; drops the old trip-count progress strings. Applied
  2026-10-18. Re-runnable.
- `api/sql/056_notification_role.sql` — `notifications.role`. Applied
  2026-10-18. Not re-runnable.
- `api/sql/057_translations_other_role_news.sql` — the mode-switch dot's
  label. Applied 2026-10-18. Re-runnable.
- `api/sql/033_translations_locations.sql` — "Share my location" text.
  Applied 2026-10-15. Re-runnable.
- `api/sql/029_translations_contact_filter.sql` — `common.api.contact_details`,
  `profile.details.contact_hint`. Applied 2026-10-15. Re-runnable.

There is no test setup, linter, or formatter configured for the PHP
services yet; `docker exec lemp-php php -l <file>` is the main check, plus
plain-PHP scripts in `lib/tests/` (exit non-zero on failure). The end-to-end check used for the service split drove real sockets
against throwaway `@example.test` accounts with the mail worker stopped.

### Database state

`guideapp` currently has:

- `roles` — lookup table, seeded with `guest`, `guide` (app-side, `users`)
  and `admin`, `manager`, `viewer` (CMS-side, `cms_users`).
- `users` / `creds` — live app accounts (visitors/guides) + their password
  hashes, wired up end to end (`RequestProcessor` → `Users` → `Auth`).
  `users.image` (added 2026-09-03) holds an optional profile photo as a
  `data:image/...;base64,...` string from `ImageCropper.vue` — `NULL`/`''`
  for accounts registered before that column existed or that skipped the
  photo step. `users.phone`/`users.two_factor_method` (added 2026-09-05,
  see `006_account_security.sql`) back `ProfilePage.vue`'s contact-info and
  2FA-channel fields. `phone` is one combined string (e.g.
  "+350 56002731") — there's no separate country-code column, see
  "Self-service profile editing" above. `two_factor_method` is
  `enum('none','email','sms','whatsapp')` (widened 2026-09-05, see
  `008_profile_updates.sql`); anything but `'none'` means login needs an
  emailed code (016 moved every `sms`/`whatsapp` row to `email`, the only
  channel that works); `'none'` is still the column's
  default for a brand-new registration (the register flow doesn't collect
  a 2FA choice) and can still appear on an account that hasn't visited the
  profile page yet, but is no longer an acceptable value going forward —
  `ProfilePage.vue`'s select requires a real channel.
- `cms_users` — like `users` but with its own `role_id` column (`role_id`/`email`/`name`/
  `created_at`), for the future CMS/admin layer (`admin/`, currently empty).
  **No `cms_creds` table yet** — nothing can log into it until one is added
  alongside whatever builds `admin/`.
- `user_role` — **the** user→role mapping (`user_id`, `role_id`, unique per
  pair); `users` has no `role_id` any more (046). `Accounts::register()`
  writes the one app role (guest or guide) in the registration transaction.
  Read it only through `lib/Roles.php` — `Roles::nameSql()` (a single-row
  subquery, so an extra mapped role can't duplicate a user in a listing)
  and `Roles::hasSql()` (the deck's role filter).
- `payments` — one row per pack purchase (`user_id`, `amount`, `pack_size`,
  `status`), written by `Payments::recordPackPurchase()`.
- `selections` — one row per (`guest_id`, `guide_id`) pair, unique on that
  pair (`db_InsertUpdate()`'s ON DUPLICATE KEY UPDATE is what makes
  `Selections::decide()`'s upsert-without-clobbering-the-other-side work).
  `guest_decision`/`guide_decision` are each `NULL` (no decision yet),
  `'interested'`, or `'pass'`; `active` is a persisted (not just computed)
  1/0, kept in sync by `Selections::syncActive()` on every write.
- `user_profiles` — one row per user (PK is `user_id`, no separate `id`),
  the scalar filterable/display fields: `date_of_birth` (a real `DATE`,
  not a stored age — replaced the `age` column 2026-09-05, see
  `008_profile_updates.sql`; `Users::searchByRole()`'s `ageRanges` filter and
  `ProfileAccount.age` both compute an age from it via SQL
  `TIMESTAMPDIFF(YEAR, ...)` rather than reading a separately-maintained
  number), `gender` (`enum('male','female')` since 010, nullable — a human
  constant, and the one `user_profiles` column the deck still filters on
  directly), `headline`, `bio`, `price_amount`/`price_label`/
  `price_note`, `includes`, `rating`, `tours` (both dead since 013 — see
  `reviews`), `party`, `duration_hours`.
  Guide- and visitor-only columns share the one
  table (like `users` itself already does) — only the columns relevant to
  that account's role get filled in practice. Every column nullable;
  written to by `ProfilePage.vue`'s "Profile details" save (see
  "Self-service profile editing" above) — an account that hasn't visited
  that page yet still has no row here, so placeholders in `stores/
  guides.ts`/`visitors.ts` remain necessary.
- `attribute_categories` (id, `key`, label, `kind`, `provider_label`,
  multi, `range_min`, `range_max`) / `attribute_options` (id, category_id,
  value, label) / `user_attribute_values` (user_id, option_id) /
  `user_attribute_ranges` (id, user_id, category_id, range_start,
  range_end) — the taxonomy every non-constant profile field lives in (see
  `007_dynamic_attributes.sql` and `010_profile_attributes.sql`). Seeded
  categories: `languages` (English/Spanish/Arabic/German), `interests`
  (history/food/nature/family/photography/bars/bro, plus `hiking`/`fwb`
  and anything else users have added), `food_allergies`
  (gluten/peanuts), and `hour_of_day` — the first `kind = 'range'`
  category, 00:00–23:00, holding rows in `user_attribute_ranges` rather
  than option picks. `interests` carries `provider_label = 'Specialities'`,
  which is all that now distinguishes a guide's specialities from a
  guest's interests. **Every** category is filterable — nothing is
  special-cased by key any more.
- `bookings` (id, selection_id, guest_id, guide_id, amount, meeting_at,
  duration_hours, location, location_lat, location_lng (the meeting pin, 043), status `pending|confirmed|declined|withdrawn|cancelled`,
  payment_status `none|paid|refunded`, accepted_at, refund_amount,
  cancel_reason, cancelled_at, cancel_requested_at, cancel_request_reason,
  trip_status `pending|started|finished`, finish_requested_by,
  finish_requested_at, finished_at, created_at, updated_at, generated
  `open_pair`) — at most one open (pending, or confirmed and unfinished) booking per pair,
  enforced by a UNIQUE index on `open_pair`. Date-times are UTC (MySQL,
  PHP and the containers all run in UTC).
- `notifications` (id, user_id, kind, about_user_id — the person it's about,
  043 — message, read_at, created_at).
- `selections.matched_at` — when the pair last became active (UTC).
- `reviews` (id, booking_id, author_id, subject_id, rating, comment,
  created_at, updated_at) — unique on (booking_id, author_id); see
  "Reviews" above.
- `trusted_devices` (id, user_id, token_hash unique, user_agent,
  created_at, expires_at, last_used_at) — remembered devices that skip the
  2FA code; see "Two-factor login & password reset".
- `auth_challenges` (id, user_id, purpose `login|reset`, token_hash unique,
  code_hash, attempts, sends, last_sent_at, expires_at, used_at,
  created_at) — see "Two-factor login & password reset".
- `email_outbox` (id, recipient, subject, body, tag, priority, status
  `queued|sending|sent|failed`, attempts, next_attempt_at, locked_until,
  last_error, created_at, sent_at) — see "Email outbox".
- No `sessions` table any more (023): sessions live in Redis.
- `legal_documents`, `legal_placeholders`, `user_legal_acceptances` — see
  "Terms and conditions".
