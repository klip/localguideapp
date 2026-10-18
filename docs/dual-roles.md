# One account, both roles

Guides travel too. An account can hold **guest** and **guide** at once and
switches between them; everything it sees and does follows the role it is
currently acting as. Agreed 2026-10-18; built in phases (below).

## Decisions

1. **Mode switch**, not a merged view. "Become a guide" / "I want to travel"
   takes the user to their profile page, where a modal makes them accept that
   role's agreement; then the role-specific fields appear and the account
   switches into the new role.
2. **One profile** — name, photo, date of birth, gender, contact details,
   languages — with a **separate headline and description per role**. A
   person's public profile shows reviews per role in **tabs** (Guide /
   Traveller) when they hold both.
3. **Ratings are per role.** A deck card shows the rating for the role that
   person has in that deck; the public profile shows both, as above.
4. **Adding a role is gated** by its agreement (point 1).
5. **Two people can be matched both ways** (A guides B, B guides A — two
   separate pairs). **Levels progress only when real money is involved**, and
   a guide progresses slightly faster the more the trip was paid.

## What already fits

The pair tables record each side's role per pair, not per account:
`selections` (`guest_id`, `guide_id`, unique on the pair, so A→B and B→A are
two rows), `bookings` (same columns) and `reviews` (through the booking).
Roles live only in `user_role` (046), which can hold both. Packs and picks are
the guest side's only.

## What assumes one role per account

41 server and 64 client role checks — everything that asks "what is this
user?": `getRoleName()` in both services, `Selections::resolvePair()`,
`RequestProcessor`'s role gates, `Bookings`, `Levels`, `Users::searchByRole()`'s
viewer, and `auth.user.role` across the pages (`BookingPanel`, `MatchesPage`,
`ProfilePage`, `TripsPage`, `DiscoverPage`, …).

## The acting role

Every request says which role it acts as, the way it already carries the
session hash:

- `AuthResult` gains `roles: ('guest'|'guide')[]`; the client keeps
  `activeRole` (persisted with the session) and `plugins/api.ts`'s `call()`
  attaches it as `actingRole`.
- The gateway's `resolve` returns the user's roles along with the id; the
  gateway refuses an `actingRole` the user doesn't hold (403), defaults it
  when the user has only one, and forwards it to the api as
  `X-Acting-Role`.
- The api reads it with `RequestProcessor::actingRole()` everywhere it now
  calls `getRoleName($callerId)`. Other people's roles still come from
  `user_role` (`Roles::hasSql()`/`nameSql()`).
- `Selections::resolvePair()` takes the acting role: acting as guest, the
  target must hold *guide* and the pair is (me, them); acting as guide, the
  reverse. That is what makes two dual-role people unambiguous.

A single-role account never notices: its acting role is its only role.

## Data changes

| Change | Why |
| --- | --- |
| `user_role` gains `headline`, `bio`, `level` | per-role face and level; moved from `user_profiles.headline`/`bio` (guide/guest by the row's current role) and `users.level` |
| `attribute_categories.scope` `shared\|guest\|guide`; nullable `role_id` on `user_attribute_values` and `user_attribute_ranges` | "Interests" and "Specialities" are one category, so a dual-role user needs a set per role. Languages stay shared; hours are guide-only; food allergies and distance guest-only (to confirm per category) |
| `reviews.subject_role` (backfilled from the booking) | per-role averages, histograms, tabs and ranking |
| `legal_documents` slugs `guide_agreement`, `traveller_agreement` | the agreement accepted when adding a role (and when registering in it); acceptance recorded in `user_legal_acceptances` as today |
| `user_profiles` keeps the role-specific scalars | price/includes (guide) and party/duration (guest) don't overlap, so one row still holds both |

## Levels from real money

Today a trip qualifies if it was paid **or** the other person is verified,
and each other person counts once (`Levels::QUALIFYING_TRIP`,
`Ranking::tripsJoin()`). New rule:

- **Only paid trips count**, for both sides; free trips never progress
  anyone. Levels are **per role**. Pack purchases don't count — only trips.
- **Every paid, finished trip counts**, including repeat trips with the same
  person.
- **Guest progress**: 1 point per paid trip.
- **Guide progress**: weighted by the fee paid —
  `1 + 0.5 × min(1, amount / £100)`: a £20 trip is 1.1 points, £100 or more
  is 1.5. "Slightly more", capped, so price can't be gamed into levels.
- Thresholds stay as they are (1, 3, 6, 12, 24, 48, 96), now in points.
- **Volunteer**: a guide with finished trips but no paid one yet is labelled
  "Volunteer" instead of a level, still showing their number of trips. The
  first paid trip replaces the label with their real level.
- Existing levels don't need preserving (development data): recompute, or
  seed different levels for testing.
- The ranking's trips count and the trips badge follow the same rule, per
  role.

## UI

- **Header**: a mode switch (Traveller ⇄ Guide) shown only to dual-role
  accounts; switching re-fetches the page in the new role. Badges for the
  other role show as a dot on the switch.
- **Profile page**: shared fields always; the active role's headline,
  description and role fields; "Become a guide" / "I want to travel" when the
  account holds one role → agreement modal → role added, switched into it,
  its fields shown and focused.
- **Decks / Matches / Trips / Messages** follow the active role. A
  notification says which role it's about, and opening it switches mode.
- **Public profile**: tabs per role for reviews when the person holds both;
  the tab open first is the one matching the viewer's acting role (a
  traveller looking at someone sees their Guide reviews).

## Phases

1. **Acting-role plumbing** — `roles`/`activeRole` in auth, `actingRole` in
   `call()`, gateway check, every `getRoleName($caller)` and
   `auth.user.role` replaced. No visible change; ships alone. **Done**
   (branch `feature/dual-roles`), checked end to end with two accounts
   holding both roles, matched both ways.
2. **Adding a role** — agreements (drafted in 15 languages like the terms),
   `addRole` in auth, the header switch, role-aware profile page. **Done**:
   agreements 049/050 (drafts — have them reviewed), registration accepts
   terms + starting role's agreement, `addRole`, header switch / add-role
   link, profile page modal.
3. **Per-role data** — headline/bio/level on `user_role`, attribute scopes,
   `reviews.subject_role`, per-role ratings on cards and the profile tabs. **Done**
   except levels (moved to phase 4, with the money-based rule): headline/bio
   on `user_role`, attribute scopes, `reviews.subject_role`, per-role ratings
   and ranking, review tabs, role-aware filter bar and profile page.
4. **Money-based levels** — the new progress rule, per role, in `Levels`,
   `Ranking` and the badges.
5. **Polish** — role-tagged notifications that switch mode, per-role badge
   counts, copy.

## Resolved (2026-10-18)

1. Registering asks for the starting role's agreement too, alongside the
   terms.
2. Guide weighting `1 + 0.5 × min(1, fee / £100)`; free guides show their
   trips count and "Volunteer" until their first paid trip.
3. Existing levels can be recomputed or reseeded — development data.
4. Pack purchases don't count toward levels.
5. Every paid trip counts, repeat trips with the same person included.
