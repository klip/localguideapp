<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import DecisionRow from '@/components/DecisionRow.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import ReviewsSection from '@/components/ReviewsSection.vue'
import TagList from '@/components/TagList.vue'
import { ApiError, useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useCategoriesStore } from '@/stores/categories'
import { toGuideCard } from '@/stores/guides'
import { useShortlistStore } from '@/stores/shortlist'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'
import { reportApiError } from '@/utils/reportApiError'
import { formatRating } from '@/utils/reviews'
import type { Guide } from '@/types'

/**
 * The destination of the "Full profile →" link on a discovery card. The
 * prototype never draws this screen, so it reuses the card's own vocabulary at
 * full width rather than inventing new layout language.
 */
const props = defineProps<{ id: string }>()

const api = useApi()
const auth = useAuthStore()
const shortlist = useShortlistStore()
const { canPick } = storeToRefs(shortlist)

const categoriesStore = useCategoriesStore()

/**
 * Loaded on its own through `api.publicProfile()` — one guide by id, with no
 * deck rules in the way (decided-on or not, it resolves) and no contact
 * details. It used to fetch the whole guide list with `includeDecided` just
 * to `find()` one entry in it.
 */
const guide = ref<Guide | null>(null)
const loading = ref(true)

async function loadGuide() {
  loading.value = true
  guide.value = null
  try {
    const profile = await api.publicProfile(Number(props.id))
    // A guest's id under /guides/ is "not found", not a guide page for them.
    guide.value = profile.role === 'guide' ? toGuideCard(profile) : null
  } catch (err) {
    // A 400 here is "Profile not found." — the not-found panel says so already.
    if (!(err instanceof ApiError && err.status === 400)) reportApiError(err, 'Could not load this guide.')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  void loadGuide()
  categoriesStore.load()
})
watch(() => props.id, loadGuide)

const isPicked = computed(() => shortlist.isPicked(props.id))

/**
 * Same contract as DiscoverPage.vue's accept()/reject(): the local shortlist
 * gates capacity and drives the UI, and the server copy is recorded
 * best-effort behind it. Without this a decision made here vanished on the
 * next reload. Signed-out viewers can still browse this page, so nothing is
 * sent without a session (it would only 401).
 */
async function persistDecision(guideId: string, decision: 'interested' | 'pass') {
  if (!auth.isAuthenticated) return
  try {
    await api.decide({ targetId: Number(guideId), decision })
  } catch (err) {
    reportApiError(err, 'Could not record your decision.')
  }
}

function accept(id: string) {
  shortlist.pick(id)
  // pick() no-ops when the pack is spent; don't persist what it didn't record.
  if (shortlist.isPicked(id)) void persistDecision(id, 'interested')
}

function reject(id: string) {
  shortlist.pass(id)
  void persistDecision(id, 'pass')
}
</script>

<template>
  <section class="section shell">
    <p class="tiny">
      <RouterLink to="/discover">← Back to discovery</RouterLink>
    </p>

    <PanelCard v-if="loading" title="Loading…" />

    <template v-else-if="guide">
      <div class="profile-layout">
        <div
          class="hero-photo"
          :style="{ backgroundImage: `var(--rg-photo-scrim), ${guide.photo}` }"
        >
          <div class="hero-copy">
            <strong class="hero-name">{{ guide.name }}, {{ guide.age }}</strong>
            <span v-if="guide.reviewCount > 0">{{ formatRating(guide.ratingAverage, guide.reviewCount) }}</span>
          </div>
        </div>

        <PanelCard :title="guide.priceLabel" :subtitle="guide.priceNote">
          <p>{{ guide.bio }}</p>

          <!--
            One block per category this guide has filled in, labelled with the
            category's provider-side name where it has one ("Specialities"
            rather than "Interests"). Nothing is named here, so a category
            invented after this page was written still renders.
          -->
          <template v-for="(values, key) in guide.attributes" :key="key">
            <h3 v-if="values.length" class="sub">{{ categoriesStore.labelFor(key, true) }}</h3>
            <TagList v-if="values.length" :tags="values.map((value) => categoriesStore.optionLabelFor(key, value))" />
          </template>

          <template v-for="(spans, key) in guide.ranges" :key="`range-${key}`">
            <h3 v-if="spans.length" class="sub">{{ categoriesStore.labelFor(key, true) }}</h3>
            <TagList v-if="spans.length" :tags="spans.map((span) => formatRange(span, rangeFormatFor(key)))" />
          </template>

          <h3 class="sub">What’s included</h3>
          <p class="tiny muted">{{ guide.includes }}</p>

          <template #footer>
            <PillBadge v-if="isPicked">On your shortlist</PillBadge>
            <DecisionRow
              v-else
              :disabled="!canPick"
              @accept="accept(guide.id)"
              @reject="reject(guide.id)"
            />
            <p v-if="!isPicked && !canPick" class="tiny muted">
              No selections left in your pack. <RouterLink to="/unlock">Unlock +5 · £5</RouterLink>
            </p>
          </template>
        </PanelCard>
      </div>

      <ReviewsSection :user-id="Number(guide.id)" :subject-name="guide.name" />
    </template>

    <PanelCard v-else title="Guide not found">
      <p class="muted">That guide is no longer listed.</p>
      <RouterLink to="/discover" role="button">Back to discovery</RouterLink>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
@use 'breakpoints' as bp;

.profile-layout {
  display: grid;
  gap: 1.25rem;

  @include bp.lg {
    grid-template-columns: 1fr 1fr;
    align-items: start;
  }
}

.hero-photo {
  aspect-ratio: 4 / 4.35;
  border-radius: var(--rg-radius-card);
  background-size: cover;
  background-position: center;
  position: relative;
  box-shadow: var(--rg-shadow);
}

.hero-copy {
  position: absolute;
  inset: auto 1.25rem 1.25rem;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.hero-name {
  font-size: 2rem;
  line-height: 1.05;
}

.sub {
  font-size: 0.95rem;
  margin: 1rem 0 0.25rem;
}
</style>
