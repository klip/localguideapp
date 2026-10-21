<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import NavIcon from '@/components/NavIcon.vue'
import { t } from '@/i18n'
import { useApi, type MatchOverviewRow, type TripRow } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { formatWhen, utcDate } from '@/utils/bookingTime'
import { debounce } from '@/utils/debounce'

/**
 * The Discover page's "Your matches" list (traveller side), in two tabs:
 *
 *   Matched  — active matches with nothing confirmed yet (no booking, or a
 *              payment waiting on the guide) — `/matches`' Matched tab
 *   Upcoming — confirmed trips that haven't finished — `/trips`' Scheduled
 *              and In progress tabs
 *
 * Each name links to the guide's profile, with where and when underneath.
 * Unlike those pages it never marks anything seen (`markSeen`) — the menu
 * counters stay until the person opens Matches or My trips. Calls the API
 * itself (like `ReviewsSection`), and reloads when the server pushes
 * something; `reload()` is exposed for the page to call after a match forms.
 */
interface RailItem {
  key: string
  guideId: number
  name: string
  caption: string
}

type Tab = 'matched' | 'upcoming'

const api = useApi()
const auth = useAuthStore()
const badges = useBadgesStore()

const matches = ref<MatchOverviewRow[]>([])
const trips = ref<TripRow[]>([])
const loaded = ref(false)

function caption(place: string | null, meetingAt: string | null) {
  const when = formatWhen(utcDate(meetingAt)) ?? t('discover.rail.no_date')
  return place ? `${place} · ${when}` : when
}

const matchedItems = computed<RailItem[]>(() =>
  matches.value
    .filter((row) => row.active === 1 && row.booking_status !== 'confirmed')
    .map((row) => ({
      key: `m${row.id}`,
      guideId: row.counterpart_id,
      name: row.name || t('discover.rail.unnamed'),
      // A pending booking already has a time (and maybe a place); otherwise
      // where the guide works.
      caption: caption(
        row.booking_status === 'pending' ? row.location || row.guide_place : row.guide_place,
        row.booking_status === 'pending' ? row.meeting_at : null,
      ),
    })),
)

const upcomingItems = computed<RailItem[]>(() =>
  trips.value
    .filter((trip) => trip.phase !== 'past')
    // The server sends newest first; the next trip reads best on top.
    .slice()
    .reverse()
    .map((trip) => ({
      key: `t${trip.id}`,
      guideId: trip.counterpart_id,
      name: trip.name || t('discover.rail.unnamed'),
      caption: caption(trip.location || trip.guide_place, trip.meeting_at),
    })),
)

const items = computed<Record<Tab, RailItem[]>>(() => ({
  matched: matchedItems.value,
  upcoming: upcomingItems.value,
}))

/** An explicit choice wins; otherwise Matched if there are any, else Upcoming if there are. */
const chosenTab = ref<Tab | null>(null)
const activeTab = computed<Tab>(() => {
  if (chosenTab.value) return chosenTab.value
  if (!matchedItems.value.length && upcomingItems.value.length) return 'upcoming'
  return 'matched'
})

const TABS = computed(() => [
  {
    id: 'matched' as const,
    icon: 'matches' as const,
    label: t('discover.rail.tab_matched'),
    to: '/matches',
    link: t('discover.rail.view_matches'),
    empty: t('discover.rail.no_matches'),
  },
  {
    id: 'upcoming' as const,
    icon: 'trips' as const,
    label: t('discover.rail.tab_upcoming'),
    to: '/trips',
    link: t('discover.rail.view_trips'),
    empty: t('discover.rail.no_trips'),
  },
])
const active = computed(() => TABS.value.find((tab) => tab.id === activeTab.value)!)

async function reload() {
  if (!auth.user) return
  try {
    const [overview, tripList] = await Promise.all([api.matchOverview(), api.trips()])
    matches.value = overview?.selections ?? []
    trips.value = tripList?.trips ?? []
  } catch {
    // Best-effort: a side list, not worth a toast; the next push retries.
  } finally {
    loaded.value = true
  }
}

const reloadSoon = debounce(() => void reload(), 300)

onMounted(reload)
// A notification (a match, an accepted booking…) or a counter going up.
watch(() => badges.revision, reloadSoon)
watch(
  () => badges.matches + badges.trips,
  (count, previous) => {
    if (count > previous) reloadSoon()
  },
)

defineExpose({ reload })
</script>

<template>
  <div class="matches-rail">
    <div class="rail-tabs" role="tablist">
      <button
        v-for="tab in TABS"
        :id="`rail-tab-${tab.id}`"
        :key="tab.id"
        type="button"
        role="tab"
        class="rail-tab"
        :class="{ 'rail-tab--active': activeTab === tab.id }"
        :aria-selected="activeTab === tab.id"
        aria-controls="rail-panel"
        :data-test="`rail-tab-${tab.id}`"
        @click="chosenTab = tab.id"
      >
        <NavIcon :name="tab.icon" />
        {{ tab.label }}
        <span class="rail-count">{{ items[tab.id].length }}</span>
      </button>
    </div>

    <div id="rail-panel" role="tabpanel" :aria-labelledby="`rail-tab-${activeTab}`">
      <ul v-if="items[activeTab].length" class="rail-list">
        <li v-for="item in items[activeTab]" :key="item.key">
          <RouterLink :to="{ name: 'guide-profile', params: { id: item.guideId } }">{{
            item.name
          }}</RouterLink>
          <span class="tiny muted">{{ item.caption }}</span>
        </li>
      </ul>
      <p v-else-if="loaded" class="tiny muted">{{ active.empty }}</p>

      <RouterLink :to="active.to" class="tiny rail-link" :data-test="`rail-link-${active.id}`"
        >{{ active.link }} <span class="rtl-flip" aria-hidden="true">→</span></RouterLink
      >
    </div>
  </div>
</template>

<style scoped lang="scss">
.rail-tabs {
  display: flex;
  gap: 0.3rem;
  margin-bottom: 0.6rem;
}

// Small versions of the Matches / My trips page tabs.
.rail-tab {
  flex: 1;
  width: auto;
  margin: 0;
  padding: 0.35rem 0.5rem;
  border-radius: 999px;
  border: 1px solid var(--rg-line);
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.8rem;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;

  &:hover,
  &:focus-visible {
    border-color: var(--rg-orange-300);
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }

  :deep(.nav-icon) {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
  }
}

.rail-tab--active,
.rail-tab--active:hover,
.rail-tab--active:focus-visible {
  background: var(--rg-orange-500);
  border-color: var(--rg-orange-500);
  color: #fff;
}

.rail-count {
  min-width: 1.2rem;
  padding: 0 0.3rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.07);
  font-size: 0.7rem;
  line-height: 1.2rem;
  text-align: center;

  .rail-tab--active & {
    background: rgba(255, 255, 255, 0.25);
  }
}

.rail-list {
  list-style: none;
  padding: 0;
  margin: 0 0 0.6rem;

  li {
    list-style: none;
    display: flex;
    flex-direction: column;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--rg-line);

    &:last-child {
      border-bottom: 0;
    }
  }

  a {
    font-weight: 600;
    overflow-wrap: anywhere;
  }
}

.rail-link {
  display: inline-block;
  margin-bottom: 0.75rem;
}
</style>
