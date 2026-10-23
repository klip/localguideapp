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
  /** The other person — a guide for a traveller, a traveller for a guide. */
  personId: number
  name: string
  /** Their photo (a data URL), or null for the initial. */
  image: string | null
  caption: string
  /** A trip under way: "In progress" stands in for the date. */
  inProgress?: boolean
}

type Tab = 'matched' | 'upcoming'

const api = useApi()
const auth = useAuthStore()
/** Whose profile a name opens: a guide's while travelling, a traveller's while guiding. */
const profileRoute = computed(() =>
  auth.activeRole === 'guide' ? 'visitor-profile' : 'guide-profile',
)
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
      personId: row.counterpart_id,
      name: row.name || t('discover.rail.unnamed'),
      image: row.image || null,
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
    // Trips under way and trips still to come; the rest are history.
    .filter((trip) => trip.phase !== 'past')
    // The server sends newest first: under way on top, then the next one up.
    .slice()
    .reverse()
    .sort((a, b) => Number(b.phase === 'current') - Number(a.phase === 'current'))
    .map((trip) => {
      const place = trip.location || trip.guide_place
      const inProgress = trip.phase === 'current'
      return {
        key: `t${trip.id}`,
        personId: trip.counterpart_id,
        name: trip.name || t('discover.rail.unnamed'),
        image: trip.image || null,
        // Under way: the place only — the "In progress" label says when.
        caption: inProgress ? (place ?? '') : caption(place, trip.meeting_at),
        inProgress,
      }
    }),
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
    // Who might like you back: a guide while travelling, a traveller while guiding.
    empty: t(
      auth.activeRole === 'guide' ? 'discover.rail.no_matches_guide' : 'discover.rail.no_matches',
    ),
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
        :aria-label="`${tab.label} (${items[tab.id].length})`"
        :title="tab.label"
        aria-controls="rail-panel"
        :data-test="`rail-tab-${tab.id}`"
        @click="chosenTab = tab.id"
      >
        <NavIcon :name="tab.icon" />
        <span class="rail-count" aria-hidden="true">{{ items[tab.id].length }}</span>
      </button>
    </div>

    <div id="rail-panel" role="tabpanel" :aria-labelledby="`rail-tab-${activeTab}`">
      <ul v-if="items[activeTab].length" class="rail-list">
        <li v-for="item in items[activeTab]" :key="item.key">
          <span class="rail-avatar" aria-hidden="true">
            <img v-if="item.image" :src="item.image" alt="" />
            <template v-else>{{ Array.from(item.name)[0] }}</template>
          </span>
          <span class="rail-who">
            <RouterLink :to="{ name: profileRoute, params: { id: item.personId } }">{{
              item.name
            }}</RouterLink>
            <span class="tiny muted rail-caption">
              <span v-if="item.inProgress" class="rail-live" data-test="rail-in-progress">{{
                t('discover.rail.in_progress')
              }}</span>
              <template v-if="item.caption">{{ item.caption }}</template>
            </span>
          </span>
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
// A segmented control: the open tab raised (docs/design/sketches/04-…png).
.rail-tabs {
  display: flex;
  gap: 2px;
  margin-bottom: 0.6rem;
  padding: 3px;
  border-radius: var(--rg-radius-control);
  background: var(--rg-sunken);
  border: 1px solid var(--rg-line);
}

.rail-tab {
  flex: 1;
  width: auto;
  height: 2rem;
  margin: 0;
  padding: 0 0.5rem;
  border-radius: 0.4rem;
  border: 0;
  background: transparent;
  color: var(--rg-muted);
  font-size: 0.8125rem;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
  box-shadow: none;

  &:hover,
  &:focus-visible {
    background: transparent;
    color: var(--rg-ink);
  }

  :deep(.nav-icon) {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
  }
}

.rail-tab--active,
.rail-tab--active:hover,
.rail-tab--active:focus-visible {
  background: #fff;
  color: var(--rg-ink);
  box-shadow: 0 1px 3px rgba(42, 31, 23, 0.14);
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
    background: var(--rg-orange-100);
  }
}

.rail-caption {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.15rem 0.4rem;
}

// A trip under way, in the brand colour so it stands out from the dates.
.rail-live {
  padding: 0 0.45rem;
  border-radius: 999px;
  background: var(--rg-orange-500);
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 1.35rem;
}

.rail-list {
  list-style: none;
  padding: 0;
  margin: 0 0 0.6rem;

  li {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0;
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

.rail-avatar {
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  overflow: hidden;
  border-radius: 50%;
  background: var(--rg-orange-100);
  color: var(--rg-orange-ink);
  font-weight: 800;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.rail-who {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.rail-link {
  display: inline-block;
  margin-bottom: 0.75rem;
}
</style>
