# Plan: guides' availability calendar

Status: **C1 and C2 built** (2026-10-22) — guides keep a calendar and visitors
book only free times; C3 ("Booked for your date" on the deck) and C4
(proposals, free guides) are still to do. Added 2026-10-21.

One change from the plan below: `hour_of_day` isn't migrated into calendars
automatically (its spans have no time zone, and a guide with a calendar is
bookable only inside it). The Calendar page offers those hours as a starting
point instead, and the filter stays until C3 replaces it.

Today a guide's working hours are a rough filter (the `hour_of_day` range
category, 44 guides / 52 spans) and the meeting time is free-form: the guest
picks any future date-time when paying, nothing checks it against the guide's
hours or their other bookings, and two guests can book the same guide for the
same morning (CLAUDE.md, "Known gaps"). This plan replaces that with a real
calendar:

- **A guide keeps a calendar** — weekly working hours, days off, and the
  bookings already in it.
- **A visitor can only choose times the guide is free.**
- **On the deck, a guide whose schedule is full for the visitor's date and
  time is still shown, labelled "Booked for your date"**, below the guides
  who are free.

---

## 1. Data

All times are in the **guide's own time zone** (where they guide), stored as
local wall-clock times plus an IANA zone, and converted for the visitor. A
booking's `meeting_at` stays UTC.

| Table | What |
| --- | --- |
| `guide_calendars` | one row per guide: `time_zone` (IANA, e.g. `Europe/Lisbon` — from the browser when they first save, editable), `slot_minutes` (start-time step, default 30), `buffer_minutes` (gap kept free between trips, default 30), `default_trip_hours` (used when a guest doesn't give a length, default 3), `min_notice_hours` (how soon a booking may start, default 12), `max_days_ahead` (default 180) |
| `guide_weekly_hours` | `user_id, weekday (1–7), start_minute, end_minute` — one or more windows per weekday ("Mon 09:00–13:00, 16:00–20:00") |
| `guide_days_off` | `user_id, day (local date), all_day` or with `start_minute/end_minute` — holidays, a free morning, or **extra** windows (`kind` = `off` \| `extra`) |

**Busy time is derived, not stored**: every booking that holds a slot —
`pending` (until accepted, declined, withdrawn or expired) and `confirmed`
not yet finished or cancelled — occupies `meeting_at` →
`meeting_at + duration (or default_trip_hours) + buffer`. No separate
"blocked" table to keep in sync.

`hour_of_day` is retired: a migration turns each guide's spans into weekly
windows on all seven days (in their base's time zone), and the category is
dropped from the profile page and the filter bar (replaced by the date filter
below).

## 2. Availability engine (`api/classes/Availability.php`)

- `freeWindows(guideId, fromUtc, toUtc)` → the guide's working windows in
  that range (weekly hours + extra windows − days off), minus busy time, minus
  anything before `now + min_notice_hours` or past `max_days_ahead`.
- `startTimes(guideId, day, durationHours)` → the start times on that local
  day where a trip of that length (+ buffer) fits, on the `slot_minutes` grid —
  what the visitor's picker shows.
- `isFree(guideId, startUtc, durationHours)` → the check `Bookings::pay()`
  (and `setMeetingTime`, below) run **inside a transaction holding a
  per-guide lock** (`GET_LOCK('guide_calendar_<id>')`), so two guests paying
  for the same slot at the same moment can't both get it — the second gets
  "That time was just taken — pick another."
- `availabilityFor(guideIds[], startUtc, durationHours)` → per guide `free` |
  `booked` (inside their working hours, but busy) | `off` (not working then),
  in one query for a whole deck page.
- Daylight-saving changes are handled by converting local windows per date
  (PHP `DateTimeZone`), never by adding fixed offsets.

## 3. Guide side — "My calendar"

- A **Calendar** page (or a section of the profile page, guide mode): the
  weekly hours as a grid of windows per weekday (add / edit / remove,
  "copy Monday to weekdays"), the settings above, and a **month view**
  showing days off, extra windows and every booking (pending ones marked as
  waiting for their answer), with a click to add a day off.
- A guide with **no calendar yet** behaves as today (free-form times) until
  they set one, with a banner asking them to — so nothing breaks on launch.
  After a set date (say 60 days) a calendar becomes required to receive paid
  bookings.
- Accepting a pending booking still works as now; declining frees the slot at
  once.

## 4. Visitor side — booking a time

- `BookingPanel`'s pay form replaces the free date-time input with a **date
  picker that only enables days with a free start time**, then the **start
  times for that day** (for the chosen trip length), shown in the visitor's
  zone with the guide's local time beside it when they differ ("10:00 your
  time · 11:00 in Lisbon").
- New API actions: `guideCalendar` (`guideId, month, durationHours` → the
  days with free time) and `guideStartTimes` (`guideId, day,
  durationHours`). Both need a session and a match with the guide — a
  calendar isn't public.
- `Bookings::pay()` refuses a time that isn't free (`Availability::isFree()`,
  under the lock). The trip length becomes required when the guide has a
  calendar (default: their `default_trip_hours`).
- **Proposing another time** (today's gap: the guide can only accept or
  decline): with calendars in place, the guide can **suggest a different free
  slot** on a pending request (`bookingAction` op `proposeTime`), which the
  guest accepts or rejects — the fee stays held meanwhile.
- **Free guides** (no fee): matching opens a booking without a time, as now;
  either side can then **set a meeting time** from the calendar
  (`setMeetingTime`), which holds the slot like a paid booking.

## 5. Deck — "Booked for your date"

- The traveller's filter bar gets **"When"**: a date (and optional time of
  day and trip length), next to the location filter; kept per role, per
  session, and in the URL like `near` (`?on=2026-11-14T10:00&hours=3`). The
  trip planner's stop dates feed it automatically for that stop.
- `Users::searchByRole()` takes `on` + `hours` and asks `Availability::
  availabilityFor()` for the page's guides:
  - **free** — ranked as now;
  - **booked** — inside their working hours but already taken for that time:
    **still shown**, labelled **"Booked for your date"** on the card (and on
    their profile when opened from the deck), and ranked **after** every free
    guide. Interested still works (they can match and agree another time);
  - **off** — not working then (day off, outside their hours): shown last,
    labelled **"Not available that day"** — *to confirm: show with a label, or
    hide?*
  - a guide **without a calendar** is treated as free (no information), as
    `hour_of_day` treats guides who never set hours today.
- With a date but no time, "booked" means **no free start time that day** for
  the chosen length.
- Rows carry `availability: 'free' | 'booked' | 'off' | null`; `ProfileCard`
  shows the label (translated, 15 languages — `common.availability.*`).

## 6. Edge cases

- **Overlaps across pairs**: today's unique `open_pair` only stops one pair
  holding two open bookings; the calendar lock is what stops a guide being
  double-booked by different guests.
- **Pending requests hold their slot** until answered or swept; a guide with
  many unanswered requests may look "booked" — the 24 h answer reminder (to
  add) keeps that short.
- **Freezes** (phase 8): a frozen guide's bookings are decided by staff; their
  calendar is irrelevant while they're off the deck.
- **Time zones**: the zone comes from the guide's browser on first save
  (`Intl.DateTimeFormat().resolvedOptions().timeZone`), checked against their
  base's country; a mismatch is flagged on the calendar page.

## 7. Phases

| # | Phase | Contents |
| --- | --- | --- |
| C1 ✓ | **Calendar data + editor** | tables, `Availability` engine with tests (DST, buffers, overlaps), the guide's Calendar page, `hour_of_day` migrated to weekly hours |
| C2 ✓ | **Booking against the calendar** | `guideCalendar` / `guideStartTimes`, the date + start-time picker in `BookingPanel`, `pay()` validation under the lock, trip length required with a calendar |
| C3 | **"Booked for your date" on the deck** | the When filter (URL, per session), `availabilityFor()` in `searchByRole()`, labels and ranking, planner stops feeding it |
| C4 | **Proposals + free guides** | `proposeTime` on pending requests, `setMeetingTime` for free bookings, answer reminders, calendar required for paid bookings after the grace period |

Open questions: hide or label guides who are *off* on the chosen day (§5);
the buffer and minimum-notice defaults; whether a guide may accept
overlapping bookings by hand (e.g. two small groups) — proposed: no, one trip
at a time.
