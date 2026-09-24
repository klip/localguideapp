<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import FilterPanel from '@/components/FilterPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import ProfileCard from '@/components/ProfileCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { useMessagesStore } from '@/stores/messages'
import { useVisitorsStore } from '@/stores/visitors'
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
const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const { filters } = storeToRefs(filtersStore)

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

const interested = ref<string[]>([])
const passed = ref<string[]>([])

const deck = computed(() =>
  visitors.value.filter(
    (visitor) => !interested.value.includes(visitor.id) && !passed.value.includes(visitor.id),
  ),
)
const current = computed(() => deck.value[0])

const interestedVisitors = computed(() =>
  interested.value.map(visitorsStore.find).filter((visitor) => !!visitor),
)

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
    reportApiError(err, 'Could not record your decision.')
    return null
  }
}

async function accept(id: string) {
  const visitor = visitorsStore.find(id)
  interested.value.push(id)

  const row = await decide(id, 'interested')
  if (row?.active === 1) {
    messages.success(
      `You matched with ${visitor?.name ?? 'this visitor'}! Once they've paid your fee (straight away, if you don't charge one), you'll both see each other's contact details on your Matches page.`,
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
    <SectionTitle
      eyebrow="Screen 04"
      heading="Guide-side matching"
      lead="The same interaction model as visitor discovery, but the card emphasises party needs, schedule and requested experience — and there's no selection pack to spend."
    />

    <div class="discovery-layout">
      <aside class="rail">
        <details class="filters-disclosure">
          <summary><strong>Availability &amp; fit</strong></summary>
          <p class="tiny muted">Match visitors against how you actually work.</p>
          <FilterPanel compact />
        </details>
      </aside>

      <PanelCard>
        <template #header>
          <div class="row-between">
            <div>
              <strong>Visitors</strong>
              <div class="tiny muted">Matching your availability</div>
            </div>
            <PillBadge>{{ interested.length }} interested</PillBadge>
          </div>
        </template>

        <ProfileCard
          v-if="current"
          :key="current.id"
          :name="current.name"
          :age="current.age"
          :photo="current.photo"
          :meta="current.party"
          :bio="current.bio"
          :tags="categoriesStore.tagsFor(current.attributes)"
          :profile-link="{ name: 'visitor-profile', params: { id: current.id } }"
          @accept="accept(current.id)"
          @reject="reject(current.id)"
        />

        <div v-else-if="visitorsLoading && !initialLoadDone" class="empty">
          <h3>Loading visitors…</h3>
        </div>

        <!-- See the matching comment in DiscoverPage.vue: decided visitors are
             filtered out server-side, so an empty deck usually isn't an empty
             system. -->
        <div
          v-else-if="initialLoadDone && visitors.length === 0 && filtersStore.isPristine && auth.isAuthenticated"
          class="empty"
        >
          <h3>Nobody new right now</h3>
          <p class="muted">
            You’ve answered every visitor available at the moment. The ones you kept live under
            Matches.
          </p>
          <RouterLink to="/matches" role="button">View matches</RouterLink>
        </div>

        <div v-else-if="initialLoadDone && visitors.length === 0 && filtersStore.isPristine" class="empty">
          <h3>No visitors registered yet</h3>
          <p class="muted">Check back soon — visitors are still signing up.</p>
        </div>

        <div v-else class="empty">
          <h3>No visitors left in this view</h3>
          <p class="muted">
            Widen your availability or clear the filters to see more arriving parties.
          </p>
          <button type="button" class="soft-btn" @click="filtersStore.reset()">
            Clear filters
          </button>
        </div>
      </PanelCard>

      <aside class="rail">
        <PanelCard title="Interested in" subtitle="Mutual interest reveals contact details.">
          <ul v-if="interestedVisitors.length" class="picked">
            <li v-for="visitor in interestedVisitors" :key="visitor.id">
              {{ visitor.name }}
              <span class="tiny muted">· {{ visitor.durationHours }}h</span>
            </li>
          </ul>
          <p v-else class="tiny muted">No visitors saved yet.</p>
        </PanelCard>
      </aside>
    </div>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.filters-disclosure {
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  background: rgba(255, 255, 255, 0.75);
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

.picked {
  padding-left: 1.1rem;
  margin-bottom: 0;

  li {
    margin-bottom: 0.2rem;
  }
}
</style>
