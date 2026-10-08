# RockGuide

A Tinder-style app for matching visitors with local guides: visitors swipe
through a deck of guides (and vice versa), pick from a purchased pack of
selections, and unlock contact once matched. Working name in code/prototype
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
revoke-a-match on `ShortlistPage.vue`; a full match-management page for
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
- **A guide who charges a fee isn't notified when a visitor completes the
  match** — only when that visitor then pays (`booking_paid`), or, for a
  free guide, on the match itself (`booking_free`). See "In-app
  notifications" below. The match does show up on `/matches` (or, once
  confirmed, `/trips`) either way; there's no nav badge/count yet.
- **No scheduler**: pending bookings expiring, trips starting and trips
  finishing on their own all happen in `Bookings::sweep()`, which runs at
  the start of every API request. On a quiet server a trip is marked
  finished (and its notifications sent) on the next request after it ended,
  not at the minute.
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
- `api/` — PHP backend. No framework, no Composer — plain scripts + a small
  set of hand-rolled classes.
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
step and the forgot/reset password pages) plus one
plain-module test, `api.spec.ts`, for `plugins/api.ts`'s `call()` itself
(session-hash attachment, 401-triggered auto-logout — see "Sessions"
above; it mocks `fetch` via `vi.stubGlobal` rather than going through a
component). The pattern for a page that calls the API: build a local test
router (routes it actually navigates to/from), a `fakeApi()` stub object
typed as `Api` (see `plugins/api.ts`) provided via `global.provide: {
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
  Discover, GuideProfile, VisitorProfile, Shortlist, GuideDiscover,
  Matches, Trips, Profile, ForgotPassword, ResetPassword, NotFound).
- `src/router/index.ts` — documents the mapping from the original static
  prototype's scroll sections to routes; read the comment there before adding
  routes.
- `src/stores/` — Pinia stores: `filters` (discovery filter state, keyed by
  category rather than by named field — see "Discovery filters" below),
  `categories` (the attribute taxonomy, fetched once and shared by the
  filter bar, the profile editor and every card that labels a tag), `shortlist` (paid selection pack:
  `packsUnlocked`, `picks`, `passed` — in-memory, so a reload drops it;
  `App.vue` re-fetches `packsUnlocked` at boot for guests, but `picks`/
  `passed` genuinely start empty again — see below), `auth` (the signed-in user + session hash, set by
  `RegisterPage`/`LoginPage` and persisted to `localStorage` — see
  "Sessions" below), `messages` (global toast queue — see below),
  `guides`/`visitors` (real discovery-deck data — see "Real guide/visitor
  cards" below). Because `shortlist` doesn't survive a reload the way
  `auth` now does, two places re-sync it from the server: `App.vue` on every
  boot (for a signed-in guest, right after its `api.session()` check — this
  is what makes a purchased pack survive a plain refresh; without it the deck
  showed the paywall and the header read "0 / 0 picked" despite the payments
  existing in the DB), and `LoginPage.vue` right after `api.login()`, so the
  capacity is correct before the first render of `/discover`. Both call
  `api.paymentState()` and feed `shortlist.setPacksUnlocked()`.
  **Picks are restored as well as the pack count**: `paymentState` returns
  `pickedIds`/`passedIds` (guide ids, from `Selections::getDecisionIdsFor()`)
  alongside the counts, and `shortlist.applyPaymentState()` seeds `picks`/
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
  `ShortlistPage`/`GuideProfilePage` were switched to real data from
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

- **`HomePage.vue`** (`/`) — marketing landing page. Hero (`PillBadge`,
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
  `<details>` rail that is closed by default on both decks), `ProfileCard` (the
  swipeable card, which itself renders a `TagList` and a `DecisionRow` for
  its accept/reject buttons), `PillBadge` (the header counter/pill).
  `SelectionCounter` is `DiscoverPage.vue`-only (the pack-remaining strip
  — see "Domain model" for why the guide side has no equivalent). Both
  pages pull their deck from `stores/guides.ts`/`stores/visitors.ts`.
- **`GuideProfilePage.vue`** (`/guides/:id`) — the "Full profile →"
  destination linked from a `ProfileCard`; reuses `PanelCard`, `TagList`,
  `PillBadge`, `DecisionRow` at full width instead of a swipe card. Its
  accept/reject mirror `DiscoverPage.vue`'s: local `shortlist` first, then
  a best-effort `api.decide()` (skipped when signed out). Loads its one
  guide with `api.publicProfile()` (mapped through `stores/guides.ts`'s
  exported `toGuideCard()`), not the guide list, and ends with a
  `ReviewsSection`. A guest's id here reads as "Guide not found".
- **`VisitorProfilePage.vue`** (`/visitors/:id`) — the guest-side
  counterpart, the "Full profile →" target on `GuideDiscoverPage.vue`'s
  card: name, photo, age, gender, party, duration, bio, category tags —
  never contact details — then a `ReviewsSection`. No accept/reject here;
  a guide's id reads as "Visitor not found".
- **`ShortlistPage.vue`** (`/shortlist`) — a visitor's local picks
  (`PanelCard` × N, `TagList`, `PillBadge`) plus the real,
  server-confirmed "Confirmed matches" panel (`api.matches()`/
  `revokeSelection()`).
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
toast queue), `AppFooter.vue` (uses `BrandMark` in its `as="text"` form).

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
- `FilterPanel.vue` — the filter form: pick a category, then values inside
  it (see "Discovery filters" below). Built from `stores/categories.ts`, so
  it names no category itself; writes straight to `stores/filters.ts`.
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
  `provide` rather than a bare module import. POSTs
  `{ action, ...payload }` as JSON to `VITE_API_BASE_URL` (see
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
  to this store automatically — callers decide the wording. Each toast also
  carries a `MessagePlacement`, passed as the second arg to `.error()`/
  `.success()`/`.info()` and defaulting to page-level (top-middle of the
  viewport) when omitted — the right default for general/API-level messages
  with no specific subject (e.g. `ShortlistPage.vue`'s on-mount
  `loadMatches()` failure). Build a non-default one with
  `anchorTo('element' | 'input', el)`, which resolves `el`'s
  `getBoundingClientRect()` **eagerly** (call it right at the triggering
  action, not lazily inside a `.then()`/after a list mutation) since the
  element may not still be around by the time the toast is actually pushed —
  see `ShortlistPage.vue`'s `revokeMatch()`, which snapshots the clicked
  row's anchor before the row can be filtered out of `matches`. `'element'`
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
  registration lands on `/guide/discover` instead of `/unlock` (guides
  don't pay). `RegisterPage.vue` **doesn't offer a photo picker at all** —
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
  not a `v-if`/`v-else-if` pair** — the picks-pill (link to `/shortlist`,
  shown whenever `useShortlistStore().hasAccess`) and "Log out" (shown
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
  showing. **"How it works" (`/#how`) is the mirror image of those** — it
  renders only while signed *out* (`v-if="!isAuthenticated"`), being
  marketing copy a signed-in user is already past; the `#how` section
  itself still exists on `HomePage.vue` for anyone who lands on `/`.
- Payments/matching actions — `api.unlockPack()`, `api.paymentState()`,
  `api.decide()`, `api.revokeSelection()`, `api.matches()` — no longer take
  an explicit `userId`; the caller's identity comes from the session hash
  `call()` attaches automatically (see "Sessions" below). `UnlockPage.vue` calls `unlockPack()`
  for real and syncs the result into `useShortlistStore().setPacksUnlocked()`.
  `ShortlistPage.vue`'s "Confirmed matches" panel calls `matches()` on mount
  and `revokeSelection()` per row — genuinely wired to the server, unlike
  the mock picks grid above it on the same page (different guide-id spaces;
  see the `data/guides.ts` note above). `decide()` **is** now called from
  both discovery decks — `DiscoverPage.vue`'s `accept()`/`reject()` and
  `GuideDiscoverPage.vue`'s `accept()`/`reject()` — now that both read real
  ids from `stores/guides.ts`/`stores/visitors.ts` rather than the old
  mock-id decks; see the Domain model section below for the mutual-match
  notification this unlocked.

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

One page for both roles, fully server-backed (unlike `ShortlistPage.vue`'s
client-only picks grid). It loads `api.matchOverview()`
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
  locally, so newly unlocked contact details show up straight away.
- **What the server hides:** `their_decision` is only ever `'interested'`
  or `null`, so a pass from the other side looks like "no answer yet". A
  pair where they passed and you never decided isn't returned at all.
  `email`/`phone` are `null` unless `active = 1` **and** the pair has a
  confirmed booking — for both sides.
- Guests: Accept and Reconsider each cost a pick (enforced server-side by
  `Selections::decide()`). With `selectionsLeft === 0` those buttons turn
  into "Unlock…" links to `/unlock`. Every guest action is mirrored into
  `shortlist` (`pick`/`pass`/`remove`), so the header's pick counter and
  the `/discover` deck stay consistent within the session.
- **A pair whose latest booking is `confirmed` isn't on this page at all**
  (`isTrip()`) — upcoming, under way or finished, it's a trip and lives on
  `/trips`; the toolbar shows "N confirmed trips · My trips". A free guide's
  match is confirmed the moment it forms, so it goes straight there. "Book
  again" after a finished trip is on the trip card; the new payment brings
  the pair back here as pending until the guide accepts.
- This is where a guide finds a match the visitor completed (see the gap
  under "Progress so far"). `ShortlistPage.vue`'s "Confirmed matches" panel
  still exists and now links here.

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
  "Free", "· £Y refunded"), contact details while confirmed and still
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
  re-syncs a guest's `shortlist` from `api.paymentState()`, since a cancel
  hands the pick back.
- `TripRow` reuses `MatchOverviewRow`'s booking field names
  (`booking_status`, `booking_amount`, …) — the shared subset is the
  `BookingView` type `BookingPanel` takes — but `id` is the *booking's*.
- Date/money formatting for both is in `src/utils/bookingTime.ts`.

### Guide fees & bookings

Contact details are what a guide sells, so a match alone no longer reveals
them — to **either** side. A **confirmed booking** (`bookings`,
`Bookings.php`) does. All of it is driven from `BookingPanel.vue`, which sits
under every row on `/matches`' Matched tab (until the booking is confirmed)
and under every non-cancelled card on `/trips` (after), and emits `changed`
after each step so the page re-fetches.

**Lifecycle of a paid booking** (guide with `user_profiles.price_amount > 0`):

| Status | How it gets there | What each side can do |
| --- | --- | --- |
| *(none)* | matched, nothing booked | guest: **Pay £X** — meeting date-time + optional trip length (0.5–24 h) + optional meeting place → `payGuideFee` |
| `pending` | guest paid | guide: **Accept** → `confirmed` (contact unlocks) or **Decline** (optional reason) → `declined`, full refund. Guest: **Withdraw payment** → `withdrawn`, full refund. Nobody answers before the meeting time → `sweep()` declines it, full refund. The match survives all of these, so the guest can try another time. |
| `confirmed` | guide accepted | guide: **Cancel booking** → `cancelled`, fee refunded **+5% if late**. Guest: **Request cancellation** (reason required, only before the trip starts) → guide **Approves** → `cancelled`, refund **fee − 5%**; or **Refuses** → booking stands. Guest can withdraw their request. Either cancel dissolves the match on both sides, which returns the guest's pick. |
| trip `started` | meeting time passed (`sweep()`) | a guide on a started trip is **hidden from the visitor deck** until the trip's end (or 12 h, if no length). |
| trip `finished` | see below | both can review each other; contact stays visible; the guest can **Book again**. |

- **Guide acceptance is the point**: the guide commits to a date *before*
  contact details are exchanged, so a guide can't take a booking and drop
  it at the last minute without it costing them (the late +5%).
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
- **Meeting place** (`bookings.location`, ≤160 chars, free text): optional
  when paying; either side can set, change or clear it while the booking is
  open (`bookingAction` op `setLocation` + `location`), and the other side
  is notified (`trip_location`).
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
- **`Bookings::sweep()`** runs at the start of every request
  (`RequestProcessor::sweepBookings()`, never allowed to fail the request):
  expires unanswered pending bookings, starts trips, finishes trips.
- **Nothing else hands out contact details**: deck cards
  (`Users::searchByRole()`, `ProfileAccount`) and public profiles carry no
  `email`/`phone` — the deck used to include `email` as a name fallback,
  which would have let anyone skip the fee.
- Nothing is charged or refunded for real (same as pack purchases). Emails
  and notification copy live in `Bookings.php`; times in emails are UTC
  ("… UTC"), in the UI the viewer's own zone.

### In-app notifications

`notifications` rows (`Notifications::notify()`, which also sends the
email) are what a user hears about while they weren't looking — currently
`booking_requested`, `booking_accepted`, `booking_declined`,
`booking_withdrawn`, `booking_expired`, `booking_free`, `booking_cancelled`,
`cancel_requested`, `cancel_request_withdrawn`, `cancel_approved`,
`cancel_refused`, `finish_requested`, `finish_refused`, `trip_finished`,
`trip_location`. Copy about a confirmed trip points to "My trips", copy
about a pending request to "Matches". There's no push
channel: `App.vue` pulls them (`api.notifications()`) at boot and on every
route change, throttled to once per 15s, and shows each as a 10s info toast;
the server marks them read in the same call, so each shows once. The
contact channel is **email only for now** — `Notifications::deliver()` is
the one place to branch on a preferred channel when SMS/WhatsApp exist.

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
  (`ShortlistPage`) even after a later, narrower fetch stops including them.
  No `loaded` cache flag — every `load()` call is a real, current fetch;
  callers decide when to call it.
- **These are the one place in the client that calls `useApi()` from
  inside a Pinia store** rather than a page component (every other store
  follows the page-calls-the-api pattern — see `RegisterPage.vue` below) —
  because the fetched-and-mapped list is shared by `DiscoverPage`
  and `ShortlistPage`. The call is made **once,
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
  `DiscoverPage.vue`'s shortlist rail ("Interested in" / "Mutual interest
  reveals contact details.") — same shared three-column `.discovery-layout`
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
  `shortlist.pick()`/`canPick` first (the local, client-only pack state —
  see "Domain model" below) and only calls `decide()` if that local pick
  actually went through (`shortlist.isPicked(id)`), so a guest out of picks
  never records a decision they didn't really make; `reject()` isn't gated
  (a pass never costs anything). Both pages treat a `decide()` failure as
  best-effort — a toast via `useMessagesStore()`, no rollback of the local
  swipe-past-this-card state — since the deck's own local
  `interested`/`passed` (or `shortlist.picks`/`.passed`) arrays are what
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
  `messages.success(...)` telling the guide contact details appear on
  `/matches` once the visitor has paid their fee (straight away for a free
  guide) — see "Guide fees & bookings". This only fires on the decision that *completes*
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
only record of "already seen" was the client-side `stores/shortlist.ts`,
which doesn't survive one.

- **Someone who liked *you* but whom you haven't answered is still shown**
  — that's how a match gets completed from the deck. Only your *own*
  decision removes a card.
- **Anonymous callers see everyone**, exactly as before — the exclusion
  needs a session, and `guides`/`visitors` still work without one.
- **`includeDecided: true` opts out**, because `ShortlistPage.vue` (the
  picks grid) renders cards the visitor has by definition already decided
  on and resolves them from the same fetch, via
  `guidesStore.load(undefined, { includeDecided: true })`. Without it the
  page silently comes up empty — the store's `find(id)` has nothing to
  find. A page that needs one specific person by id should use
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

The filter bar (`FilterPanel.vue`) is **gradual**: pick a category first,
then the values inside it, which sit as tags under that category's own
title with a rule between one group and the next. It's closed by default —
a `<details>` on both decks — and built entirely from
`stores/categories.ts`, so it offers whatever the server has, including a
category a user invented on their profile page five minutes ago.

- **Multi-select tags everywhere, with one deliberate exception.** Gender
  is a dropdown (Male / Female / No preference) because it's a human
  constant, not a tag list. There are no other `<select>`s, radios or
  checkboxes in the bar.
- **`range` categories use `RangePicker`** — two sliders and an Add button,
  collecting several spans ("mornings *and* evenings"), each removable.
  `hour_of_day` runs 00:00–23:00; `age` runs 16–90.
- **`age` is a category in the UI but not in the database.** There's
  nothing to store per user — it's derived from `date_of_birth` — so
  `stores/categories.ts` injects a synthetic `AGE_CATEGORY` for the bar and
  `plugins/api.ts` peels it back out into its own `ageRanges` request
  field. Everything else in `ranges` goes to the server as-is.
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
- Visitors pay per pack of 5 selections (`PACK_SIZE` in `stores/shortlist.ts`,
  £5/pack per the prototype's pricing) rather than getting unlimited swipes.
- A swipe produces a `Decision`: `'pass' | 'interested'`. Both sides now
  persist it via `api.decide()` (see "Real guide/visitor cards" above) —
  `DiscoverPage.vue` alongside its local, client-only `shortlist.pick()`/
  `.pass()` (which is what actually gates a visitor's remaining picks and
  drives the deck), `GuideDiscoverPage.vue` alongside its own local
  `interested`/`.passed` arrays (no pack to gate, so no equivalent
  capacity check before persisting).
- Server-side, a guest/guide pair becomes an active **match** when both
  sides have independently recorded `'interested'` — see `selections` under
  Database state. `GuideDiscoverPage.vue`'s `accept()` surfaces this the
  moment it happens on the guide's own decision (see the note above).
  Contact details then wait on a booking — see "Guide fees & bookings".
  Either side can revoke (a booked pair has extra rules, same section); revoking only clears *their own* side and deactivates the pair, it
  doesn't touch the other side's decision or delete the row, so the pair can
  re-activate later and the revoking user is immediately free to be matched
  by/with someone else.

### Sessions

The app now has real server-side sessions (see `Session.php` under
Backend) — the long-standing "there is no session/token auth yet, so
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

### Two-factor login & password reset

Both by email, both in `api/classes/AccountSecurity.php`, backed by
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
- **If the email can't be sent** (no SMTP config, Gmail down) the login
  fails with "We couldn't email your login code…" rather than letting the
  user in — so a 2FA account can't log in on a server without `SMTP_*`.
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
- **`APP_URL`** (fastcgi param, defaults to `http://localhost:5173`) is
  where email links point. It's read from the server environment, never
  from the request's Origin/Host — those are attacker-controlled and would
  let someone point a victim's reset link at their own site. Set it for
  production.
- Codes and links are never logged. In development, `MAIL_REDIRECT_TO`
  sends them to one inbox (see "Email").

## Docker environment

This app runs inside the `docker-lemp` compose stack (one level up from this
repo, at `../../docker-compose.yaml` relative to here — i.e.
`~/Docker/docker-lemp/docker-compose.yaml`). Relevant services/containers:

- `php` / `lemp-php` — PHP 8.3-fpm (custom image: `mysqli`, `pdo_mysql`,
  `memcache` extensions built in). No host-installed PHP — always run PHP
  *through this container*:
  ```sh
  docker exec lemp-php php -l /var/www/localguideapp/api/classes/Users.php   # lint
  docker exec lemp-php php -v                                        # version
  ```
  The host repo root (`~/Docker/docker-lemp/www/`) is bind-mounted to
  `/var/www` in this container (and in `nginx`/`node`), so this repo is at
  `/var/www/localguideapp` inside it.
- `nginx` / `lemp-nginx` — serves `api.rockguide.com` per
  `../../nginx/conf.d/api.rockguide.com.conf`, doc root `/var/www/localguideapp/api`,
  proxying `*.php` to `php:9000`. Both the plain-HTTP (80) and HTTPS (443)
  server blocks set `fastcgi_param PEPPER "…"` (used by `Auth.php`) and CORS
  headers (`Access-Control-Allow-Origin`, restricted by a regex to
  `*.rockguide.com` / `*.localhost:5173` origins, plus bare `localhost:5173`;
  `-Methods`/`-Headers`; `OPTIONS` short-circuits to `204`) — needed because
  the frontend (`localhost:5173` in dev) and this API are always
  cross-origin. Reload after editing: `docker exec lemp-nginx nginx -s reload`.
  Requires `127.0.0.1 api.rockguide.com` in `/etc/hosts` on the host to be
  reachable from a browser at all (nginx routes by `Host` header).
- `mysql` / `lemp-mysql` — MySQL 8.4. The `guideapp` database and
  `localguideapp` user (both hardcoded in `db.php`) already exist on this
  server, pre-dating this task:
  ```sh
  docker exec lemp-mysql mysql -u localguideapp -p'…' guideapp
  ```
- `node` / `lemp-node` — where `client/` commands (`yarn dev`, etc.) actually
  run; same `/var/www` mount.

### Email

`Mailer.php` reads these from the server environment — add them next to
`PEPPER`/`DB_*` in **both** server blocks of
`nginx/conf.d/api.rockguide.com.conf`, then `docker exec lemp-nginx nginx -s reload`.
Never commit them.

```nginx
fastcgi_param SMTP_HOST "smtp.gmail.com";
fastcgi_param SMTP_PORT "587";
fastcgi_param SMTP_USER "<the sending Gmail address>";
fastcgi_param SMTP_PASS "<16-char Google App Password>";
fastcgi_param MAIL_FROM_NAME "RockGuide";
fastcgi_param MAIL_REDIRECT_TO "<your inbox>";   # development only
```

- Gmail rejects the normal account password over SMTP. It needs an **App
  Password**: turn on 2-Step Verification for the account, then create one
  at myaccount.google.com/apppasswords. Gmail allows roughly 500 emails a
  day, and `From` must be that Gmail address (`MAIL_FROM` defaults to
  `SMTP_USER`).
- `MAIL_REDIRECT_TO` sends every email to that one address instead, noting
  the real recipient at the top of the body — set it in development, where
  test accounts have made-up addresses; remove it in production.
- With no `SMTP_*` set, nothing is sent and each attempt is logged to
  `/tmp/db_error_<date>.log` in `lemp-php` (`db::writeLog()`); SMTP failures
  land there too.
- The SMTP conversation was verified against smtp.gmail.com up to AUTH
  (bogus credentials → `535 BadCredentials`) — a real App Password is the
  only missing piece.

## API reference

Every action `RequestProcessor::handle()` dispatches (`api/classes/
RequestProcessor.php`'s `switch`), and the `src/plugins/api.ts` method that
calls it — a 1:1 mapping, always POSTed as `{ action, sessionHash?,
...payload }` JSON to `VITE_API_BASE_URL` (see `.env.development`; defaults
to `https://api.rockguide.com`). "Auth" means the action calls
`requireUserId()` and throws a `401` `AuthenticationException` (see
"Sessions" below) without a valid `sessionHash` — everything else either
takes no identity at all or reads one from the payload (`register`/`login`)
directly. Every response is `200` with a JSON body on success; a thrown
`RuntimeException` maps to `400`, `AuthenticationException` to `401`, an
unknown `action` to `404`, anything else (a real bug) to `500` (logged via
`db->writeLog()`, generic message to the client).

| Action | Auth | Frontend call | Request fields (beyond `sessionHash`) | Response | Backend |
| --- | --- | --- | --- | --- | --- |
| `register` | no | `api.register(payload)` | `email, password, role, name?, image?` | `AuthResult` (`id, email, role, sessionHash`) | `actionRegister()` → `Users::register()`, `Session::create()` |
| `login` | no | `api.login(payload)` | `email, password, deviceToken?` | `AuthResult` (`id, email, name, role, sessionHash`), or a `TwoFactorChallenge` for a 2FA account | `actionLogin()` → `Users::verifyCredentials()`, then `AccountSecurity::startLogin()` or `signIn()` |
| `verifyTwoFactor` | no | `api.verifyTwoFactor(payload)` | `challengeToken, code, rememberDevice?` | `AuthResult` (+ `deviceToken, trustedUntil` when remembering) | `AccountSecurity::verifyLogin()`, `signIn()`, `trustDevice()` |
| `forgetTrustedDevices` | **yes** | `api.forgetTrustedDevices()` | — | `{ ok: true }` | `AccountSecurity::forgetTrustedDevices()` |
| `resendTwoFactor` | no | `api.resendTwoFactor(payload)` | `challengeToken` | `TwoFactorChallenge` | `AccountSecurity::resendLoginCode()` |
| `requestPasswordReset` | no | `api.requestPasswordReset(payload)` | `email` | `{ ok: true }` always | `AccountSecurity::requestPasswordReset()` |
| `resetPassword` | no | `api.resetPassword(payload)` | `token, newPassword` (≥8) | `{ ok: true }` | `AccountSecurity::resetPassword()` |
| `guides` | optional* | `api.guides(filters?, includeDecided?)` | `gender?, attributes?, ranges?, ageRanges?, includeDecided?` | `{ guides: ProfileAccount[] }` | `Users::searchByRole('guide', ...)` |
| `visitors` | optional* | `api.visitors(filters?, includeDecided?)` | same filter shape as `guides` | `{ visitors: ProfileAccount[] }` | `Users::searchByRole('guest', ...)` |
| `attributeCategories` | no | `api.attributeCategories()` | — | `{ categories: AttributeCategory[] }` | `Profiles::getCategories()` |
| `myProfile` | **yes** | `api.myProfile()` | — | `MyProfile` (+ `trusted_device_count`) | `Users::getFullAccount()`, `AccountSecurity::trustedDeviceCount()` |
| `updateProfile` | **yes** | `api.updateProfile(payload)` | `dateOfBirth?, gender?, headline?, bio?, priceAmount?, priceLabel?, priceNote?, includes?, party?, durationHours?, attributes?, newCategoryLabels?, ranges?` — every field independent (no `rating`/`tours` since 013) | `{ ok: true }` | `actionUpdateProfile()` → `Profiles::setProfile()`/`setAttributeValues()`/`setRanges()` |
| `updateAccount` | **yes** | `api.updateAccount(payload)` | `name?, email?, phone?, twoFactorMethod?, image?` | `{ ok: true }` | `actionUpdateAccount()` → `Users::updateAccount()` (+ inline email/2FA validation) |
| `changePassword` | **yes** | `api.changePassword(payload)` | `currentPassword, newPassword` | `{ ok: true }` | `actionChangePassword()` → `Users::verifyPasswordFor()`/`setPassword()` |
| `unlockPack` | **yes** (guest only) | `api.unlockPack()` | — | `PaymentState` (`packsUnlocked, capacity, picksUsed, selectionsLeft`) | `actionUnlockPack()` → `Payments::recordPackPurchase()` |
| `paymentState` | **yes** | `api.paymentState()` | — | `PaymentState` | `paymentStateFor()` |
| `decide` | **yes** | `api.decide(payload)` | `targetId, decision` (`'interested' \| 'pass'`) | `SelectionRow` (`id, guest_id, guide_id, guest_decision, guide_decision, active, created_at, updated_at`) | `Selections::decide()` |
| `revokeSelection` | **yes** | `api.revokeSelection(payload)` | `targetId` | `SelectionRow` | `Selections::revoke()` |
| `matches` | **yes** | `api.matches()` | — | `{ matches: MatchRow[] }` (`id, counterpart_id, name, email` — `email` `null` until booked) | `Selections::getActiveMatches()` |
| `matchOverview` | **yes** | `api.matchOverview()` | — | `{ selections: MatchOverviewRow[] }` (`id, counterpart_id, my_decision, their_decision, active, matched_at, updated_at, name, image, email, phone, age, headline, price_label, party, duration_hours, fee_amount, booking_id, booking_status, payment_status, booking_amount, meeting_at, location, refund_amount, trip_status, refund_if_cancelled` — a pass from the other side reads as `null`; `email`/`phone` are `null` unless active **and** booked) | `Selections::getOverview()` |
| `trips` | **yes** | `api.trips()` | — | `{ trips: TripRow[] }` (booking `id`, `selection_id, counterpart_id, phase, is_latest, name, image, email, phone, active, fee_amount, booking_status, payment_status, booking_amount, meeting_at, booking_duration_hours, location, refund_amount, trip_status, accepted_at, cancel_reason, cancelled_at, cancel_requested_at, cancel_request_reason, finish_requested_by, finished_at, matched_at, auto_finish, refund_if_cancelled, refund_if_guest_cancels` — newest first) | `Bookings::tripsFor()` |
| `payGuideFee` | **yes** (guest only) | `api.payGuideFee(payload)` | `targetId` (guide), `meetingAt` (ISO date-time, future, ≤1 year), `durationHours?` (0.5–24), `location?` (≤160 chars) | `Booking` (`status: 'pending'` — waits for the guide) | `actionPayGuideFee()` → `Bookings::pay()` |
| `bookingAction` | **yes** (role per `op`) | `api.bookingAction(payload)` | `targetId` (the other person), `op` (see "Guide fees & bookings"), `reason?` (decline, requestCancellation — required there — refuseCancellation), `location?` (setLocation; empty clears) | `Booking` | `actionBooking()` → `Bookings::accept()/decline()/withdraw()/requestCancellation()/setLocation()/…` |
| `cancelBooking` | **yes** (guide only) | `api.cancelBooking(payload)` | `targetId` (guest), `reason?` | `Booking` (`status: 'cancelled'`, `refund_amount` set if it was paid) | `actionCancelBooking()` → `Bookings::cancelByGuide()` |
| `notifications` | **yes** | `api.notifications()` | — | `{ notifications: AppNotification[] }` (`id, kind, message, created_at`) — marked read by this call | `Notifications::takeUnread()` |
| `logout` | no (no-ops on a missing/unknown hash) | `api.logout()` | — | `{ ok: true }` | `actionLogout()` → `Session::destroy()` |
| `session` | **yes** | `api.session()` | — | `AuthResult` (same shape as `register`/`login`, unrotated `sessionHash`) | `actionSession()` |
| `publicProfile` | optional | `api.publicProfile(userId)` | `userId` | `PublicProfile` (a `ProfileAccount` minus `email`, plus `role`; `400` "Profile not found." for an unknown id or a non-guest/guide) | `Users::getPublicProfile()` |
| `reviews` | optional* | `api.reviews(payload)` | `userId, limit? (default/max 20), offset?` | `ReviewsPage` (`reviews: Review[]` newest first, `total, average, histogram` `{ "5": n, …, "1": n }`, `viewer: { canReview, bookingId, myReview }` — all no/null signed out) | `Reviews::listFor()`/`viewerStateFor()` |
| `submitReview` | **yes** | `api.submitReview(payload)` | `subjectId, rating, comment?` | `Review` (`id, booking_id, author_id, subject_id, rating, comment, created_at, updated_at, author_name, author_image`) | `Reviews::submit()` (upsert) |
| `deleteReview` | **yes** | `api.deleteReview(payload)` | `reviewId` | `{ ok: true }` | `Reviews::delete()` |

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

## Backend (`api/`)

Plain PHP, no framework, no Composer, no autoloading, no namespaces —
classes are pulled in with `require_once` and match the style already
established in `db.php`. Entry point is `api/index.php`, which just wires up
`db` + `RequestProcessor` and calls `handle()`.

- `api/classes/db.php` — a `db` class wrapping `mysqli` (connect, escape,
  select/insert/update/replace helpers, a basic `WHERE` builder). No
  prepared statements; escaping is manual via `mysqli_real_escape_string`.
  Every method is written against the pre-PHP-8.1 mysqli contract (a failed
  query returns `false`, check it yourself) — `db_Connect()` explicitly sets
  `mysqli_report(MYSQLI_REPORT_OFF)` to restore that, since PHP 8.1+
  otherwise throws `mysqli_sql_exception` on error, which (being a
  `RuntimeException`) would otherwise get caught by `RequestProcessor` and
  have its raw driver message (schema, table/column names) sent straight to
  the client. DB credentials come from the server environment
  (`DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME`, read via a small `env()` helper
  that checks `$_SERVER` then `getenv()`), set with `fastcgi_param` in
  `nginx/conf.d/api.rockguide.com.conf` exactly like `PEPPER` — nothing
  credential-shaped lives in the repo. `db_Connect()` throws if
  `DB_USER`/`DB_PASS` are missing rather than attempting an anonymous
  connection.
- `api/classes/Auth.php` — password hashing. Combines the plaintext
  password, a per-user salt, and a server-wide pepper (`$_SERVER['PEPPER']`,
  never stored in the DB) via `hash_hmac()`, then runs that through
  `password_hash()`/`password_verify()` (bcrypt). `PEPPER` must be set in
  the web server/PHP-FPM environment or every hash/verify call throws.
- `api/classes/Users.php` — data access for `users`/`roles`/`creds`/
  `user_role`: `register()`, `verifyCredentials()`, `findByEmail()`,
  `findById()` (backs `RequestProcessor::actionSession()`),
  `getRoleName()`. `register()` sets `users.role_id` (the account's current
  role) *and* inserts into `user_role` (a role-grant log — see
  `003_user_role.sql`'s header for why both exist), and takes an optional
  pre-validated `$image` data URI (`RequestProcessor::validateImage()` does
  the validating — `Users` just stores whatever string it's given).
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
  `getPacksUnlocked()`/`getCapacity()` (packs × `PACK_SIZE`).
- `api/classes/Selections.php` — data access for `selections` (guest/guide
  matching — see Domain model above for the semantics). `decide()` upserts
  one side's decision and re-syncs `active`; guards a guest's capacity
  against `Payments` (a *new* `'interested'` costs a pick, re-affirming an
  existing one doesn't); `revoke()` clears the acting side only;
  `getActiveMatches()` lists a user's active matches with the counterpart's
  basic info; `getOverview()` lists every pair the user is part of for
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
- `api/classes/Notifications.php` — `notify()` (a `notifications` row +
  an email to the user) and `takeUnread()` (fetch-and-mark-read).
- `api/classes/Mailer.php` — plain-text email over SMTP with STARTTLS +
  AUTH LOGIN, no Composer/PHPMailer. `send()` never throws: a missing config
  or SMTP error is written to `db::writeLog()` and returns `false`, so a
  flaky mail server can't fail a payment. Header-injection-safe (CR/LF
  stripped), RFC 2047 subjects, base64 bodies. See "Email" below for config.
- `Selections.php` also takes `Bookings` now: `syncActive()` stamps
  `matched_at` and calls `Bookings::onMatched()` on the flip to active;
  `revoke()` enforces the booked-pair rules; `getOverview()`/
  `getActiveMatches()` mask contact details with `CONTACT_UNLOCKED`.
- `api/classes/Reviews.php` — data access for `reviews`: `listFor()`
  (a page, newest first, plus `summaryFor()`'s total/average/histogram),
  `viewerStateFor()`, `eligibleBookingId()`, `submit()` (validates and
  upserts), `delete()`. See "Reviews" above.
- `api/classes/AccountSecurity.php` — emailed 2FA login codes and password
  reset links (`auth_challenges`); see "Two-factor login & password reset".
- `api/classes/Session.php` — server-side sessions backed by the
  `sessions` table (see `009_sessions.sql`): `create(userId)` mints a
  random 64-hex-char hash; `resolve(hash)` looks it up, checks
  `last_active_at` against a 5-minute inactivity window, and — if still
  valid — bumps `last_active_at` to now (a sliding expiry) before
  returning the user id, or deletes the row and returns `null` if it had
  expired; `destroy(hash)` is logout. See "Sessions" above for the full
  picture, including the frontend half.
- `api/classes/AuthenticationException.php` — a tiny `RuntimeException`
  subclass thrown only by `RequestProcessor::requireUserId()`, mapped to
  HTTP `401` (instead of the generic `400` every other validation failure
  gets) so `plugins/api.ts`'s `call()` can tell "you're logged out" apart
  from an ordinary bad-input error and react to it.
- `api/classes/RequestProcessor.php` — the single front-to-back entry point.
  Resolves an `action` from `$_GET['action']` or a JSON body, dispatches to
  `register` / `login` / `guides` / `visitors` / `updateProfile` /
  `attributeCategories` / `myProfile` / `updateAccount` / `changePassword` /
  `logout` / `session` / `unlockPack` / `paymentState` / `decide` /
  `revokeSelection` / `matches` / `matchOverview`, and writes a JSON response with an
  appropriate HTTP status. Add new endpoints as new `case`s here rather
  than adding more entry-point scripts. **`updateProfile` and everything
  from `myProfile` down all resolve their caller's identity through
  `requireUserId()`**, which now calls `Session::resolve()` against a
  `sessionHash` in the request rather than trusting a client-supplied
  `userId` directly — the fix for the gap this file used to flag here.
  `register`/`login` call `Session::create()` and return the new
  `sessionHash` alongside the rest of the response; `logout` calls
  `Session::destroy()` (silently no-opping on a missing/unknown hash —
  there's simply nothing to do); `session` re-validates the current
  session (sliding its window forward) and returns the same `AuthResult`
  shape `register`/`login` do, for `App.vue`'s boot-time check.
  `actionRegister()` also runs any `image` field through `validateImage()`
  before it ever reaches `Users`: must match
  `data:image/(png|jpe?g|webp);base64,...` and stay under ~2MB of base64
  text (nginx's own 1MB `client_max_body_size` default trips first for
  anything that large in practice — confirmed by testing, not configured
  here — so this cap is really defense-in-depth for a future config
  change) — this is the one place guarding what lands in `users.image`
  (`actionUpdateAccount()` reuses it for a photo change). `readFilters()`
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
  as 002.
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
- `api/sql/017_trusted_devices.sql` — `trusted_devices` ("remember this
  device" for 2FA). Applied 2026-10-08. Re-runnable.
- `api/sql/016_auth_challenges.sql` — `auth_challenges`, and every
  `two_factor_method` of `sms`/`whatsapp` set to `email`. Applied
  2026-10-05. Re-runnable.
- `api/sql/015_trip_location.sql` — `bookings.location` (VARCHAR(160),
  nullable), and `accepted_at = created_at` for bookings cancelled before
  014 (back then every booking was born confirmed), so `accepted_at IS NOT
  NULL` identifies a trip. Applied 2026-10-05. Not re-runnable.

There is no test setup, linter, or formatter configured for `api/` yet;
`docker exec lemp-php php -l <file>` is the only current check.

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
- `cms_users` — same shape as `users` (`role_id`/`email`/`name`/
  `created_at`), for the future CMS/admin layer (`admin/`, currently empty).
  **No `cms_creds` table yet** — nothing can log into it until one is added
  alongside whatever builds `admin/`.
- `user_role` — a log of role grants (`user_id`, `role_id`), one row per
  registration, unique per pair. Written by `Users::register()` in addition
  to (not instead of) setting `users.role_id`; `getRoleName()` still reads
  `users.role_id`, not this table.
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
  duration_hours, location, status `pending|confirmed|declined|withdrawn|cancelled`,
  payment_status `none|paid|refunded`, accepted_at, refund_amount,
  cancel_reason, cancelled_at, cancel_requested_at, cancel_request_reason,
  trip_status `pending|started|finished`, finish_requested_by,
  finish_requested_at, finished_at, created_at, updated_at, generated
  `open_pair`) — at most one open (pending, or confirmed and unfinished) booking per pair,
  enforced by a UNIQUE index on `open_pair`. Date-times are UTC (MySQL,
  PHP and the containers all run in UTC).
- `notifications` (id, user_id, kind, message, read_at, created_at).
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
- `sessions` (id, `session_hash` unique, user_id, created_at,
  last_active_at) — one row per active login, deleted on logout or the
  next time `Session::resolve()` finds it past its 5-minute inactivity
  window (see "Sessions" above). Never holds more than one live row per
  concurrent login — logging in again from another browser/device just
  creates a second row; nothing here limits sessions per user.
