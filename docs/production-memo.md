# Production memo: development stand-ins

Everything below works well enough to build and test the app, but is a
temporary solution. Each entry says what it is now and what to do before
real users and real money. Add to this list whenever a shortcut is taken.

## Money

| Now | For production |
| --- | --- |
| **Pack purchases are simulated**: `Payments::recordPackPurchase()` inserts a `paid` row; no card is charged. | A payment provider (Stripe Checkout / Payment Intents). Record the purchase from the provider's webhook, not from the client's request. |
| **Guide fees, refunds and commission are recorded, never moved** (`Bookings::pay()`, `cancelByGuide()`, `approveCancellation()`, `commission_*`). | Stripe Connect (or similar) for marketplace payouts: hold the fee, pay the guide after the trip minus commission, refund through the provider. Guides need onboarding (KYC by the payment provider). |
| Prices are GBP only. | Decide currencies for a global service; store currency per booking. |

## Identity and verification

| Now | For production |
| --- | --- |
| **Document checks by Claude** (planned, `docs/ai-and-location.md` phase C): Claude reads the document's fields and judges whether the images look genuine. It does **not** compare the selfie with the document photo; face matching isn't something Claude is used for. | A verification provider (Stripe Identity, Veriff, Onfido, Persona) for document authenticity, liveness and the face match. Keep the same `identity_checks` table and result shape so only the checker changes. |
| Verification can also be set by hand in SQL (`users.verified_at`). | A moderation screen (see "Admin"). |
| No DPIA, no biometric consent text, no processor list. | A DPIA, explicit consent on the upload screen, the verification provider and Anthropic named as processors, a new privacy/terms version. |

## Messaging and email

| Now | For production |
| --- | --- |
| **`MAIL_FORWARDS`** (server env): `igor@belikelocal.club` → a personal Gmail, because belikelocal.club has no mail service yet (added 2026-10-21; the CMS admin account uses that address). | A real mailbox (or the provider's forwarding) for belikelocal.club, then delete the pair — the setting is meant to end up empty. |
| **Gmail SMTP with an App Password** (`Mailer.php`), about 500 emails a day. | A transactional email service (Postmark, SES, Mailgun) with SPF, DKIM and DMARC on the sending domain; bounce/complaint webhooks. |
| **`MAIL_REDIRECT_TO`** sends every email to one inbox. | Remove it from the production env file. |
| **SMS / WhatsApp two-factor are disabled** "(coming soon)"; codes go by email only. | An SMS/WhatsApp provider (Twilio, MessageBird), then enable the options in `ProfilePage.vue` and `actionUpdateAccount()`. |

## Location

| Now | For production |
| --- | --- |
| **Geocoding through Photon's public server** (`photon.komoot.io`, OpenStreetMap data, free, fair use, no guarantee). | A paid or self-hosted geocoder: self-hosted Photon/Nominatim, or LocationIQ / Mapbox / Google Places. The proxy action keeps the client unchanged; only `Geocoder.php` changes. Check the provider's attribution and caching terms. |
| **Itinerary places are geocoded through the same Photon server**, one call per stop after Claude reads the text, within a 15-second budget. | Same replacement as above; a paid geocoder also makes the per-stop calls faster and more reliable. |
| Maps links open Apple/Google Maps; no map is drawn in the app. | If an in-app map is added, a tile provider with a production plan. |

## AI

| Now | For production |
| --- | --- |
| **Claude API called directly with one key** (`lib/Ai.php`, `ANTHROPIC_API_KEY` in `localguideapp.env`): the travel planner reads itineraries (`claude-haiku-5-5`) and ranks and explains guides (`claude-sonnet-5-5`). | A key per environment from a secrets manager, a global monthly spend cap set in the Anthropic console, alerting on the `AI call failed` logs and on cost per feature (`input_tokens`/`output_tokens` are logged per call), a data-processing agreement, and retention settings reviewed. |
| **Per-user limits are simple Redis counters** (`TripPlanner::RUNS_PER_DAY` 10 plans, `PARSES_PER_DAY` 30 readings), not enforced if Redis is down. | Limits tied to plans/abuse signals, enforced fail-closed; caching identical plans (same stops, same candidates) to save calls. |
| **Anthropic isn't named as a processor** in the privacy text, and the planner sends guides' public profile text and the traveller's interests/languages to it. | Add Anthropic to `legal_placeholders` (processors) and the privacy notice in a new terms version. |
| **The planner worker is one container** (`lemp-rg-planner`, `api/bin/planner.php`). | Run two or more replicas (claims are `SKIP LOCKED`); scale on queue length. |

## Legal

| Now | For production |
| --- | --- |
| **Sign-ins are scored and linked**: unfamiliar browsers get an emailed code, owners get "new sign-in" emails, and accounts sharing a browser or fingerprint are recorded in `account_links` — none of it in the privacy notice yet. | Same update as below (automated security checks, what's linked and why, that staff review it), reviewed by the lawyer. |
| **Device data and the sign-in history are collected** (browser, system, screen, time zone, languages, CPU/memory class as keyed hashes; IP 90 days; events 12 months) and two security cookies are set, but the privacy notice (terms section 9) and the cookie notice don't mention them yet. | Add them — purpose (account security, fraud prevention), retention, the user's own devices list — and bump `NOTICE_VERSION`; the lawyer reviews it (docs/auth-cms-plan.md §2, "Legal"). |
| **Terms, privacy notice and role agreements are drafts** (`docs/legal/`). | A lawyer reviews them in every language that will be offered. |
| **`legal_placeholders` are all NULL** (operator, controller, processors, DPO, EU/UK representative, authority, retention, governing law), shown as `[label]`. | Fill them: `UPDATE legal_placeholders SET value = …`. |
| Existing accounts never accepted any terms or role agreement. | A re-accept prompt on next login. |

## Admin and moderation

| Now | For production |
| --- | --- |
| **The CMS foundation and content editor exist** (staff sign-in with email codes, roles, staff management, audit log, UI texts), but complaints, sanctions and verification are still handled in SQL (CMS phases 8–9). Staff codes go by email, through the same Gmail stand-in. | The rest of the CMS; an authenticator-app (TOTP) or security-key second factor for staff instead of email codes; `admin.belikelocal.club` serving `admin/`'s build and routing `/auth/` to `auth/cms.php` and `/api` to `cms/index.php`, ideally behind an IP allowlist or VPN. |

## Infrastructure

| Now | For production |
| --- | --- |
| **IP → place is off** (`GEO_LOOKUP_URL` unset; any value sends users' IP addresses to that service). Devices and sign-ins have no place. | MaxMind GeoLite2 (free account + licence key) on our own servers, read locally — no address leaves them; replace `GeoIp::lookup()`'s HTTP call with it. |
| `TRUSTED_PROXIES` defaults to loopback + private ranges (the docker network). | Set it to the load balancer's ranges (on GCP: 35.191.0.0/16, 130.211.0.0/22), or every IP reads as the balancer's. |
| `FINGERPRINT_KEY` is in `localguideapp-auth.env`. | Secret Manager, auth only. Never rotate casually: a new key makes every device look new. |
| The token-signing key pair (`JWT_PRIVATE_KEY`, `JWT_PUBLIC_KEYS`, kid `2026-10`) is in the docker-lemp env files (the private one in `localguideapp-auth.env`, rg-auth only). | Secret Manager, the private key mounted into the auth service only; a key rotation every few months (new kid, old public key kept 90 days). |
| Development runs over HTTPS on the real names (www/admin/api.belikelocal.club) with a **self-made development certificate** (a local CA in `~/Docker/crt`, trusted on the developer's Mac) and Vite dev servers behind nginx. | A real certificate (Let's Encrypt / the load balancer's managed certificate) and the production builds; never ship or trust the development CA anywhere else. |
| The allowed origins (`lib/Origins.php`) are hard-coded: belikelocal.club (+ www, admin, api) and `localhost:5173`. | Drop `localhost:5173` from the production build of the list (or move it into `WS_EXTRA_ORIGINS` for development only). |
| **Grafana is open as an anonymous admin** on localhost. | Real logins, not exposed publicly; alerting on errors and failed emails. |
| Photos are base64 strings in MySQL (`users.image`). | Object storage (S3/GCS) with a CDN; store a URL. |
| One shared database for all services; one gateway holds about 1,000 sockets. | See `docs/scaling.md` (database split, several gateway replicas, GKE). |
| The app's routes come from the database at start-up (`appMap`); the build bundles a copy (`client/src/router/appMap.json`) pulled by hand, and the bundled English (`en.json`) likewise. | Run `api/bin/pull-app-map.php` and `api/bin/pull-english.php` (both have `--check`) as a release step, so the fallback copies match what's live; fail the build when `--check` does. |
| `INTERNAL_TOKEN` is one shared secret between services. | Private networking plus per-service credentials or mTLS. |
| `APP_URL` / `CMS_URL` are `https://www.belikelocal.club` / `https://admin.belikelocal.club` in the development env file (the code's fallbacks are `localhost`). | Set them in the production environment (email links depend on them). |
| `127.0.0.1 www. / api. / admin.belikelocal.club` in `/etc/hosts`; Vite dev servers. | Real DNS and TLS; a production build served by nginx/CDN that serves `index.html` for every `/<lang>/…` path. |
| No automated PHP tests or CI. | CI running the client tests, type-check, lint and the `lib/tests` scripts on every push. |
| Development seed accounts (`test_*@test.com`) and throwaway `@example.test` accounts in the database. | A clean production database; never copy the development one. |
| **100 demo accounts** (2026-10-20): `seed.guide.01–50@example.test` and `seed.guest.01–50@example.test`, password `aA123456`, no 2FA, with made-up profiles, finished trips, reviews, levels and bonuses. Their photos come from randomuser.me (UI Faces portraits, licence unclear). | Never ship them or their photos. Remove with `DELETE FROM users WHERE email LIKE 'seed.%@example.test'`, which cascades to everything they own (trips, reviews, matches, payments, picks). |
