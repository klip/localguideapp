<script setup lang="ts">
import { computed, ref } from 'vue'
import BookingPanel from '@/components/BookingPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import NavIcon from '@/components/NavIcon.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useLiveSection } from '@/composables/useLiveSection'
import { t, tn } from '@/i18n'
import {
  useApi,
  type MatchOverviewRow,
  type PaymentState,
  type SelectionDecision,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { useMatchesStore } from '@/stores/matches'
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
 * Entirely server-backed, so it's also how a guide finds out about a match the visitor completed
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
const matchesStore = useMatchesStore()

const isGuest = computed(() => auth.activeRole === 'guest')
/** `guest`/`guide` — picks the role-specific wording for keys that differ by side. */
const side = computed(() => (isGuest.value ? 'guest' : 'guide'))

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

const TAB_IDS: Group[] = ['matched', 'incoming', 'awaiting', 'passed']
const tabs = computed(() => TAB_IDS.map((id) => ({ id, label: t(`matches.tabs.${id}`) })))

/** Explicit choice wins; otherwise open on whichever group needs attention first. */
const chosenTab = ref<Group | null>(null)
const activeTab = computed<Group>(() => {
  if (chosenTab.value) return chosenTab.value
  if (grouped.value.matched.length) return 'matched'
  if (grouped.value.incoming.length) return 'incoming'
  return 'matched'
})
const visibleRows = computed(() => grouped.value[activeTab.value])
/** Every trip My trips lists (the server counts them the same way) — not pairs, which undercounts. */
const tripCount = ref(0)

/** Server-authoritative for guests (the local `matchesStore` count can drift) — null for guides, who never pay. */
const selectionsLeft = computed(() => (isGuest.value ? (payment.value?.selectionsLeft ?? 0) : null))
/** No bought picks left and no free-picks period running — Accept/Reconsider become "Unlock…". */
const outOfPicks = computed(() => {
  if (selectionsLeft.value !== 0) return false
  const until = payment.value?.freePicksUntil
  return !(until && new Date(until).getTime() > Date.now())
})

async function load() {
  if (!auth.user) return

  loading.value = true
  try {
    const [overview, paymentState] = await Promise.all([
      api.matchOverview(),
      isGuest.value ? api.paymentState() : Promise.resolve(null),
    ])
    rows.value = overview.selections
    tripCount.value = overview.tripCount ?? 0
    payment.value = paymentState
    if (paymentState) matchesStore.applyPaymentState(paymentState)
  } catch (err) {
    reportApiError(err, t('matches.toast.load_failed'))
  } finally {
    loading.value = false
    loaded.value = true
  }
}

useLiveSection('matches', load)

function displayName(row: MatchOverviewRow) {
  return row.name || t(`matches.row.unnamed_${side.value}`)
}

function subline(row: MatchOverviewRow) {
  const parts = isGuest.value
    ? [row.headline, row.price_label]
    : [
        row.party,
        row.duration_hours ? t('matches.row.tour_length', { hours: row.duration_hours }) : null,
      ]
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
function syncMatches(row: MatchOverviewRow, action: Action) {
  if (!isGuest.value) return
  const id = String(row.counterpart_id)
  if (action === 'interested') matchesStore.pick(id)
  else if (action === 'pass') matchesStore.pass(id)
  else matchesStore.remove(id)
}

const SUCCESS_COPY: Record<Group, Partial<Record<Action, (name: string) => string>>> = {
  matched: { revoke: (name) => t('matches.toast.unmatched', { name }) },
  incoming: {
    interested: (name) => t('matches.toast.matched', { name }),
    pass: (name) => t('matches.toast.passed', { name }),
  },
  awaiting: { revoke: (name) => t('matches.toast.withdrawn', { name }) },
  passed: {
    interested: (name) => t('matches.toast.reconsidered', { name }),
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
    syncMatches(row, action)
    await load()

    // Reconsidering someone who'd already said yes completes a match too.
    const copy =
      result.active === 1 && group !== 'matched'
        ? SUCCESS_COPY.incoming.interested
        : SUCCESS_COPY[group][action]
    if (copy) messages.success(copy(name), placement)
  } catch (err) {
    reportApiError(err, t('matches.toast.update_failed'), placement)
  } finally {
    busyId.value = null
  }
}

const EMPTY_COPY = computed<Record<Group, string>>(() => ({
  matched: t(`matches.empty.matched_${side.value}`),
  incoming: t('matches.empty.incoming'),
  awaiting: t(`matches.empty.awaiting_${side.value}`),
  passed: t('matches.empty.passed'),
}))

const deckLink = computed(() => (isGuest.value ? '/discover' : '/guide/discover'))
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('matches.header.eyebrow')"
      :heading="t('matches.header.heading')"
      :lead="t(`matches.header.lead_${side}`)"
    />

    <PanelCard v-if="!auth.isAuthenticated" class="narrow" :title="t('matches.signed_out.title')">
      <p class="muted">{{ t('matches.signed_out.body') }}</p>
      <RouterLink to="/login" role="button">{{ t('matches.signed_out.log_in') }}</RouterLink>
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
          {{ tn('matches.toolbar.trips', tripCount) }} ·
          <RouterLink to="/trips">{{ t('matches.toolbar.my_trips') }}</RouterLink>
        </p>
      </div>

      <PanelCard>
        <p v-if="loading && !loaded" class="muted empty">{{ t('matches.list.loading') }}</p>

        <div v-else-if="!visibleRows.length" class="empty">
          <p class="muted">{{ EMPTY_COPY[activeTab] }}</p>
          <RouterLink v-if="activeTab !== 'passed'" :to="deckLink" class="soft-btn" role="button">
            {{ t('matches.empty.go_to_discovery') }}
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
                <PillBadge v-if="activeTab === 'matched'">{{
                  t('matches.tabs.matched')
                }}</PillBadge>
                <PillBadge v-else-if="row.their_decision === 'interested'">{{
                  t('matches.tabs.incoming')
                }}</PillBadge>
                <PillBadge v-else-if="activeTab === 'awaiting'" tone="neutral">{{
                  t('matches.tabs.awaiting')
                }}</PillBadge>
              </div>
              <div v-if="subline(row)" class="tiny muted">{{ subline(row) }}</div>
              <div class="tiny muted">{{ t('matches.row.since', { date: since(row) }) }}</div>

              <p v-if="activeTab === 'incoming' && isGuest" class="tiny muted note">
                {{ t('matches.row.accept_costs_pick') }}
              </p>
            </div>

            <div class="actions">
              <template v-if="activeTab === 'matched'">
                <RouterLink
                  :to="`/messages/${row.counterpart_id}`"
                  role="button"
                  class="accept"
                  data-test="message-link"
                >
                  {{ t('matches.actions.message') }}
                </RouterLink>
                <button
                  v-if="canUnmatch(row)"
                  type="button"
                  class="soft-btn"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'revoke', $event)"
                >
                  {{ t('matches.actions.unmatch') }}
                </button>
              </template>

              <template v-else-if="activeTab === 'incoming'">
                <RouterLink v-if="outOfPicks" to="/unlock" role="button" class="accept">
                  {{ t('matches.actions.unlock_to_accept') }}
                </RouterLink>
                <button
                  v-else
                  type="button"
                  class="accept"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'interested', $event)"
                >
                  <NavIcon name="matches" />{{ t('matches.actions.accept') }}
                </button>
                <button
                  type="button"
                  class="reject"
                  :disabled="busyId !== null"
                  @click="act(row, 'pass', $event)"
                >
                  {{ t('matches.actions.pass') }}
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
                  {{ t('matches.actions.withdraw') }}
                </button>
              </template>

              <template v-else>
                <RouterLink v-if="outOfPicks" to="/unlock" role="button" class="soft-btn">
                  {{ t('matches.actions.unlock_to_reconsider') }}
                </RouterLink>
                <button
                  v-else
                  type="button"
                  class="soft-btn"
                  :aria-busy="busyId === row.id"
                  :disabled="busyId !== null"
                  @click="act(row, 'interested', $event)"
                >
                  {{ t('matches.actions.reconsider') }}
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
