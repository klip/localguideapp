<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import PlaceSearch from '@/components/PlaceSearch.vue'
import PlannerGuideCard from '@/components/PlannerGuideCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { currentLanguage, t, tn } from '@/i18n'
import {
  useApi,
  type ParsedStop,
  type PlacePoint,
  type TripPlan,
  type TripPlanSummary,
  type TripStop,
} from '@/plugins/api'
import { socket } from '@/plugins/socket'
import { useAuthStore } from '@/stores/auth'
import { toGuideCard } from '@/stores/guides'
import { useMatchesStore } from '@/stores/matches'
import { useMessagesStore } from '@/stores/messages'
import type { Guide } from '@/types'
import { reportApiError } from '@/utils/reportApiError'
import { formatStay, todayIso } from '@/utils/tripDates'

/**
 * The travel planner (`/planner`, traveller mode): a trip's stops — a place
 * and dates each, typed one by one or read from a description ("Lisbon 3–5
 * May, then Porto") — saved as a trip; the server then finds up to four
 * guides per stop in the background (`TripPlanner`) and pushes `trip_plan`
 * when they're ready (the page also re-checks every few seconds while it
 * waits, in case a push is missed). **Interested** is the deck's: it costs a
 * pick (`matchesStore.pick()` then `api.decide()`); with no pack it goes to
 * `/unlock`. The open trip is in the URL (`?trip=<id>`).
 */
const MAX_STOPS = 14
const POLL_MS = 8000

interface DraftStop {
  key: number
  place: PlacePoint | null
  arriveOn: string
  departOn: string
  /** Read from a description but not placed by the geocoder — the traveller picks it. */
  unplaced: string | null
}

const api = useApi()
const auth = useAuthStore()
const matchesStore = useMatchesStore()
const messages = useMessagesStore()
const route = useRoute()
const router = useRouter()

const isTraveller = computed(() => auth.isAuthenticated && auth.activeRole === 'guest')
const plans = ref<TripPlanSummary[]>([])
const plan = ref<TripPlan | null>(null)
const loading = ref(true)
const saving = ref(false)
const confirmingDelete = ref(false)

const title = ref('')
const stops = ref<DraftStop[]>([])
let nextKey = 1

const describeText = ref('')
const reading = ref(false)

const today = todayIso()

function blankStop(arriveOn: string): DraftStop {
  return { key: nextKey++, place: null, arriveOn, departOn: arriveOn, unplaced: null }
}

function fromPlan(saved: TripPlan) {
  title.value = saved.title ?? ''
  stops.value = saved.stops.map((stop) => ({
    key: nextKey++,
    place: {
      label: stop.label,
      countryCode: stop.countryCode,
      latitude: stop.latitude,
      longitude: stop.longitude,
    },
    arriveOn: stop.arriveOn,
    departOn: stop.departOn,
    unplaced: null,
  }))
}

/** What the trip would be saved as — compared with the saved one to tell an edit apart. */
function signature(
  name: string,
  list: {
    label: string
    latitude: number
    longitude: number
    arriveOn: string
    departOn: string
  }[],
) {
  return JSON.stringify([
    name.trim(),
    list.map((stop) => [
      stop.label,
      stop.latitude.toFixed(2),
      stop.longitude.toFixed(2),
      stop.arriveOn,
      stop.departOn,
    ]),
  ])
}

const edited = computed(() => {
  if (!plan.value) return false
  const draft = stops.value.map((stop) => ({
    label: stop.place?.label ?? '',
    latitude: stop.place?.latitude ?? 0,
    longitude: stop.place?.longitude ?? 0,
    arriveOn: stop.arriveOn,
    departOn: stop.departOn,
  }))
  return signature(title.value, draft) !== signature(plan.value.title ?? '', plan.value.stops)
})

const waiting = computed(
  () => plan.value?.status === 'pending' || plan.value?.status === 'processing',
)

const guidesById = computed(() => {
  const map = new Map<number, Guide>()
  for (const account of plan.value?.guides ?? []) map.set(Number(account.id), toGuideCard(account))
  return map
})

/** A stop's suggestions that still resolve to a card (a guide can go away between runs). */
function suggestionsFor(stop: TripStop) {
  return (stop.suggestions ?? [])
    .map((suggestion) => ({ ...suggestion, guide: guidesById.value.get(suggestion.guideId) }))
    .filter((item): item is typeof item & { guide: Guide } => !!item.guide)
}

function tripName(summary: TripPlanSummary) {
  return summary.title || t('planner.trips.untitled', { place: summary.places[0] ?? '' })
}

// ------------------------------------------------------------ loading

async function loadPlans() {
  try {
    plans.value = (await api.tripPlans()).plans
  } catch (err) {
    reportApiError(err, t('planner.toast.load_failed'))
  }
}

async function openPlan(id: number, quiet = false) {
  try {
    const saved = await api.tripPlan(id)
    // A slower answer for a trip the traveller has since left.
    if (quiet && plan.value?.id !== id) return
    const keepDraft = quiet && edited.value
    plan.value = saved
    if (!keepDraft) fromPlan(saved)
    confirmingDelete.value = false
    if (route.query.trip !== String(id)) router.replace({ query: { trip: String(id) } })
    schedulePoll()
  } catch (err) {
    if (!quiet) {
      reportApiError(err, t('planner.toast.load_failed'))
      newTrip()
    }
  }
}

function newTrip() {
  plan.value = null
  title.value = ''
  stops.value = [blankStop(today)]
  describeText.value = ''
  confirmingDelete.value = false
  if (route.query.trip) router.replace({ query: {} })
}

let pollTimer: ReturnType<typeof setTimeout> | undefined

function schedulePoll() {
  clearTimeout(pollTimer)
  if (!waiting.value || !plan.value) return
  const id = plan.value.id
  pollTimer = setTimeout(() => void openPlan(id, true), POLL_MS)
}

const stopPush = socket.on('trip_plan', (payload) => {
  const id = Number((payload as { planId?: number }).planId)
  if (plan.value?.id === id) void openPlan(id, true)
  void loadPlans()
})

onBeforeUnmount(() => {
  clearTimeout(pollTimer)
  stopPush()
})

onMounted(async () => {
  if (!isTraveller.value) {
    loading.value = false
    return
  }
  // Picks decide what Interested can do; App.vue syncs them at boot, but may not have yet.
  api
    .paymentState()
    .then((state) => matchesStore.applyPaymentState(state))
    .catch(() => {})
  await loadPlans()
  const wanted = Number(route.query.trip)
  if (wanted > 0) await openPlan(wanted)
  else if (plans.value[0]) await openPlan(plans.value[0].id)
  else newTrip()
  loading.value = false
})

// ------------------------------------------------------------ editing

function addStop() {
  if (stops.value.length >= MAX_STOPS) return
  const last = stops.value[stops.value.length - 1]
  stops.value.push(blankStop(last ? last.departOn : today))
}

function removeStop(index: number) {
  stops.value.splice(index, 1)
  if (!stops.value.length) stops.value.push(blankStop(today))
}

function setPlace(stop: DraftStop, place: PlacePoint) {
  stop.place = place
  stop.unplaced = null
}

function setArrive(stop: DraftStop, value: string) {
  stop.arriveOn = value
  if (stop.departOn < value) stop.departOn = value
}

async function readTrip() {
  const text = describeText.value.trim()
  if (text.length < 3 || reading.value) return
  reading.value = true
  try {
    const parsed = (await api.parseItinerary(text, currentLanguage.value)).stops
    if (!parsed.length) {
      messages.info(t('planner.editor.read_none'))
      return
    }
    stops.value = parsed.map((stop, index) => fromParsed(stop, parsed[index - 1]))
  } catch (err) {
    reportApiError(err, t('planner.toast.read_failed'))
  } finally {
    reading.value = false
  }
}

function fromParsed(stop: ParsedStop, previous: ParsedStop | undefined): DraftStop {
  const arriveOn = stop.arriveOn ?? previous?.departOn ?? today
  return {
    key: nextKey++,
    place: stop.place
      ? {
          label: stop.place.label,
          countryCode: stop.place.countryCode,
          latitude: stop.place.latitude,
          longitude: stop.place.longitude,
        }
      : null,
    arriveOn,
    departOn: stop.departOn && stop.departOn >= arriveOn ? stop.departOn : arriveOn,
    unplaced: stop.place ? null : stop.query,
  }
}

function problem(): string | null {
  for (const [index, stop] of stops.value.entries()) {
    const n = index + 1
    if (!stop.place) return t('planner.editor.missing_place', { n })
    if (!stop.arriveOn || !stop.departOn) return t('planner.editor.missing_dates', { n })
    if (stop.departOn < stop.arriveOn) return t('planner.editor.bad_dates', { n })
  }
  return null
}

async function save() {
  const issue = problem()
  if (issue) {
    messages.error(issue)
    return
  }
  saving.value = true
  try {
    const saved = await api.saveTripPlan({
      ...(plan.value ? { planId: plan.value.id } : {}),
      title: title.value.trim() || null,
      language: currentLanguage.value,
      stops: stops.value.map((stop) => ({
        place: stop.place!,
        arriveOn: stop.arriveOn,
        departOn: stop.departOn,
      })),
    })
    plan.value = saved
    fromPlan(saved)
    router.replace({ query: { trip: String(saved.id) } })
    schedulePoll()
    void loadPlans()
  } catch (err) {
    reportApiError(err, t('planner.toast.save_failed'))
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!plan.value) return
  try {
    await api.deleteTripPlan(plan.value.id)
    messages.success(t('planner.toast.deleted'))
    newTrip()
    await loadPlans()
  } catch (err) {
    reportApiError(err, t('planner.toast.save_failed'))
  }
}

// ------------------------------------------------------------ picks

async function interested(guideId: number) {
  const id = String(guideId)
  if (!matchesStore.hasAccess) {
    router.push('/unlock')
    return
  }
  matchesStore.pick(id)
  if (!matchesStore.isPicked(id)) return
  try {
    await api.decide({ targetId: guideId, decision: 'interested' })
  } catch (err) {
    reportApiError(err, t('planner.toast.decision_failed'))
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('planner.page.eyebrow')"
      :heading="t('planner.page.title')"
      :lead="t('planner.page.lead')"
    />

    <PanelCard v-if="!auth.isAuthenticated">
      <p>{{ t('planner.page.signed_out') }}</p>
      <RouterLink to="/login" role="button">{{ t('planner.page.log_in') }}</RouterLink>
    </PanelCard>

    <PanelCard v-else-if="!isTraveller">
      <p data-test="planner-travellers-only">{{ t('planner.page.travellers_only') }}</p>
    </PanelCard>

    <div v-else class="planner-layout">
      <aside class="trips">
        <div class="trips-head">
          <strong>{{ t('planner.trips.title') }}</strong>
          <button type="button" class="link-btn tiny" data-test="planner-new" @click="newTrip">
            + {{ t('planner.trips.new') }}
          </button>
        </div>
        <p v-if="!loading && !plans.length" class="tiny muted">{{ t('planner.trips.empty') }}</p>
        <ul class="trip-list">
          <li v-for="summary in plans" :key="summary.id">
            <button
              type="button"
              class="trip-item"
              :class="{ active: plan?.id === summary.id }"
              :aria-current="plan?.id === summary.id ? 'true' : undefined"
              :data-test="`planner-trip-${summary.id}`"
              @click="openPlan(summary.id)"
            >
              <strong>{{ tripName(summary) }}</strong>
              <span class="tiny muted">
                <template v-if="summary.firstDay && summary.lastDay">
                  {{ formatStay(summary.firstDay, summary.lastDay) }} ·
                </template>
                {{ tn('planner.trips.stops', summary.stopCount) }}
              </span>
            </button>
          </li>
        </ul>
      </aside>

      <div class="main">
        <PanelCard :title="plan ? t('planner.editor.title_edit') : t('planner.editor.title_new')">
          <label>
            {{ t('planner.editor.name') }}
            <input
              v-model="title"
              type="text"
              maxlength="80"
              :placeholder="t('planner.editor.name_placeholder')"
              data-test="planner-title"
            />
          </label>

          <details class="describe" :open="!plan">
            <summary>{{ t('planner.editor.describe') }}</summary>
            <textarea
              v-model="describeText"
              rows="3"
              maxlength="1000"
              :placeholder="t('planner.editor.describe_placeholder')"
              data-test="planner-describe"
            />
            <small class="tiny muted">{{ t('planner.editor.describe_hint') }}</small>
            <button
              type="button"
              class="secondary"
              :aria-busy="reading || undefined"
              :disabled="reading || describeText.trim().length < 3"
              data-test="planner-read"
              @click="readTrip"
            >
              {{ reading ? t('planner.editor.reading') : t('planner.editor.read') }}
            </button>
          </details>

          <ol class="stops">
            <li
              v-for="(stop, index) in stops"
              :key="stop.key"
              class="stop"
              :data-test="`planner-stop-${index}`"
            >
              <div class="stop-head">
                <strong>{{ t('planner.editor.stop', { n: index + 1 }) }}</strong>
                <button
                  type="button"
                  class="link-btn tiny"
                  :aria-label="t('planner.editor.remove', { n: index + 1 })"
                  :data-test="`planner-remove-${index}`"
                  @click="removeStop(index)"
                >
                  ✕
                </button>
              </div>
              <p v-if="stop.unplaced" class="tiny warn" role="status">
                {{ t('planner.editor.unplaced', { query: stop.unplaced }) }}
              </p>
              <PlaceSearch
                :model-value="stop.place"
                :near="stops[index - 1]?.place ?? null"
                @update:model-value="(place) => place && setPlace(stop, place)"
              />
              <div class="dates">
                <label>
                  {{ t('planner.editor.from') }}
                  <input
                    type="date"
                    :min="today"
                    :value="stop.arriveOn"
                    :data-test="`planner-from-${index}`"
                    @change="setArrive(stop, ($event.target as HTMLInputElement).value)"
                  />
                </label>
                <label>
                  {{ t('planner.editor.to') }}
                  <input
                    v-model="stop.departOn"
                    type="date"
                    :min="stop.arriveOn || today"
                    :data-test="`planner-to-${index}`"
                  />
                </label>
              </div>
            </li>
          </ol>

          <button
            v-if="stops.length < MAX_STOPS"
            type="button"
            class="secondary outline add-stop"
            data-test="planner-add"
            @click="addStop"
          >
            + {{ t('planner.editor.add') }}
          </button>

          <p class="tiny muted">{{ t('planner.editor.privacy') }}</p>

          <template #footer>
            <div class="editor-actions">
              <button
                type="button"
                :aria-busy="saving || undefined"
                :disabled="saving || waiting || (!!plan && !edited && plan.status === 'ready')"
                data-test="planner-find"
                @click="save"
              >
                {{ saving ? t('planner.editor.finding') : t('planner.editor.find') }}
              </button>
              <template v-if="plan">
                <span v-if="confirmingDelete" class="confirm">
                  {{ t('planner.editor.confirm_delete') }}
                  <button
                    type="button"
                    class="link-btn"
                    data-test="planner-delete-yes"
                    @click="remove"
                  >
                    {{ t('planner.editor.confirm_yes') }}
                  </button>
                  <button type="button" class="link-btn" @click="confirmingDelete = false">
                    {{ t('planner.editor.cancel') }}
                  </button>
                </span>
                <button
                  v-else
                  type="button"
                  class="link-btn"
                  data-test="planner-delete"
                  @click="confirmingDelete = true"
                >
                  {{ t('planner.editor.delete') }}
                </button>
              </template>
            </div>
          </template>
        </PanelCard>

        <section v-if="plan" class="results" aria-live="polite" data-test="planner-results">
          <p v-if="waiting" class="status" aria-busy="true" data-test="planner-waiting">
            {{ t('planner.results.pending') }}
          </p>
          <div v-else-if="plan.status === 'failed'" class="status">
            <p>{{ t('planner.results.failed') }}</p>
            <button type="button" class="secondary" data-test="planner-retry" @click="save">
              {{ t('planner.results.retry') }}
            </button>
          </div>
          <template v-else>
            <p v-if="edited" class="tiny warn">{{ t('planner.results.edited') }}</p>
            <p v-if="!plan.aiRanked" class="tiny muted">{{ t('planner.results.plain_order') }}</p>
            <p
              v-if="matchesStore.hasAccess && !matchesStore.canPick"
              class="tiny muted"
              data-test="planner-no-picks"
            >
              {{ t('planner.card.no_picks') }} ·
              <RouterLink to="/unlock">{{ t('planner.card.unlock') }}</RouterLink>
            </p>

            <section v-for="(stop, index) in plan.stops" :key="stop.id" class="stop-results">
              <h3>
                {{ index + 1 }} · {{ stop.label }}
                <small class="muted">{{ formatStay(stop.arriveOn, stop.departOn) }}</small>
              </h3>
              <p v-if="!suggestionsFor(stop).length" class="muted" data-test="planner-none">
                {{ t('planner.results.none', { place: stop.label }) }}
              </p>
              <template v-else>
                <p v-if="suggestionsFor(stop).length < 4" class="tiny muted">
                  {{
                    tn('planner.results.few', suggestionsFor(stop).length, { place: stop.label })
                  }}
                </p>
                <div class="guide-grid">
                  <PlannerGuideCard
                    v-for="item in suggestionsFor(stop)"
                    :key="item.guideId"
                    :guide="item.guide"
                    :reason="item.reason"
                    :distance-km="item.distanceKm"
                    :picked="matchesStore.isPicked(String(item.guideId))"
                    :can-pick="!matchesStore.hasAccess || matchesStore.canPick"
                    @interested="interested(item.guideId)"
                  />
                </div>
              </template>
            </section>
          </template>
        </section>
      </div>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.planner-layout {
  display: grid;
  // minmax(0, …): without it the column grows to its widest child (the date
  // inputs) and the page scrolls sideways on a phone.
  grid-template-columns: minmax(0, 1fr);
  gap: 1.25rem;

  @include bp.lg {
    grid-template-columns: 16rem minmax(0, 1fr);
    align-items: start;
  }
}

.trips-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 0.5rem;
}

.trip-list {
  margin: 0;
  padding: 0;

  li {
    list-style: none;
    margin: 0 0 0.4rem;
  }
}

.trip-item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  margin: 0;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
  background: var(--pico-card-background-color, #fff);
  color: inherit;
  text-align: left;

  &.active {
    border-color: var(--pico-primary);
    background: var(--rg-orange-50);
  }
}

.describe {
  margin-bottom: 1rem;

  textarea {
    margin-bottom: 0.25rem;
  }

  button {
    width: auto;
    margin-top: 0.5rem;
  }
}

.stops {
  margin: 0 0 0.75rem;
  padding: 0;
}

.stop {
  list-style: none;
  margin: 0 0 0.75rem;
  padding: 0.75rem;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
}

.stop-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 0.4rem;
}

.dates {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.75rem;

  label,
  input {
    margin-bottom: 0;
  }
}

.add-stop {
  width: auto;
}

.warn {
  color: var(--rg-orange-700, #b45309);
}

.editor-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1rem;

  button:not(.link-btn) {
    width: auto;
    margin: 0;
  }
}

.confirm {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: baseline;
}

.results {
  margin-top: 1.25rem;
}

.status button {
  width: auto;
}

.stop-results {
  margin-bottom: 1.5rem;

  h3 {
    margin-bottom: 0.4rem;
    font-size: 1.1rem;

    small {
      margin-left: 0.4rem;
      font-weight: 400;
      font-size: 0.85rem;
    }
  }
}

.guide-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
  gap: 0.9rem;
}

.link-btn {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  cursor: pointer;
}
</style>
