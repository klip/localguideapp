<script setup lang="ts">
import { computed, nextTick, reactive, ref, type ComponentPublicInstance } from 'vue'
import BookingPanel from '@/components/BookingPanel.vue'
import MapPinLinks from '@/components/MapPinLinks.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useLiveSection } from '@/composables/useLiveSection'
import { t } from '@/i18n'
import { useApi, type TripPhase, type TripRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMatchesStore } from '@/stores/matches'
import { formatHours, formatMoney, formatWhen, utcDate } from '@/utils/bookingTime'
import { percent } from '@/utils/levels'
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
const matchesStore = useMatchesStore()

const isGuest = computed(() => auth.activeRole === 'guest')
/** `guest`/`guide` — picks the role-specific wording for keys that differ by side. */
const side = computed(() => (isGuest.value ? 'guest' : 'guide'))

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

const TAB_IDS: TripPhase[] = ['scheduled', 'current', 'past']
const tabs = computed(() => TAB_IDS.map((id) => ({ id, label: t(`trips.tabs.${id}`) })))

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
    if (paymentState) matchesStore.applyPaymentState(paymentState)
  } catch (err) {
    reportApiError(err, t('trips.toast.load_failed'))
  } finally {
    loading.value = false
    loaded.value = true
  }
}

useLiveSection('trips', load)

function displayName(trip: TripRow) {
  return trip.name || t(`trips.card.unnamed_${side.value}`)
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
  return formatWhen(utcDate(trip.meeting_at)) ?? t('trips.card.date_tba')
}

function durationLabel(trip: TripRow) {
  return formatHours(trip.booking_duration_hours) ?? t('trips.card.length_not_set')
}

function priceLabel(trip: TripRow) {
  if (trip.payment_status === 'none') return t('trips.card.free')
  const paid =
    !isGuest.value &&
    trip.payment_status === 'paid' &&
    trip.guide_payout != null &&
    trip.commission_rate != null
      ? trip.commission_rate === 0
        ? t('trips.card.price_fee_no_fee', { amount: formatMoney(trip.booking_amount) })
        : t('trips.card.price_fee_payout', {
            amount: formatMoney(trip.booking_amount),
            payout: formatMoney(trip.guide_payout),
            rate: percent(trip.commission_rate),
          })
      : t(isGuest.value ? 'trips.card.price_paid' : 'trips.card.price_fee', {
          amount: formatMoney(trip.booking_amount),
        })
  return trip.payment_status === 'refunded'
    ? t('trips.card.price_refunded', { price: paid, refund: formatMoney(trip.refund_amount) })
    : paid
}

/** Where and when, for the card's one-line caption: "Lisbon · Sat 3 May, 10:00". */
function caption(trip: TripRow) {
  const place =
    trip.location ||
    trip.guide_place ||
    (trip.location_pin ? t('trips.card.location_pinned') : null)
  return [place, dateLabel(trip)].filter(Boolean).join(' · ')
}

/**
 * "Book again" sits in the card's header (the Past tab's only action), so it's
 * computed here rather than inside BookingPanel — the same conditions as its
 * `needsBooking`: the guest, the pair's latest trip, finished, still matched,
 * and a guide who charges.
 */
function canBookAgain(trip: TripRow) {
  return (
    isGuest.value &&
    trip.is_latest &&
    trip.active === 1 &&
    trip.booking_status === 'confirmed' &&
    trip.trip_status === 'finished' &&
    trip.fee_amount > 0
  )
}

/** Which cards are expanded — kept across reloads of the list (an action refetches it). */
const openIds = reactive(new Set<number>())
function onToggle(trip: TripRow, event: Event) {
  if ((event.target as HTMLDetailsElement).open) openIds.add(trip.id)
  else openIds.delete(trip.id)
}

const panels = new Map<number, InstanceType<typeof BookingPanel>>()
function setPanel(id: number, el: Element | ComponentPublicInstance | null) {
  if (el) panels.set(id, el as InstanceType<typeof BookingPanel>)
  else panels.delete(id)
}

/** Opens the card and its pay form. */
async function bookAgain(trip: TripRow) {
  openIds.add(trip.id)
  await nextTick()
  panels.get(trip.id)?.startBooking()
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
  scheduled: t(`trips.empty.scheduled_${side.value}`),
  current: t('trips.empty.current'),
  past: t('trips.empty.past'),
}))
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('trips.header.eyebrow')"
      :heading="t('trips.header.heading')"
      :lead="t(`trips.header.lead_${side}`)"
    />

    <PanelCard v-if="!auth.isAuthenticated" class="narrow" :title="t('trips.signed_out.title')">
      <p class="muted">{{ t('trips.signed_out.body') }}</p>
      <RouterLink to="/login" role="button">{{ t('trips.signed_out.log_in') }}</RouterLink>
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
        <p class="muted empty">{{ t('trips.list.loading') }}</p>
      </PanelCard>

      <PanelCard v-else-if="!visibleTrips.length">
        <div class="empty">
          <p class="muted">{{ EMPTY_COPY[activeTab] }}</p>
          <RouterLink v-if="activeTab === 'scheduled'" to="/matches" class="soft-btn" role="button">
            {{ t('trips.empty.go_to_matches') }}
          </RouterLink>
        </div>
      </PanelCard>

      <ul v-else class="trip-list" :aria-busy="loading">
        <li v-for="trip in visibleTrips" :key="trip.id">
          <details
            class="trip-card"
            :open="openIds.has(trip.id)"
            :data-test="`trip-${trip.id}`"
            @toggle="onToggle(trip, $event)"
          >
            <summary>
              <RouterLink
                :to="profileLink(trip)"
                class="avatar"
                :style="{ backgroundImage: photo(trip) }"
                tabindex="-1"
                aria-hidden="true"
              >
                <span v-if="!trip.image">{{ initial(trip) }}</span>
              </RouterLink>
              <span class="who">
                <RouterLink :to="profileLink(trip)" class="who-name">
                  <strong>{{ displayName(trip) }}</strong>
                </RouterLink>
                <span class="tiny muted caption">{{ caption(trip) }}</span>
              </span>
              <button
                v-if="canBookAgain(trip)"
                type="button"
                class="accept book-again"
                data-test="book-again"
                @click.prevent="bookAgain(trip)"
              >
                {{ t('common.booking_panel.book_again', { amount: formatMoney(trip.fee_amount) }) }}
              </button>
              <PillBadge v-else-if="trip.booking_status === 'cancelled'" tone="neutral">{{
                t('trips.status.cancelled')
              }}</PillBadge>
            </summary>

            <div class="trip-body">
              <dl class="facts">
                <div>
                  <dt>{{ t('trips.card.date') }}</dt>
                  <dd>{{ dateLabel(trip) }}</dd>
                </div>
                <div>
                  <dt>{{ t('trips.card.duration') }}</dt>
                  <dd>{{ durationLabel(trip) }}</dd>
                </div>
                <div class="wide">
                  <dt>{{ t('trips.card.location') }}</dt>
                  <dd :class="{ muted: !trip.location && !trip.location_pin }">
                    {{
                      trip.location ||
                      (trip.location_pin
                        ? t('trips.card.location_pinned')
                        : t('trips.card.location_not_set'))
                    }}
                    <MapPinLinks
                      v-if="trip.location_pin"
                      class="trip-pin"
                      :latitude="trip.location_pin.latitude"
                      :longitude="trip.location_pin.longitude"
                      :label="t('trips.card.location')"
                    />
                  </dd>
                </div>
                <div class="wide">
                  <dt>{{ t('trips.card.price') }}</dt>
                  <dd>{{ priceLabel(trip) }}</dd>
                </div>
              </dl>

              <p
                v-if="trip.booking_status === 'cancelled' && trip.cancel_reason"
                class="tiny muted note"
              >
                {{ trip.cancel_reason }}
              </p>
              <p v-if="trip.active && trip.booking_status !== 'cancelled'" class="note">
                <RouterLink
                  :to="`/messages/${trip.counterpart_id}`"
                  class="soft-btn message-btn"
                  role="button"
                  data-test="message-link"
                  >{{ t('trips.card.message', { name: displayName(trip) }) }}</RouterLink
                >
              </p>
              <p v-if="reviewLink(trip)" class="note">
                <RouterLink :to="reviewLink(trip)!" class="tiny"
                  >{{ t('trips.card.leave_review', { name: displayName(trip) }) }}
                  <span class="rtl-flip" aria-hidden="true">→</span></RouterLink
                >
              </p>

              <BookingPanel
                v-if="hasPanel(trip)"
                :ref="(el) => setPanel(trip.id, el)"
                :row="trip"
                :is-guest="isGuest"
                :name="displayName(trip)"
                :allow-booking="trip.is_latest"
                :summary="false"
                :book-button="false"
                @changed="load"
              />
            </div>
          </details>
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

// A compact panel: the summary row (photo, name, where and when, Book again)
// is all that shows until it's opened.
.trip-card {
  margin: 0;
  background: rgba(255, 255, 255, 0.9);
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-card);
  box-shadow: var(--rg-shadow);

  > summary {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.6rem 0.9rem;
    margin: 0;
    line-height: 1.3;

    // Pico draws its chevron as a floated ::after; keep it at the row's end.
    &::after {
      flex-shrink: 0;
      margin-inline-start: 0;
    }
  }
}

.trip-body {
  padding: 0 0.9rem 0.9rem;
}

.avatar {
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 50%;
  background-size: cover;
  background-position: center;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 800;
  font-size: 1rem;
  text-decoration: none;
}

.who {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;

  strong {
    overflow-wrap: anywhere;
  }
}

.caption {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.book-again {
  flex-shrink: 0;
  width: auto;
  margin: 0;
  padding: 0.35rem 0.75rem;
  font-size: 0.85rem;
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
  margin: 0.2rem 0 0;
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

.note {
  margin: 0.5rem 0 0;
}

// The only way to reach each other: no contact details are ever shown.
.message-btn {
  width: auto;
  margin: 0.25rem 0 0;
}

.trip-card :deep(.booking) {
  margin-top: 0.4rem;
}

.trip-pin {
  display: flex;
  margin-top: 0.2rem;
}
</style>
