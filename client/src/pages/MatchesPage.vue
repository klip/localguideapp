<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BookingPanel from '@/components/BookingPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import {
  useApi,
  type MatchOverviewRow,
  type PaymentState,
  type SelectionDecision,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Match management for both sides — every pair the signed-in user is part
 * of (`api.matchOverview()` → `Selections::getOverview()`), grouped into:
 *
 *   matched   — mutual interest, no confirmed trip: nothing booked yet, or
 *               a payment waiting on the guide. Once the guide confirms it
 *               (or straight away, for a free guide) the pair moves to
 *               `TripsPage.vue` and drops out of this page entirely
 *   incoming  — they're interested, you haven't answered; accept or pass
 *   awaiting  — you're interested, no answer yet; can withdraw
 *   passed    — you passed; can reconsider
 *
 * Unlike `ShortlistPage.vue`'s picks grid this is entirely server-backed,
 * so it's also how a guide finds out about a match the visitor completed
 * (the deck's "it's a match" toast only fires on the guide's own decision).
 *
 * Each matched row carries a `BookingPanel` — paying the guide's fee and the
 * guide accepting or declining it happen there; everything after that lives
 * on the trip card.
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

/** A matched pair whose latest booking is confirmed is a trip — shown on `/trips`, not here. */
function isTrip(row: MatchOverviewRow) {
  return row.active === 1 && row.booking_status === 'confirmed'
}

function groupOf(row: MatchOverviewRow): Group {
  if (row.active === 1) return 'matched'
  if (row.my_decision === 'interested') return 'awaiting'
  if (row.my_decision === 'pass') return 'passed'
  return 'incoming'
}

const grouped = computed(() => {
  const out: Record<Group, MatchOverviewRow[]> = {
    matched: [],
    incoming: [],
    awaiting: [],
    passed: [],
  }
  for (const row of rows.value) if (!isTrip(row)) out[groupOf(row)].push(row)
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
const tripCount = computed(() => rows.value.filter(isTrip).length)

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
    interested: (name) => `You're now matched with ${name}.`,
    pass: (name) => `Passed on ${name}.`,
  },
  awaiting: { revoke: (name) => `Withdrew your interest in ${name}.` },
  passed: {
    interested: (name) => `Marked ${name} as interested.`,
  },
}

/**
 * Confirmed trips aren't on this page, so the only booking that can block
 * unmatching here is a pending one — withdrawn or declined in `BookingPanel`
 * first. Mirrors `Selections::revoke()`.
 */
function canUnmatch(row: MatchOverviewRow) {
  return row.booking_status !== 'pending'
}

function profileLink(row: MatchOverviewRow) {
  return isGuest.value ? `/guides/${row.counterpart_id}` : `/visitors/${row.counterpart_id}`
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
  matched: `No matches waiting on a booking. When a ${counterpartNoun.value} you like likes you back, they appear here; confirmed trips are in My trips.`,
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

        <p v-if="tripCount" class="tiny muted picks-left">
          {{ tripCount }} confirmed trip{{ tripCount === 1 ? '' : 's' }} ·
          <RouterLink to="/trips">My trips</RouterLink>
        </p>
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
                <RouterLink :to="profileLink(row)" class="who-name"
                  ><strong
                    >{{ displayName(row)
                    }}<template v-if="row.age">, {{ row.age }}</template></strong
                  ></RouterLink
                >
                <PillBadge v-if="activeTab === 'matched'">Matched</PillBadge>
                <PillBadge v-else-if="row.their_decision === 'interested'">Likes you</PillBadge>
                <PillBadge v-else-if="activeTab === 'awaiting'" tone="neutral"
                  >Awaiting reply</PillBadge
                >
              </div>
              <div v-if="subline(row)" class="tiny muted">{{ subline(row) }}</div>
              <div class="tiny muted">Since {{ since(row) }}</div>

              <dl v-if="activeTab === 'matched' && (row.email || row.phone)" class="contact">
                <div v-if="row.email">
                  <dt>Email</dt>
                  <dd>
                    <a :href="`mailto:${row.email}`">{{ row.email }}</a>
                  </dd>
                </div>
                <div v-if="row.phone">
                  <dt>Phone</dt>
                  <dd>
                    <a :href="`tel:${row.phone.replace(/\s+/g, '')}`">{{ row.phone }}</a>
                  </dd>
                </div>
              </dl>
              <p v-if="activeTab === 'incoming' && isGuest" class="tiny muted note">
                Accepting uses one of your selections.
              </p>
            </div>

            <div class="actions">
              <template v-if="activeTab === 'matched'">
                <button
                  v-if="canUnmatch(row)"
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

            <BookingPanel
              v-if="activeTab === 'matched'"
              :row="row"
              :is-guest="isGuest"
              :name="displayName(row)"
              @changed="load"
            />
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

.who-name {
  color: inherit;
  text-decoration: none;

  &:hover,
  &:focus-visible {
    text-decoration: underline;
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
