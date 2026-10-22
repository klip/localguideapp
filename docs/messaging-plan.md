# Plan: email, SMS and WhatsApp operators

Status: **proposal** (2026-10-22). Today email goes through Gmail SMTP (≈ 500
a day) and SMS/WhatsApp are disabled options — see
`docs/production-memo.md` → Messaging. Prices were checked on 2026-10-22;
confirm them before signing.

## 1. What we send

| Kind | Examples | Channels wanted |
| --- | --- | --- |
| **Security codes** | sign-in codes, "we don't recognise this browser", staff sign-in | email now; SMS and WhatsApp as the user's choice (`two_factor_method`) |
| **Account links** | password reset, set-password (CMS), secure-your-account | email only |
| **Booking notifications** | request, accepted, 15-minute warning, reminders 24 h / 2 h, trip started, disputes | in-app + email now; WhatsApp/SMS for the time-critical ones (15-minute warning, 2 h reminder, trip started) |
| **Chat** | "new message from …" when offline | email (first unread only) |

Volumes at launch are small (hundreds to low thousands a day), so
reliability, deliverability and fraud protection matter more than unit price.

## 2. Email

| Provider | Price | Strengths | Weak spots |
| --- | --- | --- | --- |
| **Postmark** | $15/month for 10k, then $1.80 per 1k (Basic); Pro $16.50 + $1.30/1k; dedicated IP $50/month (300k+/month) | Best-reputed **transactional deliverability**; transactional and broadcast streams fully separated; bounce/spam webhooks; 45-day logs (up to 365) | Pricier per email at volume; US-hosted |
| **Amazon SES** | **$0.10 per 1k**; dedicated IP $24.95/month | Cheapest by far; EU regions (Ireland, Frankfurt) for data residency | You run reputation: warm-up, bounce/complaint handling, suppression lists |
| Mailgun | ≈ $15/month 10k, $35/month 50k | EU region | Plans changed often; deliverability middle of the pack |
| Resend | free tier; ≈ $20 for 50k | Modern API, React email | Younger; EU residency unconfirmed |

**Recommendation: Postmark** now (a few hundred dollars a year at most, best
inbox placement for sign-in codes); move bulk to **Amazon SES** (EU region)
only if volume passes ~1M emails a month. Either way: authenticate
`belikelocal.club` (SPF, DKIM, DMARC `p=quarantine`, then `reject`),
a `notifications@` sender, and consume bounce/complaint webhooks into a
suppression list.

## 3. SMS and WhatsApp

### The operators

| Provider | Verification (OTP) product | Messaging prices | Strengths | Weak spots |
| --- | --- | --- | --- | --- |
| **Twilio** | **Verify**: $0.05 per *successful* verification + channel fee; SMS, WhatsApp, voice, email, TOTP, silent network auth; failover between channels; **Fraud Guard** (SMS-pumping protection) included | SMS UK ≈ $0.046/segment, US $0.0083; WhatsApp $0.005/message + Meta's template fee | One API for SMS + WhatsApp + voice; published prices; best docs; 180+ countries | Not the cheapest SMS in some countries; support is pay-for-tier |
| **Vonage** | **Verify**: ≈ €0.052 per successful verification + channel; built-in failover (SMS → WhatsApp → voice → email), silent auth | Similar to Twilio | Good failover workflows, strong in Europe | Fewer published prices; some products sales-quoted |
| **Infobip** | 2FA API | Per-country quotes | Most **direct operator connections** (strong deliverability in EMEA, Asia, LatAm); WhatsApp, Viber, RCS | Sales-led, minimums; heavier onboarding |
| **Sinch** | Verification | Quote-based | Carrier-grade global SMS, owns operator links | Enterprise-oriented |

**Meta's WhatsApp prices** apply whichever provider sends them, per delivered
template message: authentication ≈ $0.022 in the UK, ≈ $0.055 in Germany,
≈ $0.030 in France (utility similar, marketing higher). Replies inside a
24-hour customer service window are free/flat. WhatsApp needs a **verified
Meta Business account**, **pre-approved templates** (one per notification
type and language) and the user's **opt-in**.

### Recommendation

- **Primary: Twilio** for both SMS and WhatsApp.
  - **Twilio Verify** for every code (sign-in, phone ownership check). We stop storing codes for those channels at all; Verify handles retries, rate limits, SMS-pumping fraud and channel failover (WhatsApp first, SMS if WhatsApp fails, email last).
  - **Twilio Messaging** (a Messaging Service with a WhatsApp sender and an alphanumeric "BeLikeLocal" sender ID where allowed) for booking notifications.
- **Fallback: Vonage** (or Infobip once volumes justify a contract) as a second
  route for SMS in countries where Twilio's delivery rate dips — wire it
  behind the same interface but turn it on per country only after looking at
  delivery reports.
- Expected cost at launch: a sign-in code ≈ $0.05 + $0.02–0.05 channel ≈
  **$0.07–0.10 each**; so keep **email as the default** second factor and
  "remember this browser" (already built), and offer SMS/WhatsApp as a
  choice.

## 4. Changes in the app

| Area | Change |
| --- | --- |
| Profile | Phone **ownership check** when it's added or changed (Verify code to that number); `users.phone_verified_at`; enable the SMS/WhatsApp options in the 2FA select only for a verified number; WhatsApp opt-in checkbox (`users.whatsapp_opt_in_at`) |
| Auth | `AccountSecurity::startLogin()` branches on `two_factor_method`: email → as now; SMS/WhatsApp → Verify `start`, `verifyTwoFactor` → Verify `check`; challenge rows keep attempts/expiry but no code hash for those channels |
| Notifications | `Notifications::deliver()` (already the single branching point) picks channels per kind and user preference: email always; WhatsApp/SMS only for the time-critical kinds and only with opt-in; quiet hours by the user's time zone; per-user daily cap |
| Outbox | Generalise `email_outbox` → `message_outbox` (channel, recipient, template, params, status, provider id, cost) so SMS/WhatsApp get the same retry/priority worker (`lemp-rg-mailer` → a messaging worker) |
| Webhooks | Delivery status callbacks (Twilio status callback, Postmark bounce/spam) into the outbox row; STOP/opt-out handling for SMS |
| Templates | WhatsApp templates per kind × 15 languages, submitted to Meta; SMS texts short (one segment, 160 GSM-7 / 70 UCS-2 chars — Arabic, Thai, CJK, Greek, Cyrillic are UCS-2) |
| Email | Mailer switches from SMTP-to-Gmail to the Postmark API (or SMTP with the Postmark token), sender domain authenticated; drop `MAIL_FORWARDS` / `MAIL_REDIRECT_TO` in production |
| Legal / privacy | Name the providers as processors (§ 9.1 placeholders), add phone/WhatsApp to "what we collect", opt-in wording |

## 5. Phases

| # | Phase | Contents |
| --- | --- | --- |
| M1 | Email | Postmark account, domain authentication (SPF/DKIM/DMARC), Mailer on Postmark, bounce webhook + suppression |
| M2 | Phone verification + SMS 2FA | Twilio Verify, phone ownership check, SMS as a 2FA choice, Fraud Guard on, country allow-list |
| M3 | WhatsApp | Meta Business verification, WhatsApp sender, templates in 15 languages, WhatsApp 2FA (Verify) and opt-in notifications |
| M4 | Notifications on phone | Outbox generalised, time-critical booking messages by WhatsApp/SMS, quiet hours, caps, delivery reports in the CMS |
| M5 | Second route | Vonage/Infobip behind the same interface, per-country routing by delivery rate |

## Sources

- [Postmark pricing](https://postmarkapp.com/pricing) · [Amazon SES pricing](https://aws.amazon.com/ses/pricing/) · [Comparison: Resend, Postmark, SES, Mailgun (third-party)](https://www.buildmvpfast.com/blog/build-vs-buy-email-infrastructure-saas-mvp-2026) · [Postmark alternatives 2026](https://automationatlas.io/answers/best-postmark-alternatives-2026/)
- [Twilio Verify pricing](https://www.twilio.com/en-us/verify/pricing) · [Twilio WhatsApp pricing](https://www.twilio.com/en-us/whatsapp/pricing) · [Twilio SMS pricing — UK](https://www.twilio.com/en-us/sms/pricing/gb)
- [Vonage Verify pricing](https://www.vonage.com/communications-apis/verify/pricing/) · [OTP providers compared 2026 (Authgear)](https://www.authgear.com/post/best-otp-service-providers/)
- [Infobip vs Sinch (StackShare)](https://stackshare.io/stackups/infobip-vs-sinch) · [Most reliable SMS APIs 2026](https://mobile-text-alerts.com/articles/most-reliable-sms-api)
- [Meta WhatsApp Business Platform pricing](https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing) · [WhatsApp API pricing explained 2026](https://www.authgear.com/post/whatsapp-api-pricing) · [Sleekflow WhatsApp price table](https://sleekflow.io/blog/whatsapp-business-price)
