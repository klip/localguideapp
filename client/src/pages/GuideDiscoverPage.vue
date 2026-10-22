<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import ClearFilters from '@/components/ClearFilters.vue'
import DeckBar from '@/components/DeckBar.vue'
import LocationPrompt from '@/components/LocationPrompt.vue'
import FilterPanel from '@/components/FilterPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import MatchesRail from '@/components/MatchesRail.vue'
import ProfileCard from '@/components/ProfileCard.vue'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useDeckLocation } from '@/composables/useDeckLocation'
import { useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { useMessagesStore } from '@/stores/messages'
import { useVisitorsStore } from '@/stores/visitors'
import { formatDistance } from '@/utils/distance'
import { formatStay } from '@/utils/tripDates'
import { debounce } from '@/utils/debounce'
import { reportApiError } from '@/utils/reportApiError'
import type { DiscoveryFilters } from '@/types'

/**
 * The guide side of the match — same one-card-at-a-time deck and
 * accept/reject buttons as DiscoverPage.vue's visitor-side deck (same
 * `ProfileCard`/`DecisionRow`, same default labels), just without that
 * page's pack/paywall framing: guides don't pay and never run out of
 * swipes, so there's nothing to unlock here.
 */
const api = useApi()
const messages = useMessagesStore()

const auth = useAuthStore()
// The search point in the URL, and (travellers) on the server for guides to see.
useDeckLocation()
const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const { filters } = storeToRefs(filtersStore)

// The filters rail can't be collapsed on desktop (its summary is a plain
// heading there — see `.filters-disclosure`), so it's open from `lg` up and
// starts closed below. The user's own toggle on mobile sticks until the
// viewport crosses the breakpoint.
const isDesktop = useMediaQuery(LG_QUERY)
const filtersOpen = ref(isDesktop.value)
watch(isDesktop, (desktop) => {
  filtersOpen.value = desktop
})
function onFiltersToggle(event: Event) {
  filtersOpen.value = (event.target as HTMLDetailsElement).open
}

const visitorsStore = useVisitorsStore()
const { items: visitors, loading: visitorsLoading } = storeToRefs(visitorsStore)

/** Filtered in SQL, not client-side — see the equivalent note in DiscoverPage.vue. */
const initialLoadDone = ref(false)
const debouncedLoad = debounce((next: DiscoveryFilters) => visitorsStore.load(next), 350)

onMounted(async () => {
  await visitorsStore.load(filters.value)
  initialLoadDone.value = true
  // Labels for the card's tag chips, and the filter bar's own category list.
  categoriesStore.load()
})

watch(filters, (next) => debouncedLoad(next), { deep: true })

// "2 adults · Visiting Lisbon, 3–5 May · 3 km away" (a planned stop), or
// "… · Looking in Lisbon, Portugal · …" (where they're searching) — each part when there is one.
const currentMeta = computed(() => {
  const visitor = current.value
  if (!visitor) return undefined
  const stop = visitor.nextStop
  return [
    visitor.party,
    stop
      ? t('guide_discover.deck.visiting', {
          place: stop.label,
          dates: formatStay(stop.arriveOn, stop.departOn),
        })
      : visitor.areaLabel
        ? t('guide_discover.deck.looking_in', { place: visitor.areaLabel })
        : null,
    visitor.distanceKm !== null ? formatDistance(visitor.distanceKm) : null,
  ]
    .filter((part): part is string => !!part)
    .join(' · ')
})

const interested = ref<string[]>([])
const passed = ref<string[]>([])

const deck = computed(() =>
  visitors.value.filter(
    (visitor) => !interested.value.includes(visitor.id) && !passed.value.includes(visitor.id),
  ),
)
const current = computed(() => deck.value[0])

/**
 * Records the decision server-side (see `Selections::decide()`) so a real
 * mutual match can actually happen — matching `Selections.decide()`'s
 * response tells us whether this decision was the one that completed it
 * (`active: 1`, both sides now 'interested'). Guides aren't gated by pack
 * capacity there (only guests are), so this should only fail on something
 * like a dropped session, not a "no picks left" case.
 */
async function decide(visitorId: string, decision: 'interested' | 'pass') {
  try {
    return await api.decide({ targetId: Number(visitorId), decision })
  } catch (err) {
    reportApiError(err, t('guide_discover.toast.decision_failed'))
    return null
  }
}

/** The matches rail, to refresh when a decision completes a match. */
const matchesRail = ref<InstanceType<typeof MatchesRail> | null>(null)

async function accept(id: string) {
  const visitor = visitorsStore.find(id)
  interested.value.push(id)

  const row = await decide(id, 'interested')
  if (row?.active === 1) {
    // A new match — it belongs on the rail's Matched tab now.
    void matchesRail.value?.reload()
    messages.success(
      t('guide_discover.toast.matched', {
        name: visitor?.name ?? t('guide_discover.toast.matched_fallback_name'),
      }),
    )
  }
}

function reject(id: string) {
  passed.value.push(id)
  void decide(id, 'pass')
}
</script>

<template>
  <section class="section shell">
    <!-- The filters in force, right under the menu (as on DiscoverPage.vue). -->
    <DeckBar />

    <div class="discovery-layout">
      <aside class="rail">
        <details class="filters-disclosure" :open="filtersOpen" @toggle="onFiltersToggle">
          <summary>
            <strong>{{ t('guide_discover.filters.title') }}</strong>
            <ClearFilters />
          </summary>
          <p class="tiny muted">{{ t('guide_discover.filters.hint') }}</p>
          <FilterPanel compact target-role="guest" />
        </details>
      </aside>

      <PanelCard>
        <template #header>
          <!-- The title with where, on one line. -->
          <div class="deck-head">
            <strong>{{ t('guide_discover.deck.title') }}</strong>
            <span class="tiny muted" data-test="deck-subtitle">
              {{
                filters.near
                  ? t('guide_discover.deck.subtitle_near', { place: filters.near.label })
                  : t('guide_discover.deck.subtitle')
              }}
            </span>
          </div>
        </template>

        <LocationPrompt />

        <ProfileCard
          v-if="current"
          :key="current.id"
          :name="current.name"
          :age="current.age"
          :photo="current.photo"
          :meta="currentMeta"
          :bio="current.bio"
          :tags="categoriesStore.tagsFor(current.attributes)"
          :profile-link="{ name: 'visitor-profile', params: { id: current.id } }"
          :standing="current.standing"
          @accept="accept(current.id)"
          @reject="reject(current.id)"
        />

        <div v-else-if="visitorsLoading && !initialLoadDone" class="empty">
          <h3>{{ t('guide_discover.empty.loading') }}</h3>
        </div>

        <!-- See the matching comment in DiscoverPage.vue: decided visitors are
             filtered out server-side, so an empty deck usually isn't an empty
             system. -->
        <div
          v-else-if="
            initialLoadDone &&
            visitors.length === 0 &&
            filtersStore.isPristine &&
            auth.isAuthenticated
          "
          class="empty"
        >
          <h3>{{ t('guide_discover.empty.nobody_new_title') }}</h3>
          <p class="muted">
            {{ t('guide_discover.empty.nobody_new_body') }}
          </p>
          <RouterLink to="/matches" role="button">{{
            t('guide_discover.empty.view_matches')
          }}</RouterLink>
        </div>

        <div
          v-else-if="initialLoadDone && visitors.length === 0 && filtersStore.isPristine"
          class="empty"
        >
          <h3>{{ t('guide_discover.empty.no_visitors_title') }}</h3>
          <p class="muted">{{ t('guide_discover.empty.no_visitors_body') }}</p>
        </div>

        <div v-else class="empty">
          <h3>{{ t('guide_discover.empty.none_left_title') }}</h3>
          <p class="muted">
            {{ t('guide_discover.empty.none_left_body') }}
          </p>
          <button type="button" class="soft-btn" @click="filtersStore.reset()">
            {{ t('guide_discover.empty.clear_filters') }}
          </button>
        </div>
      </PanelCard>

      <aside class="rail">
        <!-- Matches and trips, as on the traveller's deck. -->
        <PanelCard :title="t('discover.rail.title')">
          <MatchesRail ref="matchesRail" />
        </PanelCard>
      </aside>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.filters-disclosure {
  // "Filters", then "clear ×" (while any are set) just before the chevron.
  summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;

    // The title takes the room, so "clear" and the chevron sit at the end.
    strong {
      flex: 1;
    }
  }

  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: #fff;
  padding: 0.85rem 1rem;
  margin-bottom: 0;

  p {
    margin: 0.25rem 0 0.75rem;
  }

  @include bp.lg {
    summary {
      list-style: none;
      cursor: default;
      pointer-events: none;

      &::-webkit-details-marker {
        display: none;
      }
    }
  }
}

.empty {
  text-align: center;
  padding: 2rem 1rem;

  h3 {
    margin-bottom: 0.5rem;
  }

  button {
    width: auto;
  }
}

// The filters bar sits right under the menu, as on the traveller's deck.
.section.shell {
  padding-top: 1.25rem;

  @include bp.lg {
    padding-top: 1.5rem;
  }
}

.deck-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.25rem 1rem;
  flex-wrap: wrap;

  span {
    text-align: end;
  }
}
</style>
