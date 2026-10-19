<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import ActiveFilters from '@/components/ActiveFilters.vue'
import LocationPrompt from '@/components/LocationPrompt.vue'
import FilterPanel from '@/components/FilterPanel.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import PriceBox from '@/components/PriceBox.vue'
import ProfileCard from '@/components/ProfileCard.vue'
import ProtectedNotice from '@/components/ProtectedNotice.vue'
import SelectionCounter from '@/components/SelectionCounter.vue'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'
import { t, tn } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useDeckLocation } from '@/composables/useDeckLocation'
import { useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { useGuidesStore } from '@/stores/guides'
import { useMatchesStore } from '@/stores/matches'
import { debounce } from '@/utils/debounce'
import { addPendingReject } from '@/utils/pendingRejects'
import { formatRating } from '@/utils/reviews'
import { formatDistance } from '@/utils/distance'
import { anchorTo } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'
import type { DiscoveryFilters } from '@/types'

const router = useRouter()
const api = useApi()
const auth = useAuthStore()
// The search point in the URL, and (travellers) on the server for guides to see.
useDeckLocation()
const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const matchesStore = useMatchesStore()
const guidesStore = useGuidesStore()

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
const {
  hasAccess,
  picks,
  picksUsed,
  capacity,
  selectionsLeft,
  canPick,
  packSize,
  freePicksActive,
  freePicksUntil,
} = storeToRefs(matchesStore)
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
  if (auth.activeRole === 'guide') {
    router.replace('/guide/discover')
    return
  }

  // The pack check runs alongside the first deck fetch rather than relying
  // on App.vue's boot sync, which hasn't finished yet after a plain refresh —
  // without its answer the payment modal would flash open for a paid-up guest.
  await Promise.all([guidesStore.load(filters.value), checkPack()])
  initialLoadDone.value = true
  // Labels for the card's tag chips, and the filter bar's own category list.
  categoriesStore.load()
})

watch(filters, (next) => debouncedLoad(next), { deep: true })

/**
 * Undecided guides from the server's already-filtered list; the head of
 * the list is the card on top.
 */
const deck = computed(() => guides.value.filter((guide) => !matchesStore.isDecided(guide.id)))
const current = computed(() => deck.value[0])

/** From real reviews — omitted until a guide has at least one, rather than showing "★ 0 · 0 reviews". */
// "★ 4.8 (12) · 3 km away" — each part only when there is one.
const currentMeta = computed(() => {
  const guide = current.value
  if (!guide) return undefined
  const parts = [
    guide.reviewCount > 0 ? formatRating(guide.ratingAverage, guide.reviewCount) : null,
    guide.distanceKm !== null ? formatDistance(guide.distanceKm) : null,
  ].filter((part): part is string => part !== null)
  return parts.length ? parts.join(' · ') : undefined
})

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

const freePicksUntilLabel = new Intl.DateTimeFormat(undefined, {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
})
const counterHeadline = computed(() => {
  if (freePicksActive.value && freePicksUntil.value) {
    return t('discover.counter.free_picks_until', {
      date: freePicksUntilLabel.format(new Date(freePicksUntil.value)),
    })
  }
  return selectionsLeft.value > 0
    ? tn('discover.counter.selections_left', selectionsLeft.value)
    : t('discover.counter.no_selections_left')
})

/**
 * Persists the decision server-side (see `Selections::decide()`) in the
 * background, best-effort — `matchesStore`'s local pick/pass state is what the
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
    reportApiError(err, t('discover.toast.decision_failed'))
  }
}

/**
 * The deck is the landing page, so it's open to everyone: a signed-out
 * visitor can browse and reject freely (locally only — there's no one to
 * record it against), and "Interested" is where they're asked to sign up.
 * A signed-in guest without a pack is shown the payment modal straight
 * away (and again on "Interested"); everyone else just swipes.
 */
const isGuest = computed(() => auth.activeRole === 'guest')
const authPromptOpen = ref(false)
const payOpen = ref(false)
const paying = ref(false)
const payPanelRef = ref<HTMLElement | null>(null)
/** The card whose "Interested" opened the payment modal — picked once paid. */
const pendingAcceptId = ref<string | null>(null)

/** Out of picks in a pack they already bought — the card's buttons go inert, as before. */
const cardDisabled = computed(() => isGuest.value && hasAccess.value && !canPick.value)

async function checkPack() {
  if (!isGuest.value) return
  try {
    matchesStore.applyPaymentState(await api.paymentState())
  } catch (err) {
    reportApiError(err, t('discover.toast.pack_check_failed'))
    return
  }
  if (!hasAccess.value) payOpen.value = true
}

// Stands in for the payment provider callback — calling api.unlockPack() *is*
// the payment (see api/classes/Payments.php, no real gateway wired up yet).
async function payForPack() {
  if (paying.value) return
  paying.value = true
  try {
    matchesStore.applyPaymentState(await api.unlockPack())
    payOpen.value = false
    const pending = pendingAcceptId.value
    pendingAcceptId.value = null
    if (pending && !matchesStore.isDecided(pending)) accept(pending)
  } catch (err) {
    reportApiError(err, t('discover.toast.payment_failed'), anchorTo('element', payPanelRef.value))
  } finally {
    paying.value = false
  }
}

function onPayClose() {
  payOpen.value = false
  pendingAcceptId.value = null
}

function accept(id: string) {
  if (!auth.isAuthenticated) {
    authPromptOpen.value = true
    return
  }
  if (isGuest.value && !hasAccess.value) {
    pendingAcceptId.value = id
    payOpen.value = true
    return
  }
  matchesStore.pick(id)
  // pick() no-ops (and isPicked stays false) when the pack is already
  // spent — the button is disabled in that case (see `:disabled="!canPick"`
  // below), but guard here too rather than persist a decision the local
  // state didn't actually record.
  if (matchesStore.isPicked(id)) void persistDecision(id, 'interested')
}

function reject(id: string) {
  matchesStore.pass(id)
  // Signed out: kept in this browser and sent once they sign in (App.vue).
  if (auth.isAuthenticated) void persistDecision(id, 'pass')
  else addPendingReject(id)
}
</script>

<template>
  <section class="section shell">
    <div class="discovery-layout">
      <!-- Filters: a side rail on desktop, a collapsed disclosure on mobile. -->
      <aside class="rail">
        <details class="filters-disclosure" :open="filtersOpen" @toggle="onFiltersToggle">
          <summary>
            <strong>{{ t('discover.filters.title') }}</strong>
          </summary>
          <p class="tiny muted">{{ t('discover.filters.hint') }}</p>
          <FilterPanel target-role="guide" />
        </details>
      </aside>

      <PanelCard>
        <template #header>
          <div class="row-between">
            <div>
              <strong>{{ t('discover.deck.title') }}</strong>
              <div class="tiny muted">
                {{
                  filters.near
                    ? t('discover.deck.subtitle_near', { place: filters.near.label })
                    : t('discover.deck.subtitle')
                }}
              </div>
            </div>
            <PillBadge v-if="freePicksActive" data-test="free-picks">{{
              t('discover.deck.free_picks')
            }}</PillBadge>
            <PillBadge v-else-if="hasAccess">{{
              t('discover.deck.picked', { picked: picksUsed, capacity })
            }}</PillBadge>
          </div>
        </template>

        <!--
          The deck is open to everyone; "Interested" is what's gated — by an
          account for a signed-out visitor, by a pack for a guest without one.
        -->
        <SelectionCounter
          v-if="!auth.isAuthenticated"
          :headline="t('discover.counter.browse_freely')"
          :caption="t('discover.counter.browse_caption')"
          :action-label="t('discover.counter.sign_up')"
          @action="router.push('/register')"
        />
        <SelectionCounter
          v-else-if="isGuest && !hasAccess"
          :headline="t('discover.counter.unlock_headline', { count: packSize })"
          :caption="t('discover.counter.unlock_caption')"
          :action-label="t('discover.counter.unlock')"
          @action="payOpen = true"
        />
        <SelectionCounter
          v-else
          :headline="counterHeadline"
          :caption="
            freePicksActive
              ? t('discover.counter.free_picks_caption')
              : t('discover.counter.pack_caption')
          "
          :action-label="t('discover.counter.get_more', { count: packSize })"
          @action="router.push('/unlock')"
        />

        <LocationPrompt />
        <ActiveFilters />

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
          :standing="current.standing"
          :disabled="cardDisabled"
          @accept="accept(current.id)"
          @reject="reject(current.id)"
        />

        <div v-else-if="guidesLoading && !initialLoadDone" class="empty">
          <h3>{{ t('discover.empty.loading') }}</h3>
        </div>

        <!--
              Signed in, no filters, nothing back: the server also hides guides
              this visitor already decided on (see Users::searchByRole()), so
              "nobody has registered" would often be a lie. This copy is true
              either way — an empty system and a fully-worked-through deck both
              mean there's nobody new to show.
            -->
        <div
          v-else-if="
            initialLoadDone &&
            guides.length === 0 &&
            filtersStore.isPristine &&
            auth.isAuthenticated
          "
          class="empty"
        >
          <h3>{{ t('discover.empty.nobody_new_title') }}</h3>
          <p class="muted">
            {{ t('discover.empty.nobody_new_body') }}
          </p>
          <div class="empty-actions">
            <RouterLink to="/matches" role="button">{{
              t('discover.empty.view_matches')
            }}</RouterLink>
          </div>
        </div>

        <div
          v-else-if="initialLoadDone && guides.length === 0 && filtersStore.isPristine"
          class="empty"
        >
          <h3>{{ t('discover.empty.no_guides_title') }}</h3>
          <p class="muted">{{ t('discover.empty.no_guides_body') }}</p>
        </div>

        <div v-else class="empty">
          <h3>{{ t('discover.empty.everyone_title') }}</h3>
          <p class="muted">
            {{ t('discover.empty.everyone_body') }}
          </p>
          <div class="empty-actions">
            <button type="button" class="soft-btn" @click="filtersStore.reset()">
              {{ t('discover.empty.clear_filters') }}
            </button>
            <RouterLink to="/matches" role="button">{{
              t('discover.empty.view_matches')
            }}</RouterLink>
            <button type="button" class="soft-btn" @click="matchesStore.resetPassed()">
              {{ t('discover.empty.take_another_look') }}
            </button>
          </div>
        </div>

        <p v-if="cardDisabled && current" class="tiny muted">
          {{ t('discover.deck.pack_used', { count: packSize }) }}
        </p>
      </PanelCard>

      <!-- Matches rail. -->
      <aside class="rail">
        <PanelCard v-if="!auth.isAuthenticated" :title="t('discover.rail.title')">
          <p class="tiny muted">
            {{ t('discover.rail.signed_out_body', { count: packSize }) }}
          </p>
          <RouterLink to="/register" role="button" class="full">{{
            t('discover.rail.create_account')
          }}</RouterLink>
          <RouterLink to="/login" role="button" class="soft-btn full">{{
            t('discover.rail.log_in')
          }}</RouterLink>
        </PanelCard>
        <PanelCard v-else :title="t('discover.rail.title')">
          <SelectionCounter
            :headline="t('discover.rail.count', { picked: picksUsed, capacity })"
            :caption="t('discover.rail.selected')"
          />

          <ul v-if="pickedGuides.length" class="picked">
            <li v-for="guide in pickedGuides" :key="guide.id">{{ guide.name }}</li>
          </ul>
          <p v-else class="tiny muted">{{ t('discover.rail.nothing_picked') }}</p>

          <RouterLink to="/matches" role="button" class="soft-btn full">{{
            t('discover.rail.view_matches')
          }}</RouterLink>
          <RouterLink to="/unlock" role="button" class="full">
            {{ t('discover.rail.unlock_more', { count: packSize }) }}
          </RouterLink>
        </PanelCard>
      </aside>
    </div>

    <ModalDialog
      :open="authPromptOpen"
      :title="t('discover.auth_modal.title')"
      @close="authPromptOpen = false"
    >
      <p class="muted">
        {{ t('discover.auth_modal.body', { count: packSize }) }}
      </p>
      <RouterLink to="/register" role="button" class="full">{{
        t('discover.auth_modal.create_account')
      }}</RouterLink>
      <RouterLink to="/login" role="button" class="soft-btn full">{{
        t('discover.auth_modal.log_in')
      }}</RouterLink>
    </ModalDialog>

    <ModalDialog
      :open="payOpen"
      :title="t('discover.pay_modal.title')"
      :close-label="t('discover.pay_modal.not_now')"
      @close="onPayClose"
    >
      <div ref="payPanelRef">
        <p class="muted">
          {{ t('discover.pay_modal.body', { count: packSize }) }}
        </p>
        <PriceBox compact>
          <div class="price-head">
            <div>
              <span class="tiny muted">{{ t('discover.pay_modal.one_time_fee') }}</span>
              <div class="price">£5</div>
            </div>
            <PillBadge>{{ t('discover.pay_modal.picks', { count: packSize }) }}</PillBadge>
          </div>
        </PriceBox>
        <ProtectedNotice kind="pack" />
        <button
          type="button"
          class="full pay"
          :aria-busy="paying"
          :disabled="paying"
          @click="payForPack"
        >
          {{ paying ? t('discover.pay_modal.paying') : t('discover.pay_modal.pay') }}
        </button>
        <p class="tiny muted">
          {{ t('discover.pay_modal.top_up_note', { count: packSize }) }}
        </p>
      </div>
    </ModalDialog>
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

.price-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
}

.pay {
  margin: 0.75rem 0;
}
</style>
