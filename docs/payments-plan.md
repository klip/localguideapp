# Plan: attaching a real payment service

Status: **proposal** (2026-10-22). Today every payment is simulated — see
`docs/production-memo.md` → Money. Prices quoted here were checked on
2026-10-22; confirm them before signing anything.

## 1. What BeLikeLocal needs from a provider

BeLikeLocal is a **marketplace**: travellers pay, guides get paid, the
platform keeps a commission. That rules out plain card processors and
merchant-of-record services (Paddle, Lemon Squeezy) and narrows the field to
"platform" products. The app's rules (`Bookings.php`) translate into these
requirements:

| Requirement | Why (where in the app) |
| --- | --- |
| Charge a traveller for a **pack** (£5) — platform revenue, no split | `Payments::recordPackPurchase()` |
| Charge a traveller for a **guide's fee**, keep the money until the trip happens, then pay the guide minus the commission (15% → 5% by level) | `Bookings::pay()`, `commission_*`, `guide_payout` |
| **Full and partial refunds**: full (withdrawn, declined, unanswered, never started, guide cancels), fee − 5% (guest cancels before start), **fee + 5%** (late guide cancel), any amount (staff dispute decision) | `Bookings` refund rules, `settleDispute()`, `cancelByStaff()` |
| **Hold the payout** while a cancellation or dispute is open | `disputed_at`, `cancel_requested_at` |
| **Onboard guides** (identity + bank details, KYC/KYB) done by the provider — we don't want that liability | guide agreement § 5 |
| **Pay guides in many countries** — guides are anywhere (Lisbon, Seville, Bangkok, Gibraltar…) | `guide_bases` |
| Take cards, Apple/Google Pay; ideally local methods (iDEAL, Bancontact, BLIK) | European travellers |
| Show and **charge in the traveller's currency** (we already display it — `utils/money.ts`) | currency switcher |
| Webhooks, test mode, good API without an SDK requirement (plain PHP, no Composer) | backend style |

## 2. The options

| Provider | Marketplace product | Pricing (UK platform, published) | Guide payout coverage | Fit |
| --- | --- | --- | --- | --- |
| **Stripe** | Connect (Express accounts, separate charges & transfers) | Cards: UK 1.5% + 20p, premium UK 2.8% + 20p, EEA 2.5% + 20p, international 3.15% + 20p, +2% when converting. Connect "you handle pricing": £2 per monthly active account, 0.25% + 10p per payout, 0.25% funds routing. Disputes £20. Fees not returned on refund. | Self-serve to connected accounts in the **US, UK, EEA, Canada, Switzerland** (cross-border payouts). Global Payouts to 160+ countries from a UK platform, but aimed at licensed money transmitters. | **Best balance** — published prices, no minimums, full refund/partial/transfer-reversal toolkit, hosted onboarding & KYC, Adaptive Pricing for local currencies, excellent test mode. |
| **Adyen** | Adyen for Platforms | Interchange++; ~€0.11–0.15 per transaction + interchange + scheme; negotiated; **monthly minimum invoice** | Very wide | Cheapest at scale, but built for large volumes (sales-led, minimums). Revisit at ≈ £5–10 M/year. |
| **Mollie** | Mollie Connect (routing/split) | Not published for Connect; standard Mollie rates | Sellers in **EEA, UK, CH** only; split in EUR/GBP | Great European local methods, but narrower than Stripe and no wider payout reach. |
| **PayPal / Braintree** | Braintree Marketplace; PayPal Payouts | Braintree UK cards 1.9% + 20p; +1.5% multi-currency. Marketplace terms by quote (UK). Payouts: no fee to recipients, 156+ countries, 23+ currencies | **Widest payouts** (PayPal accounts) | PayPal wallet is popular with travellers; Payouts is a good **second rail** for guides Stripe can't reach. Marketplace product itself is quote-based and less flexible. |
| **Airwallex** | Platform APIs / Payouts | Cards ≈ 2.8% + 30¢ domestic / 4.3% + 30¢ international (third-party figures); FX ≈ 0.5–1% over interbank; marketplace fees by quote | Strong global payouts (local rails in many countries) | Good for FX-heavy payouts later; card acceptance pricier, less mature marketplace tooling. |

**Recommendation: Stripe Connect** as the processor and marketplace engine,
with **PayPal Payouts** (or Stripe Global Payouts once available without a
licence) as a later second rail for guides outside Stripe's payout regions.

Why: it's the only option with published, no-minimum pricing *and* every
building block the booking rules need (hold, partial refund, transfer
reversal, hosted KYC, webhooks, Adaptive Pricing). Its weak spot — guide
payouts outside US/UK/EEA/CA/CH — is a coverage gap we can close later
without changing how travellers pay.

## 3. What it costs (worked examples, UK cards)

| Payment | Stripe fees | Platform keeps | Guide receives |
| --- | --- | --- | --- |
| Pack £5 | 1.5% + 20p = **£0.28** (5.5%) | £4.72 | — |
| Guide fee £80, commission 15% (£12) | card 1.5% + 20p = £1.40; payout 0.25% + 10p of £68 = £0.27; routing 0.25% = £0.17; + £2/month if it's the guide's only payout that month | **≈ £8.16** (£12 − fees − the £2 active-account fee) | £68.00 |
| Same with an EEA card | card 2.5% + 20p = £2.20 | ≈ £7.36 | £68.00 |
| Refunded in full before the trip | the £1.40 card fee is **not returned** | −£1.40 | — |

- **Small packs**: the fixed 20p is 4% of £5. Fine at this price; a £10 / 12-pick
  pack would halve it.
- **Refunds cost us the card fee.** Unanswered and declined requests (full
  refunds by our own rules) are pure cost — keep the 15-minute warning and
  reminders, and consider charging only when the guide accepts (see §4,
  option B).
- **Late guide cancel (+5%)** and **guest cancel (−5%)** move money between
  platform and guide; record them in our own ledger and settle against the
  guide's next transfer rather than debiting their account (account debits
  cost 1.4%).

## 4. How money moves (proposed design)

Stripe calls this **separate charges and transfers**: the traveller pays the
platform, the platform transfers to the guide later.

```
Traveller pays ─► PaymentIntent on the platform (amount = fee)
                   │  pending: guide hasn't answered
   declined / withdrawn / unanswered / expired ─► Refund (full)
   accepted ─► nothing moves; the trip is confirmed
   trip started ─► nothing moves
   trip finished + 48 h with no cancellation/dispute
               ─► Transfer to the guide's connected account
                   (fee − commission − any ledger adjustments)
                  ─► the guide's automatic payout to their bank
   cancelled before start ─► Refund (fee − 5% / full / fee + 5% per rules)
   cancelled after start ─► guide approves: full refund
                          ─► dispute: hold; staff decide → partial Refund
                             + Transfer of the rest
```

**Holding period.** Stripe doesn't offer escrow. Funds kept on a *connected
account* must be paid out within **90 days** (UK/EEA; 2 years US; 10 days
Thailand). Keeping them on the platform balance and transferring after the
trip avoids that, but bookings can be made up to a year ahead, so:

- **Option A (recommended for launch):** charge when the traveller books,
  but **limit paid bookings to 90 days ahead** (calendar `max_days_ahead`,
  `MAX_BOOKING_AHEAD_SECONDS`). Simple, matches what the terms promise.
- **Option B (later):** for trips further out, save the card (SetupIntent)
  and charge it off-session when the guide accepts or 7 days before the trip
  — cheaper refunds, but a card can fail at charge time (needs a "pay now or
  lose the booking" flow).

**Packs** are plain PaymentIntents on the platform (no Connect).

**Currencies.** Phase 1 charges in GBP and keeps today's "you're charged
£X" note. Phase 2 turns on **Adaptive Pricing** (Stripe shows and charges in
the customer's currency; the customer sees a conversion fee from 2%) or
charges natively in EUR/USD with GBP settlement — store `currency` on
`payments` and `bookings` either way.

**Guides outside Stripe's payout regions** (e.g. Thailand, Japan, Australia,
Turkey): until a second rail exists they can guide **for free** or be
onboarded once Stripe adds their region; then add PayPal Payouts (we'd do KYC
via PayPal's own account checks) or Stripe Global Payouts.

## 5. Changes in the app

| Area | Change |
| --- | --- |
| Data (migration) | `stripe_accounts` (user_id, account_id, country, charges/payouts enabled, requirements due); `payments.provider_ref`, `currency`, `status` from webhooks; `bookings.payment_intent_id`, `transfer_id`, `currency`; `ledger_entries` (guide, amount, reason, booking) for ±5% adjustments; `webhook_events` (id unique — idempotency) |
| Server | `lib/Stripe.php`: a small curl client (form-encoded API, `Idempotency-Key`, no SDK); `Payments`/`Bookings` create PaymentIntents, Refunds and Transfers instead of writing `paid` rows; a **webhook endpoint** `POST /webhooks/stripe` (signature check with the endpoint secret, `Stripe-Signature` HMAC) that marks payments paid/failed, handles `charge.dispute.created`, `account.updated`, `payout.failed` |
| Scheduler | `Bookings::sweep()` adds "transfer finished trips older than 48 h with no open cancellation/dispute" and retries failed transfers |
| Guide onboarding | Profile page (guide mode): "Get paid" → Stripe-hosted onboarding link (Account Links); status shown; a guide with a fee must finish onboarding before their fee can be booked |
| Client | Stripe.js **Payment Element** in the pay modal, `/unlock` and `BookingPanel`'s pay form (cards, Apple/Google Pay, local methods); confirm then wait for the webhook (the page shows "processing…") |
| CMS | Payments tab on the user page: charges, refunds, transfers, the guide's onboarding status; dispute resolution calls `Refund` + `Transfer` |
| Legal | Terms § 5 (charging moment, 90-day horizon), privacy § 9.4 (Stripe as processor/independent controller), guide agreement (Stripe Connected Account Agreement) — a new version |
| Tax | UK VAT on packs and commission once registered; EU VAT (OSS) on packs sold to EU consumers (electronically supplied service); **platform reporting** (UK/EU DAC7: report guides' earnings yearly) — Stripe Tax and Stripe's 1099/DAC7 reporting can help |

## 6. Phases

| # | Phase | Contents |
| --- | --- | --- |
| P1 | Packs | Stripe account (UK), PaymentIntents + Payment Element for packs, webhook endpoint, idempotency, test mode end to end |
| P2 | Guide onboarding | Express accounts, onboarding links, status on the profile, gate paid bookings on it |
| P3 | Guide fees | Charge on booking, refunds per rule, transfers after the trip, ledger for ±5%, 90-day horizon, dispute holds |
| P4 | Disputes & CMS | Chargeback handling, CMS payments view, staff refunds through Stripe |
| P5 | Currencies & reach | Adaptive Pricing / native EUR & USD, PayPal Payouts (or Global Payouts) for other countries, Stripe Tax / platform reporting |

Before P1: open the Stripe account in the operator's name (needs the
company details still missing from the legal placeholders), agree the
statement descriptor ("BELIKELOCAL"), and decide option A vs B.

## Sources

- [Stripe UK pricing](https://stripe.com/gb/pricing) · [Stripe Connect UK pricing](https://stripe.com/gb/connect/pricing) · [Cross-border payouts](https://docs.stripe.com/connect/cross-border-payouts) · [Global Payouts](https://docs.stripe.com/global-payouts) · [Manual payouts & holding periods](https://docs.stripe.com/connect/manual-payouts)
- [Adyen for Platforms transaction fees](https://docs.adyen.com/platforms/online-payments/transaction-fees) · [Ryft: split payment providers for UK marketplaces 2026](https://ryftpay.com/blog/split-payment-providers-for-uk-marketplaces-2026) (competitor-written)
- [Mollie Connect: route and split payments](https://docs.mollie.com/docs/connect-route-and-split-payments) · [Sharetribe: Mollie overview](https://www.sharetribe.com/academy/marketplace-payments/mollie-overview/)
- [PayPal Braintree UK fees](https://paypal.com/uk/enterprise/paypal-braintree-fees) · [PayPal mass payments / Payouts](https://www.paypal.com/us/business/operations/mass-payments) · [PayPal Payouts FAQ](https://developer.paypal.com/payouts/faqs)
- [Airwallex platform payouts](https://www.airwallex.com/ca/platform/payouts) · [Airwallex pricing 2026 (third-party)](https://www.wearefounders.uk/airwallex-pricing-2026-every-plan-fee-and-hidden-cost-explained/)
