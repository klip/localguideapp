<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BookingPanel from '@/components/BookingPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useApi, type TripPhase, type TripRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useShortlistStore } from '@/stores/shortlist'
import { formatHours, formatMoney, formatWhen, utcDate } from '@/utils/bookingTime'
import { reportApiError } from '@/utils/reportApiError'

/**
 * My trips, for both roles — every booking that was ever confirmed
 * (`api.trips()` → `Bookings::tripsFor()`), in three tabs:
 *
 *   scheduled — confirmed, not started yet
 *   current   — the meeting time has passed, the trip isn't over
 *   past      — finished, or cancelled
 *
 * A pair arrives here from `/matches` the moment its booking is confirmed (the
 * guide accepted the payment, or straight away for a free guide) and leaves
 * Matches for good. Cancelling a trip dissolves the match, so both people go
 * back into each other's discovery deck; the cancelled trip stays under Past.
 *
 * Everything you can do to an upcoming trip — cancel, ask to cancel, set the
 * meeting place, mark it finished — is the same `BookingPanel` the Matches
 * page uses, minus its summary line (the card shows that itself).
 */
const api = useApi()
const auth = useAuthStore()
const shortlist = useShortlistStore()

const isGuest = computed(() => auth.user?.role === 'guest')
const counterpartNoun = computed(() => (isGuest.value ? 'guide' : 'visitor'))

const trips = ref<TripRow[]>([])
const loading = ref(false)
const loaded = ref(false)

const grouped = computed(() => {
  const out: Record<TripPhase, TripRow[]> = { scheduled: [], current: [], past: [] }
  for (const trip of trips.value) out[trip.phase].push(trip)
  // The server sends newest first; upcoming trips read better soonest first.
  out.scheduled.reverse()
  return out
})

const tabs: { id: TripPhase; label: string }[] = [
  { id: 'scheduled', label: 'Scheduled' },
  { id: 'current', label: 'In progress' },
  { id: 'past', label: 'Past' },
]

/** Explicit choice wins; otherwise a trip under way, then the next one up, then history. */
const chosenTab = ref<TripPhase | null>(null)
const activeTab = computed<TripPhase>(() => {
  if (chosenTab.value) return chosenTab.value
  if (grouped.value.current.length) return 'current'
  if (grouped.value.scheduled.length) return 'scheduled'
  if (grouped.value.past.length) return 'past'
  return 'scheduled'
})
const visibleTrips = computed(() => grouped.value[activeTab.value])

async function load() {
  if (!auth.user) return

  loading.value = true
  try {
    const [result, paymentState] = await Promise.all([
      api.trips(),
      // A cancelled trip hands the guest their pick back — keep the header's
      // counter and the deck's local picks in step with the server.
      isGuest.value ? api.paymentState() : Promise.resolve(null),
    ])
    trips.value = result.trips
    if (paymentState) shortlist.applyPaymentState(paymentState)
  } catch (err) {
    reportApiError(err, 'Could not load your trips.')
  } finally {
    loading.value = false
    loaded.value = true
  }
}

onMounted(load)

function displayName(trip: TripRow) {
  return trip.name || `Your ${counterpartNoun.value}`
}

function profileLink(trip: TripRow) {
  return isGuest.value ? `/guides/${trip.counterpart_id}` : `/visitors/${trip.counterpart_id}`
}

function photo(trip: TripRow) {
  if (trip.image) return `url("${trip.image}")`
  return isGuest.value ? 'var(--rg-photo-guide)' : 'var(--rg-photo-visitor)'
}

function initial(trip: TripRow) {
  return displayName(trip).trim().charAt(0).toUpperCase()
}

function dateLabel(trip: TripRow) {
  return formatWhen(utcDate(trip.meeting_at)) ?? 'Date to be arranged'
}

function durationLabel(trip: TripRow) {
  return formatHours(trip.booking_duration_hours) ?? 'Length not set'
}

function priceLabel(trip: TripRow) {
  if (trip.payment_status === 'none') return 'Free'
  const paid = `${formatMoney(trip.booking_amount)} ${isGuest.value ? 'paid' : 'fee'}`
  return trip.payment_status === 'refunded'
    ? `${paid} · ${formatMoney(trip.refund_amount)} refunded`
    : paid
}

function status(trip: TripRow): { label: string; tone?: 'neutral' } {
  if (trip.booking_status === 'cancelled') return { label: 'Cancelled', tone: 'neutral' }
  if (trip.phase === 'past') return { label: 'Finished', tone: 'neutral' }
  if (trip.phase === 'current') return { label: 'In progress' }
  return { label: 'Scheduled' }
}

/** Cancelled trips have nothing left to do; finished ones keep the review link and "Book again". */
function hasPanel(trip: TripRow) {
  return trip.booking_status === 'confirmed'
}

function reviewLink(trip: TripRow) {
  return trip.booking_status === 'confirmed' && trip.trip_status === 'finished'
    ? profileLink(trip)
    : null
}

const EMPTY_COPY = computed<Record<TripPhase, string>>(() => ({
  scheduled: isGuest.value
    ? 'No upcoming trips. Once a guide you’ve matched with confirms your booking, it shows up here.'
    : 'No upcoming trips. Once you confirm a visitor’s booking, it shows up here.',
  current: 'No trip in progress right now.',
  past: 'No finished or cancelled trips yet.',
}))
</script>

<template>
  <section class="section shell">
    <SectionTitle
      eyebrow="Your trips"
      heading="My trips"
      :lead="
        isGuest
          ? 'Every tour you’ve booked — coming up, under way, and done.'
          : 'Every tour you’ve confirmed — coming up, under way, and done.'
      "
    />

    <PanelCard v-if="!auth.isAuthenticated" class="narrow" title="Log in to see your trips">
      <p class="muted">Trips are tied to your account.</p>
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
      </div>

      <PanelCard v-if="loading && !loaded">
        <p class="muted empty">Loading your trips…</p>
      </PanelCard>

      <PanelCard v-else-if="!visibleTrips.length">
        <div class="empty">
          <p class="muted">{{ EMPTY_COPY[activeTab] }}</p>
          <RouterLink v-if="activeTab === 'scheduled'" to="/matches" class="soft-btn" role="button">
            Go to Matches
          </RouterLink>
        </div>
      </PanelCard>

      <ul v-else class="trip-list" :aria-busy="loading">
        <li v-for="trip in visibleTrips" :key="trip.id">
          <PanelCard class="trip-card">
            <div class="trip-head">
              <div class="avatar" :style="{ backgroundImage: photo(trip) }" aria-hidden="true">
                <span v-if="!trip.image">{{ initial(trip) }}</span>
              </div>
              <div class="who">
                <RouterLink :to="profileLink(trip)" class="who-name">
                  <strong>{{ displayName(trip) }}</strong>
                </RouterLink>
                <PillBadge :tone="status(trip).tone">{{ status(trip).label }}</PillBadge>
              </div>
            </div>

            <dl class="facts">
              <div>
                <dt>Date</dt>
                <dd>{{ dateLabel(trip) }}</dd>
              </div>
              <div>
                <dt>Duration</dt>
                <dd>{{ durationLabel(trip) }}</dd>
              </div>
              <div class="wide">
                <dt>Location</dt>
                <dd :class="{ muted: !trip.location }">
                  {{ trip.location || 'Meeting place not set yet' }}
                </dd>
              </div>
              <div class="wide">
                <dt>Price</dt>
                <dd>{{ priceLabel(trip) }}</dd>
              </div>
            </dl>

            <dl v-if="trip.email || trip.phone" class="contact">
              <div v-if="trip.email">
                <dt>Email</dt>
                <dd>
                  <a :href="`mailto:${trip.email}`">{{ trip.email }}</a>
                </dd>
              </div>
              <div v-if="trip.phone">
                <dt>Phone</dt>
                <dd>
                  <a :href="`tel:${trip.phone.replace(/\s+/g, '')}`">{{ trip.phone }}</a>
                </dd>
              </div>
            </dl>

            <p
              v-if="trip.booking_status === 'cancelled' && trip.cancel_reason"
              class="tiny muted note"
            >
              {{ trip.cancel_reason }}
            </p>
            <p v-if="reviewLink(trip)" class="note">
              <RouterLink :to="reviewLink(trip)!" class="tiny"
                >Leave {{ displayName(trip) }} a review →</RouterLink
              >
            </p>

            <BookingPanel
              v-if="hasPanel(trip)"
              :row="trip"
              :is-guest="isGuest"
              :name="displayName(trip)"
              :allow-booking="trip.is_latest"
              :summary="false"
              @changed="load"
            />
          </PanelCard>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.toolbar {
  margin-bottom: 1rem;
}

// Same pill tabs as MatchesPage.vue.
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

.trip-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 1rem;

  > li {
    list-style: none;
    min-width: 0;
  }

  @include bp.lg {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
  }
}

.trip-head {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}

.avatar {
  flex-shrink: 0;
  width: 3rem;
  height: 3rem;
  border-radius: 50%;
  background-size: cover;
  background-position: center;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 800;
  font-size: 1.1rem;
}

.who {
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem 0.6rem;

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

.facts {
  margin: 0.9rem 0 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.6rem 1rem;

  .wide {
    grid-column: 1 / -1;
  }

  dt {
    color: var(--rg-muted);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  dd {
    margin: 0.1rem 0 0;
    font-size: 0.95rem;
    overflow-wrap: anywhere;
  }
}

.contact {
  margin: 0.75rem 0 0;
  padding-top: 0.6rem;
  border-top: 1px solid var(--rg-line);
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1.25rem;
  font-size: 0.9rem;

  div {
    display: flex;
    gap: 0.4rem;
    min-width: 0;
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
  margin: 0.5rem 0 0;
}

.trip-card :deep(.booking) {
  margin-top: 0.4rem;
}
</style>
