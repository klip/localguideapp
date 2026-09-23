<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import DecisionRow from '@/components/DecisionRow.vue'
import PanelCard from '@/components/PanelCard.vue'
import PillBadge from '@/components/PillBadge.vue'
import TagList from '@/components/TagList.vue'
import { useCategoriesStore } from '@/stores/categories'
import { useGuidesStore } from '@/stores/guides'
import { useShortlistStore } from '@/stores/shortlist'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'

/**
 * The destination of the "Full profile →" link on a discovery card. The
 * prototype never draws this screen, so it reuses the card's own vocabulary at
 * full width rather than inventing new layout language.
 */
const props = defineProps<{ id: string }>()

const shortlist = useShortlistStore()
const { canPick } = storeToRefs(shortlist)

const categoriesStore = useCategoriesStore()
const guidesStore = useGuidesStore()
// `includeDecided` so a guide reached from the shortlist (already picked, so
// filtered out of the deck) still resolves by id — see stores/guides.ts.
onMounted(() => {
  guidesStore.load(undefined, { includeDecided: true })
  categoriesStore.load()
})

const guide = computed(() => guidesStore.find(props.id))
const isPicked = computed(() => shortlist.isPicked(props.id))
</script>

<template>
  <section class="section shell">
    <p class="tiny">
      <RouterLink to="/discover">← Back to discovery</RouterLink>
    </p>

    <PanelCard v-if="guidesStore.loading" title="Loading…" />

    <template v-else-if="guide">
      <div class="profile-layout">
        <div
          class="hero-photo"
          :style="{ backgroundImage: `var(--rg-photo-scrim), ${guide.photo}` }"
        >
          <div class="hero-copy">
            <strong class="hero-name">{{ guide.name }}, {{ guide.age }}</strong>
            <span v-if="guide.rating > 0">★★★★★ {{ guide.rating }} · {{ guide.tours }} tours</span>
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
              @accept="shortlist.pick(guide.id)"
              @reject="shortlist.pass(guide.id)"
            />
            <p v-if="!isPicked && !canPick" class="tiny muted">
              No selections left in your pack. <RouterLink to="/unlock">Unlock +5 · £5</RouterLink>
            </p>
          </template>
        </PanelCard>
      </div>
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
