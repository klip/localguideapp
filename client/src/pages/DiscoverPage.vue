<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import FilterPanel from '@/components/FilterPanel.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import ProfileCard from '@/components/ProfileCard.vue'
import SelectionCounter from '@/components/SelectionCounter.vue'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { useGuidesStore } from '@/stores/guides'
import { PACK_SIZE, useShortlistStore } from '@/stores/shortlist'
import { debounce } from '@/utils/debounce'
import { reportApiError } from '@/utils/reportApiError'
import type { DiscoveryFilters } from '@/types'

const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const shortlist = useShortlistStore()
const guidesStore = useGuidesStore()

const { filters } = storeToRefs(filtersStore)
const { hasAccess, picks, capacity, selectionsLeft, canPick } = storeToRefs(shortlist)
const { items: guides, loading: guidesLoading } = storeToRefs(guidesStore)

/**
 * Filtering happens in SQL now (`Users::searchByRole()`), not in a JS
 * `.filter()` over an already-fetched list — so a filter change means a
 * refetch, not a recompute. Debounced because a range category's sliders
 * fire on every tick of a drag.
 */
const initialLoadDone = ref(false)
const debouncedLoad = debounce((next: DiscoveryFilters) => guidesStore.load(next), 350)

onMounted(async () => {
  // This page (and its pack/paywall box) is visitor-only — a signed-in
  // guide never pays or browses other guides, so send them straight to
  // their own deck instead of flashing this page first. Covers every way
  // a guide could land here (a stale bookmark, browser back/forward), not
  // just the nav link/post-login redirect (see AppHeader.vue/LoginPage.vue).
  if (auth.user?.role === 'guide') {
    router.replace('/guide/discover')
    return
  }

  await guidesStore.load(filters.value)
  initialLoadDone.value = true
  // Labels for the card's tag chips, and the filter bar's own category list.
  categoriesStore.load()
})

watch(filters, (next) => debouncedLoad(next), { deep: true })

/**
 * Undecided guides from the server's already-filtered list; the head of
 * the list is the card on top.
 */
const deck = computed(() => guides.value.filter((guide) => !shortlist.isDecided(guide.id)))
const current = computed(() => deck.value[0])

/** Real accounts don't have a rating/tour count yet — omit the line rather than show "★★★★★ 0 · 0 tours". */
const currentMeta = computed(() =>
  current.value && current.value.rating > 0 ? `★★★★★ ${current.value.rating} · ${current.value.tours} tours` : undefined,
)

const pickedGuides = computed(() => picks.value.map(guidesStore.find).filter((guide) => !!guide))

/**
 * A pick restored after a reload (see `App.vue`'s boot fetch) names a guide
 * the deck fetch deliberately leaves out, since the server hides cards this
 * visitor has already decided on — so the rail could show "1 / 5 selected"
 * directly above "Nothing picked yet". Pull those guides into the store's id
 * cache when, and only when, one fails to resolve: during normal swiping the
 * guide is already cached, so this costs one request after a reload rather
 * than on every mount.
 */
watch(
  picks,
  (ids) => {
    if (ids.some((id) => !guidesStore.find(id))) void guidesStore.hydrateDecided()
  },
  { immediate: true },
)

const counterHeadline = computed(() =>
  selectionsLeft.value > 0
    ? `${selectionsLeft.value} selection${selectionsLeft.value === 1 ? '' : 's'} left`
    : 'No selections left',
)

/**
 * Persists the decision server-side (see `Selections::decide()`) in the
 * background, best-effort — `shortlist`'s local pick/pass state is what the
 * deck itself reads from and is what already gates capacity, so a failure
 * here doesn't need to block or undo it, just surface a toast. This is what
 * makes a real mutual match with a guide possible at all — see
 * GuideDiscoverPage.vue's `decide()`, which is the side that currently
 * shows the "it's a match" notification.
 */
async function persistDecision(guideId: string, decision: 'interested' | 'pass') {
  try {
    await api.decide({ targetId: Number(guideId), decision })
  } catch (err) {
    reportApiError(err, 'Could not record your decision.')
  }
}

function accept(id: string) {
  shortlist.pick(id)
  // pick() no-ops (and isPicked stays false) when the pack is already
  // spent — the button is disabled in that case (see `:disabled="!canPick"`
  // below), but guard here too rather than persist a decision the local
  // state didn't actually record.
  if (shortlist.isPicked(id)) void persistDecision(id, 'interested')
}

function reject(id: string) {
  shortlist.pass(id)
  void persistDecision(id, 'pass')
}
</script>

<template>
  <section class="section shell">
    <div class="discovery-layout">
      <!-- Filters: a side rail on desktop, a collapsed disclosure on mobile. -->
      <aside class="rail">
        <details class="filters-disclosure">
          <summary><strong>Filters</strong></summary>
          <p class="tiny muted">Keep them light and optional.</p>
          <FilterPanel />
        </details>
      </aside>
      
        <PanelCard>
          <template #header>
            <div class="row-between">
              <div>
                <strong>Guides for you</strong>
                <div class="tiny muted">Friday · cruise day</div>
              </div>
              <PillBadge>{{ picks.length }} / {{ capacity }} picked</PillBadge>
            </div>
          </template>

          <!-- Paywall: every route stays reachable, the deck itself is what is gated. -->
          <template v-if="!hasAccess">
            <div class="empty">
              <h3>Unlock to start matching</h3>
              <p class="muted">
                A one-time £5 fee gives you {{ PACK_SIZE }} guide selections. Browse freely once
                unlocked.
              </p>
              <RouterLink to="/unlock" role="button">Unlock {{ PACK_SIZE }} picks · £5</RouterLink>
            </div>
          </template>

          <template v-else>
            <SelectionCounter
              :headline="counterHeadline"
              caption="before your current pack is used"
              :action-label="`Get +${PACK_SIZE}`"
              @action="router.push('/unlock')"
            />

            <ProfileCard
              v-if="current"
              :key="current.id"
              :name="current.name"
              :age="current.age"
              :photo="current.photo"
              :meta="currentMeta"
              :price-label="current.priceLabel"
              :price-note="current.priceNote"
              :bio="current.bio"
              :tags="categoriesStore.tagsFor(current.attributes)"
              :includes="current.includes"
              :profile-link="{ name: 'guide-profile', params: { id: current.id } }"
              :disabled="!canPick"
              @accept="accept(current.id)"
              @reject="reject(current.id)"
            />

            <div v-else-if="guidesLoading && !initialLoadDone" class="empty">
              <h3>Loading guides…</h3>
            </div>

            <!--
              Signed in, no filters, nothing back: the server also hides guides
              this visitor already decided on (see Users::searchByRole()), so
              "nobody has registered" would often be a lie. This copy is true
              either way — an empty system and a fully-worked-through deck both
              mean there's nobody new to show.
            -->
            <div
              v-else-if="initialLoadDone && guides.length === 0 && filtersStore.isPristine && auth.isAuthenticated"
              class="empty"
            >
              <h3>Nobody new right now</h3>
              <p class="muted">
                You’ve seen every guide available at the moment. Guides you’ve already decided on
                live under Matches.
              </p>
              <div class="empty-actions">
                <RouterLink to="/matches" role="button">View matches</RouterLink>
              </div>
            </div>

            <div v-else-if="initialLoadDone && guides.length === 0 && filtersStore.isPristine" class="empty">
              <h3>No guides registered yet</h3>
              <p class="muted">Check back soon — local guides are still signing up.</p>
            </div>

            <div v-else class="empty">
              <h3>That’s everyone for now</h3>
              <p class="muted">
                No more guides match these filters. Loosen them, or review the shortlist you built.
              </p>
              <div class="empty-actions">
                <button type="button" class="soft-btn" @click="filtersStore.reset()">
                  Clear filters
                </button>
                <RouterLink to="/shortlist" role="button">View shortlist</RouterLink>
                <button type="button" class="soft-btn" @click="shortlist.resetPassed()">
                  Take another look
                </button>
              </div>
            </div>

            <p v-if="!canPick && current" class="tiny muted">
              You’ve used every pick in this pack. Unlock {{ PACK_SIZE }} more to keep going.
            </p>
          </template>
        </PanelCard>
      
      <!-- Shortlist rail. -->
      <aside class="rail">
        <PanelCard title="Your shortlist">
          <SelectionCounter :headline="`${picks.length} / ${capacity}`" caption="selected" />

          <ul v-if="pickedGuides.length" class="picked">
            <li v-for="guide in pickedGuides" :key="guide.id">{{ guide.name }}</li>
          </ul>
          <p v-else class="tiny muted">Nothing picked yet.</p>

          <RouterLink to="/shortlist" role="button" class="soft-btn full">View shortlist</RouterLink>
          <RouterLink to="/unlock" role="button" class="full">
            Unlock +{{ PACK_SIZE }} · £5
          </RouterLink>
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

  // On desktop the rail is always expanded, so the summary is a plain heading.
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
}

.empty-actions {
  display: flex;
  gap: 0.6rem;
  justify-content: center;
  flex-wrap: wrap;

  a[role='button'],
  button {
    width: auto;
    margin-bottom: 0;
  }
}

.picked {
  padding-left: 1.1rem;
  margin-bottom: 0.85rem;

  li {
    margin-bottom: 0.2rem;
  }
}

a[role='button'] {
  margin-bottom: 0.5rem;
}
</style>
