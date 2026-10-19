# Location, travel planner and document verification

Three features asked for on 2026-10-19, planned here, not built yet:

1. **Location as a search filter** — on login the app reads the device's
   location and searches near it; the user can move the search point (to
   where they're going) to rank guides there higher.
2. **Travel planner** — a traveller enters their itinerary (places + dates)
   and gets up to 4 suggested guides per stop.
3. **Document verification** — upload an ID card or passport plus a selfie;
   the data read from the document is offered for the profile (the user
   chooses what to use), and the account gets the Verified badge.

Order: **location first** (the planner can't work without it), then the
planner; verification is independent and can run alongside, but has legal
prerequisites that take calendar time, so start those now.

## What exists today

- **Distance isn't real.** `distance` (047) is a `range` category: each user
  stores a span (0–5 km) on their profile and the filter checks span overlap.
  Nobody has coordinates, so it measures nothing. It gets replaced.
- **No location on anyone.** Guides have no base or area; the product copy
  still says "Gibraltar". A multi-city planner means guides anywhere.
- **No availability calendar.** Guides have working hours (`hour_of_day`), not
  dates. Meeting times are free-form (see "Known gaps" in CLAUDE.md).
- **Verification is manual**: `users.verified_at` + `verification_method`
  (034), set in SQL; worth +30 in the ranking; `ProfilePage` shows the status.
- Chat already reads the device location once (`useCurrentPosition.ts`), and
  map pins already exist for meeting places.
- Limits that matter: a WebSocket message is ≤ 4 MB (gateway), service
  requests time out at 25 s, and only `/ws` is public.

## Phase A — location

**Data**
- `user_locations`, or columns on `user_profiles`: a guide's **base** — place
  label, country code, `POINT SRID 4326` with a spatial index — and a
  **service radius** (km, default 25). Stored rounded to ~1 km so a guide's
  home isn't exact. Guides set it on the profile page (place search, or "use
  where I am now").
- A traveller's location is **not stored by default**: it rides on each deck
  request as `near: { lat, lng, label }`. (Saved trip stops, phase B, are the
  stored kind.)

**Search**
- `Users::searchByRole()` takes `near` and an optional `maxKm`:
  `ST_Distance_Sphere(base, near)` with a bounding-box prefilter for the
  index. Unlimited (the existing switch) = no distance cutoff.
- **Proximity in the ranking** (`Ranking.php`): something like up to +20 for
  being within the guide's own radius, fading with distance — so moving the
  search point really reorders the deck, without hiding everyone a bit
  further away.
- Cards show a rounded distance ("~3 km away"), never coordinates. A guide
  with no base set is shown last when searching near somewhere, with no
  distance.
- Drop the `distance` category (and its stored spans) in the same migration;
  the filter becomes a single max-distance slider driven by `near`.

**Client**
- After login (and on boot with a session), ask for the location once through
  `useCurrentPosition` → reverse-geocode the label → `filtersStore.near`.
  Denied or unavailable → no `near`, deck as today, plus a "Set location"
  prompt. The browser shows its own permission prompt; we explain why first
  (a one-line banner), since a cold prompt on login is often denied.
- A **location pill** above the deck ("Near: Lisbon · Change"), opening a
  place search (and "Use my current location"). Shown as a pill with the
  other `ActiveFilters`; persisted per tab (sessionStorage), not forever.
- Also in the URL as `?near=lat,lng` so a link reproduces the search.
- The guide's deck (guides browsing visitors) uses the other direction:
  visitors whose **current location or planned stop** is within the guide's
  radius — the planner's stops make this genuinely useful.

**Geocoding** (place name ↔ coordinates, autocomplete) needs an external
service; one server-side proxy action (`geocode`, `reverseGeocode`) so the key
stays on the server and results are cached in Redis. Choice below.

## Phase B — travel planner

**Input**
- A list of **stops**: place (the same place search) + arrival and departure
  dates (1–14 stops, within the next 12 months).
- Optional **free-text mode**: "Lisbon 3–5 May, then Porto until the 9th" →
  Claude turns it into stops → each is geocoded → the user confirms or edits
  the list before searching. The AI never supplies coordinates; the geocoder
  does.

**Matching, per stop**
1. SQL picks candidates: guides whose base is within their radius of the stop
   (fallback: nearest within 50 km), not the traveller themselves, not
   already decided on, not on a started trip, holding the guide role.
2. Ordered by the existing ranking + proximity + overlap with the traveller's
   interests and languages (their guest-role picks).
3. **Claude re-ranks the top ~12** against the traveller's interests and each
   guide's headline/bio/specialities, and writes one short line per pick
   ("Knows the Alfama food scene; speaks your language"). It only orders and
   explains guides SQL already found; it can't add anyone.
4. Up to **4 per stop**. Fewer is shown honestly ("Only 2 guides near
   Porto so far").

**Storage and flow**
- `trip_plans` (user, title, created) and `trip_plan_stops` (plan, place
  label, point, from, to, suggestions as JSON with the explanation and when
  they were computed). Saved, so the traveller can return to it.
- New page `/planner` (traveller mode): stops editor → results per stop with
  the usual `ProfileCard`s → **Interested** is the same `decide()` as the deck
  (a pick, under the same pack rules — see decisions).
- Generating runs as a **job**, not inside the request (several geocodes + an
  AI call can exceed 25 s): `planTrip` returns `{ planId, status: 'pending' }`,
  a worker fills it, and a push (`{ type: 'trip_plan', planId }`) updates the
  page. Same pattern as the email outbox; the scheduler or a small `ai` worker
  runs it.
- Stops feed phase A's guide deck (guides see visitors coming to their area,
  with dates) and can later trigger "a new guide joined near your stop".

**Not included**: a calendar of guide availability (dates are used for
filtering out trips under way and for display; a real availability calendar
is a separate feature), itinerary suggestions (what to see), bookings from
the planner (it leads into the normal match → chat → pay flow).

## Phase C — document verification

**Flow**
1. Profile page → "Verify your identity" → choose document type → photograph
   the front (and back for an ID card) → take a live selfie. Images are
   compressed in the browser (≤ 1 MB each) and sent once.
2. Server checks: the document is real and unexpired, the selfie is a live
   person (liveness), and the face matches the document photo.
3. Data read from the document — name, date of birth, sex, nationality,
   expiry — comes back to the client, which shows "Use these details in your
   profile?" with a checkbox per field (prefilled where it differs). Accepting
   saves through the existing `updateAccount`/`updateProfile`.
4. Pass → `verified_at`, `verification_method`, the badge and +30 ranking.
   Fail → a reason the user can act on ("photo too blurry", "document
   expired") and a retry; after 3 failures, manual review.

**Data and privacy** (this is the heavy part)
- A selfie compared with a document photo is **biometric data** (GDPR
  Art. 9): explicit consent on the screen, a DPIA, the provider as a named
  processor in the privacy notice (`legal_placeholders` → processors), and a
  new terms/privacy version.
- **Images are deleted** as soon as the check finishes (hours, not days). We
  keep: result, method, document country, expiry, the check's reference, and
  a **keyed hash of the document number** — so one document can verify only
  one account (stops sock puppets farming levels and reviews, which the
  ranking notes already flag).
- Verification ties to the identity: changing the date of birth (or the name
  substantially) after verifying removes the badge until re-verified. An
  expired document doesn't remove it (verified once is verified).
- `identity_checks` table (user, status `pending | processing | passed |
  failed | review`, provider ref, reasons, extracted fields until the user has
  answered, timestamps). Results arrive by **webhook** from the provider, so
  a public `/webhooks/verification` route (signature-checked) is needed
  beside `/ws`.

**Who does the checking — see decision 1.** A general AI model can read the
fields off a document well, but it is not a document-authenticity or
liveness/face-match system: it can't reliably tell a printed photo of a
passport from a real one, or a photo of a photo from a live selfie, and
face-matching people is outside what Claude is meant to be used for. A
verification provider does all three and returns the extracted fields.

## AI integration (shared)

- **Claude API**, called from PHP with plain curl to the Messages API (no
  Composer): `lib/Ai.php` with structured JSON output, timeouts, retries,
  token/cost logging per call (never the prompt's personal data in logs).
  `ANTHROPIC_API_KEY` in `localguideapp.env`.
- Models: `claude-haiku-5-5` for itinerary parsing (cheap, fast),
  `claude-sonnet-5-5` for the re-rank and explanations.
- Only what's needed leaves the server: guides' public headline/bio/tags and
  the traveller's interests — no names, contact details or ids beyond an
  opaque index. Add Anthropic as a processor in the privacy text.
- Per-user rate limits (e.g. 10 plans a day) and a cache on identical
  requests. A failed AI step degrades to the plain SQL order without
  explanations, never to an error page.

## Decisions needed

1. **Verification checks** — *recommended:* a verification provider
   (Stripe Identity fits if real payments go through Stripe later; Veriff,
   Onfido, Persona are the alternatives) doing authenticity + liveness + face
   match + extraction. *Alternative:* Claude reads the document, and every
   upload goes to a person in a manual review queue before the badge.
2. **When the badge appears** — you asked for "upon document upload".
   *Recommended:* when the check **passes** (usually under a minute with a
   provider), showing "Verification in progress" until then. A badge on
   upload alone would let anyone get +30 and a ✓ with any picture.
3. **Geocoder** — Google Places (best place search, paid per request),
   Mapbox (cheaper, good), or OpenStreetMap via a hosted Nominatim such as
   LocationIQ (cheapest; weaker autocomplete).
4. **Guide area** — *recommended:* one base point + radius. Alternative:
   several areas per guide (more flexible, more UI).
5. **Do planner suggestions cost picks?** *Recommended:* yes, same as the
   deck — Interested from the planner is the same decision.
6. **Location prompt** — on every login, or once and then reuse until
   changed (recommended: ask once per session, explain first).
7. **Beyond Gibraltar?** Multi-city search and the planner assume guides
   anywhere; the marketing copy and `index.html` title would change.

## Phases and order

| Phase | Contents | Depends on |
| --- | --- | --- |
| A1 | Guide base + radius on the profile, geocoder proxy, migration dropping `distance` | decision 3, 4 |
| A2 | `near` in search + proximity in ranking + distance on cards | A1 |
| A3 | Login location, location pill, `?near=`, guide deck by visitor location | A2 |
| B1 | `lib/Ai.php`, job worker, `trip_plans` tables | — |
| B2 | Planner page, stops editor, matching + re-rank, results, push | A2, B1 |
| B3 | Free-text itinerary, stops on the guide deck | B2 |
| C0 | Provider contract, DPIA, privacy/terms version, consent copy | decisions 1, 2 |
| C1 | `identity_checks`, webhook route, upload flow, badge | C0 |
| C2 | "Use these details" prompt, re-verification rules, duplicate-document guard | C1 |

Each phase: migration + translations (15 languages) + tests + a live check
with throwaway accounts, as with dual roles.
