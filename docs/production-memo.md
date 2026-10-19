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
| **Gmail SMTP with an App Password** (`Mailer.php`), about 500 emails a day. | A transactional email service (Postmark, SES, Mailgun) with SPF, DKIM and DMARC on the sending domain; bounce/complaint webhooks. |
| **`MAIL_REDIRECT_TO`** sends every email to one inbox. | Remove it from the production env file. |
| **SMS / WhatsApp two-factor are disabled** "(coming soon)"; codes go by email only. | An SMS/WhatsApp provider (Twilio, MessageBird), then enable the options in `ProfilePage.vue` and `actionUpdateAccount()`. |

## Location

| Now | For production |
| --- | --- |
| **Geocoding through Photon's public server** (`photon.komoot.io`, OpenStreetMap data, free, fair use, no guarantee). | A paid or self-hosted geocoder: self-hosted Photon/Nominatim, or LocationIQ / Mapbox / Google Places. The proxy action keeps the client unchanged; only `Geocoder.php` changes. Check the provider's attribution and caching terms. |
| Maps links open Apple/Google Maps; no map is drawn in the app. | If an in-app map is added, a tile provider with a production plan. |

## AI

| Now | For production |
| --- | --- |
| Claude API called directly with one API key (planned). | Per-user and global spend limits, monitoring of cost per feature, a data-processing agreement, and retention settings reviewed. |

## Legal

| Now | For production |
| --- | --- |
| **Terms, privacy notice and role agreements are drafts** (`docs/legal/`). | A lawyer reviews them in every language that will be offered. |
| **`legal_placeholders` are all NULL** (operator, controller, processors, DPO, EU/UK representative, authority, retention, governing law), shown as `[label]`. | Fill them: `UPDATE legal_placeholders SET value = …`. |
| Existing accounts never accepted any terms or role agreement. | A re-accept prompt on next login. |

## Admin and moderation

| Now | For production |
| --- | --- |
| **No admin panel** (`admin/` is empty, `cms_users` has no credentials table). Complaints, sanctions and verification are handled in SQL. | A CMS/admin with its own login and 2FA: complaints queue, sanctions, verification review, translations editing (the `translations` table was built for it). |

## Infrastructure

| Now | For production |
| --- | --- |
| **Grafana is open as an anonymous admin** on localhost. | Real logins, not exposed publicly; alerting on errors and failed emails. |
| Photos are base64 strings in MySQL (`users.image`). | Object storage (S3/GCS) with a CDN; store a URL. |
| One shared database for all services; one gateway holds about 1,000 sockets. | See `docs/scaling.md` (database split, several gateway replicas, GKE). |
| `INTERNAL_TOKEN` is one shared secret between services. | Private networking plus per-service credentials or mTLS. |
| `APP_URL` defaults to `http://localhost:5173`. | Set it to the production URL (email links depend on it). |
| `127.0.0.1 api.rockguide.com` in `/etc/hosts`; Vite dev server. | Real DNS and TLS; a production build served by nginx/CDN that serves `index.html` for every `/<lang>/…` path. |
| No automated PHP tests or CI. | CI running the client tests, type-check, lint and the `lib/tests` scripts on every push. |
| Development seed accounts (`test_*@test.com`) and throwaway `@example.test` accounts in the database. | A clean production database; never copy the development one. |
