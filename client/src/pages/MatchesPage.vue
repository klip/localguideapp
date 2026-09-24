<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useApi, type MatchOverviewRow, type PaymentState, type SelectionDecision } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Match management for both sides — every pair the signed-in user is part
 * of (`api.matchOverview()` → `Selections::getOverview()`), grouped into:
 *
 *   matched   — mutual interest; contact details unlock once there's a
 *               booking (guest pays the guide's fee, or the guide is free)
 *   incoming  — they're interested, you haven't answered; accept or pass
 *   awaiting  — you're interested, no answer yet; can withdraw
 *   passed    — you passed; can reconsider
 *
 * Unlike `ShortlistPage.vue`'s picks grid this is entirely server-backed,
 * so it's also how a guide finds out about a match the visitor completed
 * (the deck's "it's a match" toast only fires on the guide's own decision).
 *
 * On a match with a paid guide, the guest pays here (picking when to meet),
 * and the guide can cancel the booking here — refunding the guest, plus 5% if
 * it's late (`Bookings::refundAmount()`). The row's `refund_if_cancelled`
 * already has that worked out, so the confirm step can state the amount.
 */
type Group = 'matched' | 'incoming' | 'awaiting' | 'passed'
type Action = SelectionDecision | 'revoke'

const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()
const shortlist = useShortlistStore()

const isGuest = computed(() => auth.user?.role === 'guest')
const counterpartNoun = computed(() => (isGuest.value ? 'guide' : 'visitor'))

const rows = ref<MatchOverviewRow[]>([])
const payment = ref<PaymentState | null>(null)
const loading = ref(false)
const loaded = ref(false)
const busyId = ref<number | null>(null)

function groupOf(row: MatchOverviewRow): Group {
  if (row.active === 1) return 'matched'
  if (row.my_decision === 'interested') return 'awaiting'
  if (row.my_decision === 'pass') return 'passed'
  return 'incoming'
}

const grouped = computed(() => {
  const out: Record<Group, MatchOverviewRow[]> = { matched: [], incoming: [], awaiting: [], passed: [] }
  for (const row of rows.value) out[groupOf(row)].push(row)
  return out
})

const tabs: { id: Group; label: string }[] = [
  { id: 'matched', label: 'Matched' },
  { id: 'incoming', label: 'Likes you' },
  { id: 'awaiting', label: 'Awaiting reply' },
  { id: 'passed', label: 'Passed' },
]

/** Explicit choice wins; otherwise open on whichever group needs attention first. */
const chosenTab = ref<Group | null>(null)
const activeTab = computed<Group>(() => {
  if (chosenTab.value) return chosenTab.value
  if (grouped.value.matched.length) return 'matched'
  if (grouped.value.incoming.length) return 'incoming'
  return 'matched'
})
const visibleRows = computed(() => grouped.value[activeTab.value])

/** Server-authoritative for guests (the local `shortlist` count can drift) — null for guides, who never pay. */
const selectionsLeft = computed(() => (isGuest.value ? (payment.value?.selectionsLeft ?? 0) : null))
const outOfPicks = computed(() => selectionsLeft.value === 0)

async function load() {
  if (!auth.user) return

  loading.value = true
  try {
    const [overview, paymentState] = await Promise.all([
      api.matchOverview(),
      isGuest.value ? api.paymentState() : Promise.resolve(null),
    ])
    rows.value = overview.selections
    payment.value = paymentState
    if (paymentState) shortlist.applyPaymentState(paymentState)
  } catch (err) {
    reportApiError(err, 'Could not load your matches.')
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onMounted(load)

function displayName(row: MatchOverviewRow) {
  return row.name || row.email || `A ${counterpartNoun.value}`
}

function subline(row: MatchOverviewRow) {
  const parts = isGuest.value
    ? [row.headline, row.price_label]
    : [row.party, row.duration_hours ? `${row.duration_hours}h tour` : null]
  return parts.filter(Boolean).join(' · ')
}

function photo(row: MatchOverviewRow) {
  if (row.image) return `url("${row.image}")`
  return isGuest.value ? 'var(--rg-photo-guide)' : 'var(--rg-photo-visitor)'
}

function initial(row: MatchOverviewRow) {
  return displayName(row).trim().charAt(0).toUpperCase()
}

function since(row: MatchOverviewRow) {
  const date = new Date(row.updated_at.replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}

/** Keeps the visitor deck's client-only pick/pass state in step, so a guide handled here doesn't resurface there. */
function syncShortlist(row: MatchOverviewRow, action: Action) {
  if (!isGuest.value) return
  const id = String(row.counterpart_id)
  if (action === 'interested') shortlist.pick(id)
  else if (action === 'pass') shortlist.pass(id)
  else shortlist.remove(id)
}

const SUCCESS_COPY: Record<Group, Partial<Record<Action, (name: string) => string>>> = {
  matched: { revoke: (name) => `Unmatched from ${name}.` },
  incoming: {
    interested: (name) => `You're now matched with ${name} — their contact details are under Matched.`,
    pass: (name) => `Passed on ${name}.`,
  },
  awaiting: { revoke: (name) => `Withdrew your interest in ${name}.` },
  passed: {
    interested: (name) => `Marked ${name} as interested.`,
  },
}

function money(amount: number) {
  return `£${amount.toFixed(2)}`
}

/** The server's UTC `YYYY-MM-DD HH:MM:SS`, shown in the viewer's own time zone. */
function meetingLabel(row: MatchOverviewRow) {
  if (!row.meeting_at) return null
  const date = new Date(`${row.meeting_at.replace(' ', 'T')}Z`)
  return Number.isNaN(date.getTime())
    ? null
    : date.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function hasOpenBooking(row: MatchOverviewRow) {
  return row.booking_status === 'confirmed' && row.trip_status !== 'finished'
}

function isPaidBooking(row: MatchOverviewRow) {
  return hasOpenBooking(row) && row.payment_status === 'paid'
}

/** Matched with a guide who charges, and nobody has paid yet — contact details are still hidden. */
function awaitingPayment(row: MatchOverviewRow) {
  return row.active === 1 && !hasOpenBooking(row) && row.fee_amount > 0
}

/** Cancelling now would cost the guide the late-cancellation extra. */
function lateCancel(row: MatchOverviewRow) {
  return row.refund_if_cancelled !== null && row.refund_if_cancelled > (row.booking_amount ?? 0)
}

/** At most one inline form (pay or cancel) is open at a time. */
const openForm = ref<{ rowId: number; kind: 'pay' | 'cancel' } | null>(null)
const meetingAt = ref('')
const cancelReason = ref('')

function showForm(row: MatchOverviewRow, kind: 'pay' | 'cancel') {
  openForm.value = { rowId: row.id, kind }
  meetingAt.value = ''
  cancelReason.value = ''
}

function isFormOpen(row: MatchOverviewRow, kind: 'pay' | 'cancel') {
  return openForm.value?.rowId === row.id && openForm.value.kind === kind
}

/** `<input type="datetime-local">` wants local time without a zone. */
function localInputValue(date: Date) {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
const minMeeting = computed(() => localInputValue(new Date()))

async function pay(row: MatchOverviewRow, event: Event) {
  if (busyId.value !== null) return

  const placement = anchorTo('element', (event.currentTarget as HTMLElement | null)?.closest('li'))
  const when = new Date(meetingAt.value)
  if (!meetingAt.value || Number.isNaN(when.getTime())) {
    messages.error('Choose when you’d like to meet.', placement)
    return
  }

  const name = displayName(row)
  busyId.value = row.id
  try {
    const booking = await api.payGuideFee({ targetId: row.counterpart_id, meetingAt: when.toISOString() })
    openForm.value = null
    await load()
    messages.success(`Payment of ${money(booking.amount)} confirmed — ${name}’s contact details are unlocked.`, placement)
  } catch (err) {
    reportApiError(err, 'The payment didn’t go through.', placement)
  } finally {
    busyId.value = null
  }
}

async function cancelBooking(row: MatchOverviewRow, event: Event) {
  if (busyId.value !== null) return

  const placement = anchorTo('element', (event.currentTarget as HTMLElement | null)?.closest('li'))
  const name = displayName(row)
  const reason = cancelReason.value.trim()

  busyId.value = row.id
  try {
    const booking = await api.cancelBooking({ targetId: row.counterpart_id, ...(reason ? { reason } : {}) })
    openForm.value = null
    await load()
    messages.success(
      booking.refund_amount !== null
        ? `Booking cancelled — ${name} has been refunded ${money(booking.refund_amount)}.`
        : `Booking cancelled — ${name} has been told.`,
      placement,
    )
  } catch (err) {
    reportApiError(err, 'Could not cancel this booking.', placement)
  } finally {
    busyId.value = null
  }
}

async function act(row: MatchOverviewRow, action: Action, event: MouseEvent) {
  if (busyId.value !== null) return

  // Captured before the row can move to another group (see anchorTo()).
  const placement = anchorTo('element', (event.currentTarget as HTMLElement | null)?.closest('li'))
  const group = groupOf(row)
  const name = displayName(row)

  busyId.value = row.id
  try {
    const result =
      action === 'revoke'
        ? await api.revokeSelection({ targetId: row.counterpart_id })
        : await api.decide({ targetId: row.counterpart_id, decision: action })
    syncShortlist(row, action)
    await load()

    // Reconsidering someone who'd already said yes completes a match too.
    const copy =
      result.active === 1 && group !== 'matched'
        ? SUCCESS_COPY.incoming.interested
        : SUCCESS_COPY[group][action]
    if (copy) messages.success(copy(name), placement)
  } catch (err) {
    reportApiError(err, 'Could not update this match.', placement)
  } finally {
    busyId.value = null
  }
}

const EMPTY_COPY = computed<Record<Group, string>>(() => ({
  matched: `No mutual matches yet. When a ${counterpartNoun.value} you like likes you back, they appear here.`,
  incoming: `Nobody's waiting on an answer from you right now.`,
  awaiting: `You're not waiting on anyone. Mark ${counterpartNoun.value}s as interested from the deck.`,
  passed: `You haven't passed on anyone.`,
}))

const deckLink = computed(() => (isGuest.value ? '/discover' : '/guide/discover'))
</script>

<template>
  <section class="section shell">
    <SectionTitle
      eyebrow="Your matches"
      heading="Matches"
      :lead="
        isGuest
          ? 'Everyone you’ve picked, everyone who picked you, and the guides you’ve matched with.'
          : 'Visitors who liked you, visitors you liked, and everyone you’ve matched with.'
      "
    />

    <PanelCard v-if="!auth.isAuthenticated" class="narrow" title="Log in to see your matches">
      <p class="muted">Matches are tied to your account.</p>
      <RouterLink to="/login" role="button">Log in</RouterLink>
    </PanelCard>

    <template v-else>
      <div class="toolbar">
        <div class="tabs">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="tab"
            :class="{ 'tab--active': activeTab === tab.id }"
            :aria-pressed="activeTab === tab.id"
            @click="chosenTab = tab.id"
          >
            {{ tab.label }}
            <span class="tab-count">{{ grouped[tab.id].length }}</span>
          </button>
        </div>

        <p v-if="selectionsLeft !== null" class="tiny muted picks-left">
          {{ selectionsLeft }} selection{{ selectionsLeft === 1 ? '' : 's' }} left ·
          <RouterLink to="/unlock">Unlock more</RouterLink>
        </p>
      </div>

      <PanelCard>
        <p v-if="loading && !loaded" class="muted empty">Loading your matches…</p>

        <div v-else-if="!visibleRows.length" class="empty">
          <p class="muted">{{ EMPTY_COPY[activeTab] }}</p>
          <RouterLink v-if="activeTab !== 'passed'" :to="deckLink" class="soft-btn" role="button">
            Go to discovery
          </RouterLink>
        </div>

        <ul v-else class="match-list" :aria-busy="loading">
          <li v-for="row in visibleRows" :key="row.id" class="match-row">
            <div class="avatar" :style="{ backgroundImage: photo(row) }" aria-hidden="true">
              <span v-if="!row.image">{{ initial(row) }}</span>
            </div>

            <div class="who">
              <div class="who-line">
                <strong>{{ displayName(row) }}<template v-if="row.age">, {{ row.age }}</template></strong>
                <PillBadge v-if="activeTab === 'matched'">Matched</PillBadge>
                <PillBadge v-else-if="row.their_decision === 'interested'">Likes you</PillBadge>
                <PillBadge v-else-if="activeTab === 'awaiting'" tone="neutral">Awaiting reply</PillBadge>
              </div>
              <div v-if="subline(row)" class="tiny muted">{{ subline(row) }}</div>
              <div class="tiny muted">Since {{ since(row) }}</div>

              <p v-if="activeTab === 'matched' && isPaidBooking(row)" class="booking-line">
                <PillBadge>Paid {{ money(row.booking_amount ?? 0) }}</PillBadge>
                <span v-if="meetingLabel(row)" class="tiny muted">Meeting {{ meetingLabel(row) }}</span>
              </p>
              <p v-else-if="activeTab === 'matched' && hasOpenBooking(row)" class="booking-line">
                <PillBadge tone="neutral">Free guide</PillBadge>
              </p>

              <dl v-if="activeTab === 'matched' && (row.email || row.phone)" class="contact">
                <div v-if="row.email">
                  <dt>Email</dt>
                  <dd><a :href="`mailto:${row.email}`">{{ row.email }}</a></dd>
                </div>
                <div v-if="row.phone">
                  <dt>Phone</dt>
                  <dd><a :href="`tel:${row.phone.replace(/\s+/g, '')}`">{{ row.phone }}</a></dd>
                </div>
              </dl>
              <template v-if="activeTab === 'matched'">
                <p v-if="awaitingPayment(row) && isGuest" class="tiny muted note">
                  Pay {{ displayName(row) }}’s {{ money(row.fee_amount) }} fee to unlock their contact details — and
                  yours for them.
                </p>
                <p v-else-if="awaitingPayment(row)" class="tiny muted note">
                  Contact details unlock once the visitor pays your {{ money(row.fee_amount) }} fee.
                </p>
                <p v-else-if="isPaidBooking(row) && isGuest" class="tiny muted note">
                  Only {{ displayName(row) }} can cancel this booking — you’d be refunded in full.
                </p>
              </template>
              <p v-if="activeTab === 'incoming' && isGuest" class="tiny muted note">
                Accepting uses one of your selections.
              </p>
            </div>

            <div class="actions">
              <template v-if="activeTab === 'matched'">
                <button
                  v-if="isGuest && awaitingPayment(row) && !isFormOpen(row, 'pay')"
                  type="button"
                  class="accept"
                  :disabled="busyId !== null"
                  @click="showForm(row, 'pay')"
                >
                  Pay {{ money(row.fee_amount) }}
                </button>
                <button
                  v-if="!isGuest && hasOpenBooking(row) && !isFormOpen(row, 'cancel')"
                  type="button"
                  class="reject"
                  :disabled="busyId !== null"
                  @click="showForm(row, 'cancel')"
                >
                  Cancel booking
                </button>
                <button
                  v-if="isGuest ? !isPaidBooking(row) : !hasOpenBooking(row)"
                  type="button"
                  class="soft-btn"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'revoke', $event)"
                >
                  Unmatch
                </button>
              </template>

              <template v-else-if="activeTab === 'incoming'">
                <RouterLink v-if="outOfPicks" to="/unlock" role="button" class="accept">
                  Unlock to accept
                </RouterLink>
                <button
                  v-else
                  type="button"
                  class="accept"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'interested', $event)"
                >
                  ♥ Accept
                </button>
                <button
                  type="button"
                  class="reject"
                  :disabled="busyId !== null"
                  @click="act(row, 'pass', $event)"
                >
                  Pass
                </button>
              </template>

              <template v-else-if="activeTab === 'awaiting'">
                <button
                  type="button"
                  class="soft-btn"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'revoke', $event)"
                >
                  Withdraw
                </button>
              </template>

              <template v-else>
                <RouterLink v-if="outOfPicks" to="/unlock" role="button" class="soft-btn">
                  Unlock to reconsider
                </RouterLink>
                <button
                  v-else
                  type="button"
                  class="soft-btn"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'interested', $event)"
                >
                  Reconsider
                </button>
              </template>
            </div>

            <form
              v-if="activeTab === 'matched' && isFormOpen(row, 'pay')"
              class="inline-form"
              :aria-label="`Pay ${displayName(row)}’s fee`"
              @submit.prevent="pay(row, $event)"
            >
              <label>
                When would you like to meet?
                <input v-model="meetingAt" type="datetime-local" :min="minMeeting" required />
              </label>
              <p class="tiny muted">
                You’ll pay {{ money(row.fee_amount) }}. If {{ displayName(row) }} cancels, you’re refunded in full — plus
                5% if they cancel at short notice.
              </p>
              <div class="form-actions">
                <button type="submit" class="accept" :aria-busy="busyId === row.id" :disabled="busyId !== null">
                  Confirm payment
                </button>
                <button type="button" class="soft-btn" :disabled="busyId !== null" @click="openForm = null">
                  Not now
                </button>
              </div>
            </form>

            <form
              v-if="activeTab === 'matched' && isFormOpen(row, 'cancel')"
              class="inline-form"
              :aria-label="`Cancel the booking with ${displayName(row)}`"
              @submit.prevent="cancelBooking(row, $event)"
            >
              <p class="cancel-summary">
                <template v-if="row.payment_status === 'paid'">
                  {{ displayName(row) }} will be refunded
                  <strong>{{ money(row.refund_if_cancelled ?? row.booking_amount ?? 0) }}</strong
                  ><template v-if="lateCancel(row)"> — that includes 5% for cancelling at short notice</template>.
                </template>
                <template v-else>{{ displayName(row) }} will be told the booking is off.</template>
                They get their pick back and a message by email.
              </p>
              <label>
                Reason <span class="muted">(optional, shared with them)</span>
                <textarea v-model="cancelReason" rows="2" maxlength="256" />
              </label>
              <div class="form-actions">
                <button type="submit" class="reject" :aria-busy="busyId === row.id" :disabled="busyId !== null">
                  Cancel booking
                </button>
                <button type="button" class="soft-btn" :disabled="busyId !== null" @click="openForm = null">
                  Keep booking
                </button>
              </div>
            </form>
          </li>
        </ul>
      </PanelCard>
    </template>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.picks-left {
  margin: 0;
}

// Scrolls sideways on narrow screens rather than wrapping onto two rows.
.tabs {
  display: flex;
  gap: 0.4rem;
  overflow-x: auto;
  max-width: 100%;
  padding-bottom: 0.15rem;
  scrollbar-width: none;
}

.tab {
  flex-shrink: 0;
  width: auto;
  margin: 0;
  padding: 0.45rem 0.85rem;
  border-radius: 999px;
  border: 1px solid var(--rg-line);
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.85rem;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;

  &:hover,
  &:focus-visible {
    border-color: var(--rg-orange-300);
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }
}

.tab--active,
.tab--active:hover,
.tab--active:focus-visible {
  background: var(--rg-orange-500);
  border-color: var(--rg-orange-500);
  color: #fff;
}

.tab-count {
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.07);
  font-size: 0.75rem;
  line-height: 1.4rem;
  text-align: center;

  .tab--active & {
    background: rgba(255, 255, 255, 0.25);
  }
}

.empty {
  text-align: center;
  padding: 1.5rem 0.5rem;
  margin: 0;

  [role='button'] {
    width: auto;
  }
}

.match-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.match-row {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.5rem 0.9rem;
  align-items: start;
  padding: 0.9rem 0;
  list-style: none;

  & + & {
    border-top: 1px solid var(--rg-line);
  }

  @include bp.md {
    grid-template-columns: auto 1fr auto;
    align-items: center;
  }
}

.avatar {
  width: 3.25rem;
  height: 3.25rem;
  border-radius: 50%;
  background-size: cover;
  background-position: center;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 800;
  font-size: 1.2rem;
}

.who {
  min-width: 0;

  strong {
    overflow-wrap: anywhere;
  }
}

.who-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.6rem;
}

.contact {
  margin: 0.5rem 0 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1.25rem;
  font-size: 0.9rem;

  div {
    display: flex;
    gap: 0.4rem;
  }

  dt {
    color: var(--rg-muted);
    font-weight: 400;
  }

  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }
}

.note {
  margin: 0.35rem 0 0;
}

.booking-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.6rem;
  margin: 0.4rem 0 0;
}

// Full width under the row, whatever the breakpoint.
.inline-form {
  grid-column: 1 / -1;
  margin: 0.25rem 0 0;
  padding: 0.9rem;
  border: 1px solid var(--rg-line);
  border-radius: 0.75rem;
  background: var(--rg-orange-50);

  label {
    font-size: 0.9rem;
    font-weight: 600;
  }

  input,
  textarea {
    margin: 0.35rem 0 0.5rem;
    font-weight: 400;
  }

  p {
    margin: 0 0 0.6rem;
  }
}

.cancel-summary {
  font-size: 0.95rem;
}

.form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;

  > * {
    width: auto;
    margin: 0;
    padding: 0.5rem 0.9rem;
    font-size: 0.9rem;
  }
}

// Same soft green/red pair as DecisionRow.vue, so accepting here reads like the deck.
.accept {
  background: var(--rg-green-soft);
  border: 1px solid var(--rg-green-border);
  color: var(--rg-green);
  font-weight: 600;

  &:hover:not(:disabled),
  &:focus-visible {
    background: #dcefe4;
    border-color: var(--rg-green);
    color: var(--rg-green);
  }
}

.reject {
  background: var(--rg-red-soft);
  border: 1px solid var(--rg-red-border);
  color: var(--rg-red);
  font-weight: 600;

  &:hover:not(:disabled),
  &:focus-visible {
    background: #ffe4e0;
    border-color: var(--rg-red);
    color: var(--rg-red);
  }
}

// Full-width row under the details on mobile; a right-hand column from `md`.
.actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 0.5rem;

  > * {
    flex: 1;
    margin: 0;
    padding: 0.5rem 0.9rem;
    font-size: 0.9rem;
    white-space: nowrap;
  }

  @include bp.md {
    grid-column: auto;

    > * {
      flex: none;
    }
  }
}
</style>
