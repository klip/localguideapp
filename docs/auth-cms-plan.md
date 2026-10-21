# Plan: signed tokens, device security, and the CMS

Status (2026-10-21): **phases 1–6 built** — sign-in (CLAUDE.md "Sign-in")
and the CMS foundation (CLAUDE.md "CMS"); phases 7–9 remain a proposal. Three pieces that depend on
each other, in build order:

1. **Auth v2**: signed short-lived access tokens + rotating refresh tokens,
   checked by every service with a public key, no Redis per request.
2. **Devices & login security**: a device/system/location fingerprint stored
   with every login, suspicious-login detection, multi-account detection.
3. **CMS**: a separate service and Vue build for staff (admin / manager /
   editor) — content editing on a page wireframe, user search and a user page
   (levels, badges, bonuses, verification, freezing).

Open questions are collected at the end.

---

## 1. Auth v2: access + refresh tokens

### Before (retired in phase 5)

`login` created a random session hash in Redis (5-minute sliding TTL). Every
request the gateway called auth's `resolve` (one Redis `GETEX`), then forwarded
`X-User-Id`. Idle for 5 minutes = signed out.

### Target

| Token | Lifetime | Format | Checked by | Stored |
| --- | --- | --- | --- | --- |
| **Access** | 15 min | JWT, EdDSA (Ed25519) signature | every service, locally, with the **public key** | client **memory only** — never `localStorage`; a reload gets a new one from the refresh cookie |
| **Refresh** | 30 days sliding, 90 days absolute | 32 random bytes, opaque (built that way: every use is looked up anyway), and a server-side row holding only its hash | auth only, on refresh (once per ~15 min per device) | an **HttpOnly cookie** page scripts can't read (`__Host-rg_refresh`); server `refresh_tokens` |
| **Device id** | 400 days (the browser maximum), renewed on use | random 128-bit id | auth, on sign-in and refresh | an HttpOnly cookie (`__Host-rg_device`) |

### Why cookies change the transport for sign-in

A cookie can only be set or cleared by an HTTP response, and the app talks to
the API over one WebSocket, whose messages can't carry cookies. So the
actions that **issue, renew or end** tokens move to plain HTTPS on the app's
**own origin**; everything else stays on the WebSocket:

| Over HTTP: `POST /auth/<action>` (same origin as the app) | Over the WebSocket (unchanged) |
| --- | --- |
| `register`, `login`, `verifyTwoFactor`, `refresh`, `logout`, `logoutEverywhere` (all built) | every other action, with the access token — `addRole` included (it returns a new access token; no cookie changes), and `resetPassword` (it revokes every refresh token; the user isn't signed in on that page) |

- **Same origin is what makes the cookie first-party.** Production serves the
  app and `/auth/*` from `belikelocal.club` (nginx routes `/auth/` to the auth
  service, the rest to the static build), so the cookie never crosses sites
  and `SameSite=Strict` holds. Development does the same over HTTPS
  (`https://www.belikelocal.club`, a local development certificate — see
  CLAUDE.md "Development over HTTPS"), so every browser, Safari included,
  keeps the `Secure` cookies. The CMS does the same on its own origin
  (`admin.belikelocal.club/auth/*`, cookie `__Host-rg_cms_refresh`), so app and
  staff sessions can't touch each other.
- **Cookie attributes**: `HttpOnly; Secure; SameSite=Strict; Path=/`, with
  the `__Host-` prefix (the browser then refuses it without `Secure`, from
  another domain, or with a `Domain` attribute — no subdomain can overwrite
  it). Lifetime = the refresh token's; `logout` sends an expired one.
- **CSRF**: `SameSite=Strict` already keeps other sites from sending the
  cookie; the `/auth/*` endpoints also require `Content-Type:
  application/json` (a cross-site form can't send it without a CORS
  preflight, which is refused) and an `Origin` header naming the app.
- **What a script on the page can still do**: read the in-memory access
  token (15 minutes at most) — but never the refresh token, so an injected
  script can't keep an account. That's the gain over `localStorage`.

- **Access token claims** (kept small, it rides on every request):
  `iss` (`belikelocal-auth`), `aud` (`app` or `cms`), `sub` (user id), `sid`
  (the login "family", one per device sign-in), `roles` (`["guest","guide"]`
  or the staff role), `dev` (device id hash), `iat`, `exp`, `jti`, header
  `kid` (key id, for rotation).
- **Signing**: libsodium's `sodium_crypto_sign_detached()` (available in all
  three PHP containers — no Composer, consistent with the codebase). A small
  `lib/Jwt.php` (encode/verify, base64url, `exp`/`nbf`/`aud`/`iss` checks,
  60 s clock leeway). Private key **only** in the auth service env
  (`JWT_PRIVATE_KEY`, Secret Manager in production); public keys as
  `JWT_PUBLIC_KEYS` (`kid:base64,…`) in every service, also served by auth at
  `GET /jwks` for later automation.
- **Why the refresh token is also stored server-side**: a signed token alone
  can't be revoked. Refresh is the one moment a server check is acceptable
  (every 15 minutes, not every request), and it's what makes "sign out that
  device", password reset, freezing and stolen-token detection work.

### Flow

```
POST /auth/login       ─► body { accessToken, accessExpiresAt, user }
  (or verifyTwoFactor)    + Set-Cookie __Host-rg_refresh (and rg_device if new)
page load / new tab    ─► POST /auth/refresh (cookie sent by the browser)
                          → a fresh access token, or 401 = signed out
every request (WS)     ─► gateway verifies access token (signature, exp, aud)
                          → forwards it to the service (Authorization: Bearer)
                          → the service verifies it again (defence in depth)
~60 s before expiry    ─► POST /auth/refresh { device info }
                          auth: cookie's token hash → valid, not revoked, not reused
                          → marks it used, issues a NEW pair (rotation):
                            access token in the body, refresh token as a new cookie
                          → login_events row (see §2)
access expired / 401   ─► one refresh attempt, then the request is retried once
refresh refused        ─► signed out (today's "session expired" toast)
```

- **Rotation with reuse detection**: each refresh token is single-use. Using
  an already-rotated one means it was copied — the whole `sid` family is
  revoked (every device token from that sign-in) and the user gets a
  "suspicious sign-in" email. A 30 s grace window returns the same new pair
  to a duplicate request (two tabs racing, a flaky network).
- **Multiple tabs**: the cookie is shared by every tab, so only one may
  refresh at a time — Web Locks API (`navigator.locks.request(
  'belikelocal-refresh', …)`); the new access token is handed to the other tabs
  over `BroadcastChannel` (memory to memory, never through storage). A tab
  that opens while another is refreshing waits for the lock and asks the
  channel first. Without this, rotation turns ordinary tab use into false
  "reuse" alarms; the 30 s grace window covers what slips through.
- **Sign-in state across reloads**: `localStorage` keeps only the
  non-secret `user` summary (name, roles, active role) so the header renders
  at once; the session itself is the cookie, confirmed by the first
  `/auth/refresh` (which replaces today's boot-time `api.session()` check).
- **WebSocket**: the connection binds with the access token (`{ type: 'auth',
  accessToken }`) instead of the hash; the gateway records its `exp` and drops
  the binding when it passes unless the client re-binds with a fresh token
  (it does, right after each refresh). Requests carry the token too, so the
  gateway never calls auth for an ordinary request — the 20 s `peekMany`
  sweep goes away.
- **Acting role** stays a per-request field; the gateway now checks it
  against the token's `roles` claim instead of auth's `resolve` answer.
  `addRole` returns a new pair at once so the claim is current.
- **"Stay signed in"**: the 5-minute idle logout disappears — an idle tab
  just refreshes on its next use within 30 days. Optional later: a "this is a
  shared computer" checkbox that makes the refresh token session-only.

### Revocation without per-request lookups

An access token stays valid until it expires (≤ 15 min). For the cases that
can't wait — logout, "sign out everywhere", password reset, freeze — auth
revokes the family in MySQL and publishes `{ sid | userId, at }` on Redis
`auth:revoked`. Every gateway keeps those in an in-memory denylist until the
matching access tokens would have expired anyway, and reloads the live list
(`revoked:sid:*` / `revoked:user:*` keys with a TTL) at boot and after a
Redis outage. That's one Redis subscription per gateway, not a call per
request. (Built: `lib/Revocations.php`. Only the gateway checks it — the
php-fpm services keep nothing between requests and are reachable only
through the gateway. Accounts are revoked sid by sid, each live sign-in, so
signing straight back in isn't caught; the user-wide form is for freezing.)

### Storage

```
refresh_tokens (id, user_id, sid, device_id, token_hash UNIQUE, parent_id,
                issued_at, expires_at, absolute_expires_at, used_at,
                revoked_at, revoke_reason, replaced_by_id)
signing_keys   — not a table: keys live in env/Secret Manager, `kid` names them
```

`trusted_devices` (skip-2FA) folds into the new `devices` table (§2).

### Security notes

- No token is ever in `localStorage` (today's session hash is). Still worth
  doing: a strict CSP (no inline scripts, no third-party scripts), since an
  injected script could use the in-memory access token for up to 15 minutes.
- The WebSocket stays on `api.belikelocal.club` and carries the access token in
  its messages, not cookies — so it needs no CSRF protection and no change to
  the gateway's origin check.
- Key rotation: add the new public key everywhere, switch signing to the new
  `kid`, drop the old public key after 90 days.
- Every token check failure is a `401`, so `call()`'s existing logout path
  still works; a refresh failure is the only thing that actually signs out.

---

## 2. Devices, fingerprints and suspicious logins

### What's collected on every login, 2FA step and refresh

**From the client** (`src/utils/deviceInfo.ts`, sent as `device` on login /
register / refresh):

**Tier 1 — collected from the start:**

| Signal | Why |
| --- | --- |
| **Device id**: the `__Host-rg_device` HttpOnly cookie, set by auth on the first sign-in (§1) — the page never sees it | the strongest, honest identifier of "the same browser" — until cookies are cleared or a private window is used |
| Browser and OS family + major version (Client Hints, else the User-Agent) | "Chrome on macOS" for the devices list; an OS change is a signal |
| Device model (Client Hints, mobile only) | phones |
| Screen size, pixel ratio, colour depth, touch points | device class |
| Time zone, language list | a time zone that disagrees with the IP's country is a classic VPN/takeover signal |
| CPU cores, memory class | device class |

**Not collected: canvas and WebGL** (decided 2026-10-20). They're what tells
apart two laptops of the same model and browser (tier 1 can't), but they're
the part regulators call fingerprinting, and Safari, Firefox and Brave
deliberately randomise them, so on those browsers they'd make one device look
new every time. Reconsider only with a lawyer's sign-off.

**Stored as keyed hashes, per component.** Each component is stored as
`HMAC-SHA256(server secret FINGERPRINT_KEY, name + value)` — one-way (it's a
hash, not encryption, so there's no key that turns it back), and keyed so a
leaked database can't be reversed by hashing every common screen/time zone/
browser combination (there are few enough to try them all) or matched against
another site's data. Per component rather than one hash of everything,
because one combined hash changes completely on every browser update and
every device would look new; per component, "6 of 7 match, the browser
version changed" is still the same device. Plaintext is kept only where a
person must read it: the coarse label ("Chrome on macOS · Lisbon, Portugal")
for the user's own devices list, and the IP for 90 days.

**From the server** (no client cooperation needed):

- **IP address** — today the gateway doesn't see it: nginx must pass
  `X-Real-IP` / `X-Forwarded-For` on the `/ws` upgrade, the gateway records it
  per connection and forwards `X-Client-Ip` (trusting the header only from
  nginx).
- **IP → country, region, city, ASN, "hosting/VPN" flag**: MaxMind GeoLite2
  (free, needs an account and licence key; the .mmdb files read by a small
  pure-PHP reader) or an API in development — a stand-in to log in
  `docs/production-memo.md`.
- `Accept-Language` of the upgrade request.
- Precise GPS location is **not** asked for at login (a permission prompt on
  every sign-in is hostile and adds little); IP geolocation is the location.

### Storage

```
devices      (id, user_id, device_id, fingerprint_hash, label, first_seen_at,
              last_seen_at, last_ip, last_country, trusted_until  ← replaces trusted_devices,
              revoked_at)
login_events (id, user_id NULL for failures, email_hash, event
              login_ok|login_failed|twofa_ok|twofa_failed|refresh|register|
              logout|reuse_detected, device_id, fingerprint_hash, components JSON,
              ip, country, region, city, asn, is_hosting, user_agent,
              risk_score, risk_reasons JSON, created_at)
account_links (id, user_a, user_b, signal device_id|fingerprint|ip_fp,
              strength, first_seen_at, last_seen_at, reviewed_by, verdict)
```

Retention: `login_events` 12 months (then aggregated), raw IPs 90 days, as
the privacy notice will say.

### Suspicious-login scoring (on login and refresh)

| Signal | Points |
| --- | --- |
| Device id never seen for this account | +30 |
| Fingerprint never seen | +15 |
| New country for this account | +30 |
| Impossible travel (distance / time since last login > 900 km/h) | +40 |
| Hosting/VPN ASN | +10 |
| Time zone doesn't match IP country | +10 |
| Several failed passwords in the last hour | +20 |

- **≥ 40**: require the email code even on a trusted device, and email
  "New sign-in from Chrome on Windows near Lisbon, Portugal — not you?" with a
  one-click **"Not me — sign out everywhere and reset password"** link.
- **≥ 70** or refresh-token reuse: revoke the family, force a password reset.
- Profile page: **"Your devices"** — label, place, last active, "This device",
  sign out one / all (replaces "Forget all devices").

### Multiple accounts from one person

On register and login, look up other accounts with the same device id, the
same fingerprint, or the same IP + fingerprint within 30 days, and record an
`account_links` row. Policy (proposal):

- **Never auto-block on a fingerprint alone** — families share laptops,
  hostels and offices share IPs, mobile carriers put thousands behind one IP
  (CGNAT), and identical phones produce near-identical fingerprints.
- **Block registration** when the device id belongs to a frozen/banned
  account (ban evasion) — with a "contact support" message.
- Everything else is **flagged for staff** in the CMS (user page → "Linked
  accounts", with the shared signals), plus a ranking/visibility rule later.
- ID verification (`docs/ai-and-location.md`, phase C) remains the real
  answer: one verified document = one account.

### Legal (needs the lawyer)

The cookie notice currently says the app stores only strictly necessary data
(ePrivacy, no consent asked). The sign-in cookies and a security device id
are arguably strictly necessary for a secure sign-in, and canvas/WebGL — the
contested part (EU ePrivacy art. 5(3), the UK ICO's position against
fingerprinting) — is left out. Still to do: list the two cookies and the
device data in terms section 9 and the cookie notice (bumping
`NOTICE_VERSION`), state the purpose (account security and fraud
prevention), and document retention. Hashing doesn't take the data out of
GDPR: an identifier that recognises a person is pseudonymous personal data.
Users see their devices and login history on the profile page (transparency).

---

## 3. CMS

### Shape

| Piece | Where | Notes |
| --- | --- | --- |
| **Vue app** | `admin/` (the existing placeholder) — its own `package.json`, Vite build, Pico, English-only UI | served at `admin.belikelocal.club` (dev: `localhost:5174`, added to `WS_EXTRA_ORIGINS` only if it ever uses the socket) |
| **CMS API** | `cms/` — a PHP service like `api/` (`cms/index.php`, `CmsProcessor`, `ServiceHttp`), container `lemp-rg-cms` | plain HTTPS JSON (`POST /api` with `{ action, … }`), no WebSocket needed |
| **Auth** | the same auth service and keys; tokens with `aud: cms` and `roles: ["manager"]`; `/auth/*` on the CMS origin, cookie `__Host-rg_cms_refresh` | staff accounts are separate from app accounts |

- **Staff accounts are separate from app accounts** (decided 2026-10-20):
  `cms_users` (exists) + a new `cms_creds` (same salt+pepper+bcrypt as
  `creds`), their own ids, never in `users`; a person who is also a traveller
  or guide has two accounts. `two_factor_method` **mandatory** (email code
  now, TOTP app later), refresh lifetime 12 hours (no 30-day remember-me).
  Auth actions `cmsLogin`, `cmsVerifyTwoFactor`, `cmsRefresh`, `cmsLogout`.
  An app token (`aud: app`) is rejected by the CMS and vice versa, and the
  token's `sub` is prefixed (`staff:12`) so a staff id can never be read as
  an app user id.
- **Roles**: `admin`, `manager`, `editor` (the `roles` table has `admin`,
  `manager`, `viewer` — `viewer` becomes `editor`). The first admin is created
  by a CLI script (`php cms/bin/create-admin.php email`).

### Permissions

| Capability | admin | manager | editor |
| --- | :-: | :-: | :-: |
| UI texts: edit translations, add keys | ✓ | ✓ | ✓ |
| Legal documents: edit, publish a new version | ✓ | ✓ | — |
| Complaints & disputes: view, resolve | ✓ | ✓ | — |
| Refunds on bookings and packs | ✓ | ✓ | — |
| App users (travellers and guides): search, view, edit all data, levels, sanctions, badges, bonuses, verification | ✓ | ✓ | — |
| App users: freeze / unfreeze, delete, login history, linked accounts | ✓ | ✓ | — |
| **Staff accounts**: add / remove admins, managers, editors; change their roles and permissions | ✓ | — | — |
| Audit log | ✓ | everything except staff management | own actions |

The manager runs the whole user-facing operation; the only thing reserved
for admins is the staff itself. Refunds are recorded the same way as today's
simulated payments (`payment_status = 'refunded'`, `refund_amount`) until a
real payment provider exists — then the CMS action calls its refund API.

Enforced in `cms/classes/Permissions.php` (action → minimum capability), and
mirrored in the UI only to hide what would be refused. **Every write is
audited**: `staff_audit (id, staff_id, action, target_type, target_id,
before JSON, after JSON, ip, created_at)`.

### Content editor (translations)

**Pages and blocks come from the code**, so the CMS never drifts from the
app: extend `scripts/i18n-scopes.mjs` to also write `client/src/i18n/layout.json`:

```json
{
  "discover": {
    "route": "/discover",
    "regions": {
      "header":  ["common.app_header"],
      "aside":   ["common.filter_panel", "common.location_filter"],
      "main":    ["discover.deck", "common.profile_card", "common.decision_row"],
      "side":    ["discover.matches_rail"],
      "footer":  ["common.app_footer", "common.cookie_notice"]
    }
  }
}
```

The region comes from a one-line comment per page (`<!-- cms-layout: aside -->`
before a component/section) with "main" as the default; block order is the
order of first use in the template. The CMS reads `layout.json` from the
deployed client (or the API serves it) — a page added to the app shows up in
the CMS after the next build. A **"Shared"** pseudo-page lists `common.*`
blocks not placed on any page (API messages, country names, …).

**UI:**

```
┌ Content ───────────────────────────────────────────────────────────┐
│ Page: [ Discover ▾ ]                                               │
│ ┌──────────────────────── header ───────────────────────────────┐  │
│ │ app_header                                                     │  │
│ ├────────────┬──────────────────────────────┬───────────────────┤  │
│ │filter_panel│ deck                         │ matches_rail      │  │
│ │location_   │ profile_card                 │                   │  │
│ │  filter    │ decision_row                 │                   │  │
│ ├────────────┴──────────────────────────────┴───────────────────┤  │
│ │ app_footer · cookie_notice                                     │  │
│ └────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────┘
```

Clicking a block name opens the form (a side panel or modal):

```
┌ Discover > deck ──────────────────────────────── [ English ▾ ] ┐
│ title            [ Guides for you                            ] │
│ subtitle         [ Local guides, wherever you travel         ] │
│ picks_left_one   [ {count} pick left                         ] │
│ picks_left_other [ {count} picks left                        ] │
│ …                                                               │
│ + Add key   [ key      ] [ value                       ] [Add] │
│                                    [ Cancel ]  [ Save changes ] │
└─────────────────────────────────────────────────────────────────┘
```

- Labels show only the final key segment (`title`, not `discover.deck.title`).
- The language switcher (top right of the form) reloads the values for that
  language; empty fields show the English text as a placeholder and are
  marked "missing". Unsaved changes warn before switching.
- **Validation on save**: every `{placeholder}` in the English value must be
  present in the translation (and no unknown ones); plural keys required by
  the language (`_few`/`_many` for ru/uk, all six for ar) are listed together;
  no HTML.
- **Add key**: the final segment only (`[a-z0-9_]+`), prefixed with
  `page.block.` automatically; the English value is required before other
  languages. A key nobody uses in code is harmless but does nothing — the
  form says so ("developers reference it as `discover.deck.<key>`").
- Saves are `translations` upserts + `staff_audit`; `translations_history`
  keeps old values for one-click revert. No cache to clear today
  (`Translations::forLanguage()` reads MySQL); if one is added, the CMS
  publishes `translations:changed`.
- `en.json` (the bundled English fallback) drifts once English is edited in
  the CMS: add `yarn i18n:pull` (DB → `en.json`) to the release steps.

### Users

**List / search** (`/users`): one search box that recognises an id (`#123`
or digits), an email, a phone (digits, any formatting), or a name; filters for
role, location (place search → within N km of a guide's base / a traveller's
area, or a country), level range per role, verified, frozen, created between;
sortable columns, 50 per page, server-side (`cms` action `searchUsers`).
Each row: photo, name, email, roles, levels, location, created, status.

**User page** (`/users/:id`, reachable from a result or directly):

| Section | Edit |
| --- | --- |
| Account | name, email, phone, photo (remove), 2FA method |
| Roles | add/remove guest/guide; headline & bio per role |
| Profile | date of birth, gender, price fields, categories |
| Levels | earned level & points per role (read-only, they come from trips); **adjustments**: sanctions (−N levels, reason, expiry) — and a proposed `level_adjustments` with a signed delta so staff can also *award* levels |
| Badges & achievements | derived ones shown (verified, top rated, trips, volunteer); new `user_badges` table for manually awarded ones (e.g. "Founding guide") with label, icon, expiry |
| Bonuses | `bonus_grants`: list, add (kind, days, reason), extend, revoke |
| Verification | status, method, date; set / clear; later the document & selfie review (phase C) |
| Security (admin) | devices, login history with places, linked accounts |
| Activity | matches, trips, reviews written/received, complaints by/about |
| **Freeze** | a days field (temporary) or a **"permanently"** checkbox, plus a required reason |

**Freezing** (`user_freezes (id, user_id, starts_at, ends_at NULL = permanent,
reason, staff_id, lifted_at, lifted_by)`):

- auth refuses login and refresh while a freeze is active ("Your account is
  suspended until …"); the freeze publishes on `auth:revoked`, so open
  sessions stop within seconds, not 15 minutes;
- the user disappears from decks, the planner and public profiles; chats
  become read-only for the other side;
- open bookings are **not** changed automatically: the freeze dialog lists
  the user's pending and confirmed bookings, each with the manager's choice
  — leave it, decline/cancel with a full refund, or cancel with a partial
  refund (amount field) — and the other side is notified of whatever is
  chosen. Bookings left open stay on the user page as "needs a decision";
- a device belonging to a frozen account can't register a new one (§2).

---

## Roadmap

| # | Phase | Contents | Depends on |
| --- | --- | --- | --- |
| 1 ✓ | **Tokens, in parallel with sessions** — built 2026-10-20 | Ed25519 keys + env, `lib/Jwt.php`, `refresh_tokens`; `POST /auth/*` HTTP endpoints on the app's origin (Vite proxy in dev, nginx route in production) setting the HttpOnly refresh and device cookies; login/2FA/register return an access token *and* the session hash; gateway and services accept either; client keeps the access token in memory, refreshes under a cross-tab lock, re-binds the socket | — |
| 2 ✓ | **Refresh security** — built 2026-10-20 | rotation, reuse detection, 30 s grace; the `auth:revoked` list (by sid, gateway only — the services are behind it); sign out on all devices | 1 |
| 3 ✓ | **Client IP + devices** — built 2026-10-20 (geo lookup optional, off by default; `trusted_devices` not merged yet) | nginx → gateway → services IP header, `devices`, `login_events`, device info from the client, GeoLite2, "Your devices" on the profile page; `trusted_devices` merged into `devices` | 1 |
| 4 ✓ | **Risk scoring & alerts** — built 2026-10-21 (without the geolocation signals and the ban-evasion check, which wait for GeoLite2 and freezing; the privacy notice still needs the lawyer) | scores, step-up 2FA, "new sign-in" email with "not me", `account_links`, ban-evasion check at registration; privacy notice + cookie notice update | 3, lawyer |
| 5 ✓ | **Retire sessions** — done 2026-10-21 | remove the session hash path, `resolve`/`peekMany`, `Session.php`'s request role; Redis keeps pub/sub, counters, caches | 2 |
| 6 ✓ | **CMS foundation** — built 2026-10-21 (email codes; TOTP later) | `cms/` service + container, `admin/` Vue app, staff accounts with mandatory 2FA, `aud: cms` tokens, permissions, `staff_audit`, create-admin script | 1–2 |
| 7 ✓ | **Content editor** — built 2026-10-21 (regions are header / page / shared / footer from the imports, not per-component comments; `i18n:pull` is `api/bin/pull-english.php`) | `layout.json` generator, page wireframe, block form with language switcher, add key, validation, history/revert, `i18n:pull` | 6 |
| 8 | **Users** | search/list, user page, sanctions/adjustments, badges, bonuses, verification, freeze (+ booking handling), security tab | 3, 6 |
| 9 | **Moderation** | complaints/disputes queue and resolution, linked-accounts review, audit log viewer | 4, 8 |

Phases 1–2 are about a week of work each including tests; 6–8 are the bulk
of the CMS. 3–4 can run alongside 6–7.

**Tests to add**: `lib/tests/JwtTest.php` (sign/verify, expiry, wrong key,
wrong `aud`, tampered payload, `kid` rotation); refresh rotation and reuse;
the gateway rejecting forged/expired tokens without calling auth; client
`api.spec.ts` for refresh-and-retry and the cross-tab lock; CMS permission
matrix (every action × role).

## Decisions (2026-10-20)

- Managers manage everything about app users, disputes, sanctions, bonuses,
  refunds and legal documents; only staff accounts are admin-only. Editors
  edit UI texts only.
- Freezing leaves open bookings to the manager's decision.
- The refresh token lives in an HttpOnly cookie from the start (sign-in
  actions move to same-origin HTTP); the access token in memory only.
- No canvas/WebGL fingerprinting.
- Staff accounts are separate from app accounts.

No open questions left; the lawyer's review of the privacy notice gates
phase 4 only.
