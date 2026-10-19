<script setup lang="ts">
import { computed } from 'vue'
import StandingBadges from './StandingBadges.vue'
import { t } from '@/i18n'
import type { Guide } from '@/types'
import { formatDistance } from '@/utils/distance'
import { formatRating } from '@/utils/reviews'

/**
 * One suggested guide for a trip stop on `/planner`: photo, name, headline,
 * rating and distance, the planner's one line on why (when Claude ranked
 * the trip), "Full profile" and **Interested** — which costs a pick, like
 * the deck (the page decides; this only emits).
 */
const props = defineProps<{
  guide: Guide
  reason: string | null
  distanceKm: number | null
  picked: boolean
  /** A pick is available — or free picks are on. */
  canPick: boolean
}>()

const emit = defineEmits<{ interested: [] }>()

const meta = computed(() =>
  [
    props.guide.reviewCount > 0
      ? `★ ${formatRating(props.guide.ratingAverage, props.guide.reviewCount)}`
      : null,
    props.distanceKm !== null ? formatDistance(props.distanceKm) : null,
    props.guide.priceLabel || null,
  ]
    .filter((part): part is string => !!part)
    .join(' · '),
)
</script>

<template>
  <article class="planner-card" :data-test="`planner-guide-${guide.id}`">
    <div
      class="photo"
      role="img"
      :aria-label="guide.name"
      :style="{ backgroundImage: `var(--rg-photo-scrim), ${guide.photo}` }"
    >
      <StandingBadges :standing="guide.standing" on-photo class="badges" />
    </div>
    <div class="body">
      <strong class="name">{{ guide.name }}</strong>
      <p v-if="guide.headline" class="tiny headline">{{ guide.headline }}</p>
      <p v-if="meta" class="tiny muted">{{ meta }}</p>
      <p v-if="reason" class="reason tiny" data-test="planner-reason">{{ reason }}</p>
      <div class="actions">
        <RouterLink :to="`/guides/${guide.id}`" class="tiny">{{
          t('planner.card.profile')
        }}</RouterLink>
        <button v-if="picked" type="button" class="secondary" disabled data-test="planner-picked">
          {{ t('planner.card.picked') }}
        </button>
        <button
          v-else
          type="button"
          :disabled="!canPick"
          data-test="planner-interested"
          @click="emit('interested')"
        >
          {{ t('planner.card.interested') }}
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped lang="scss">
.planner-card {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
  background: var(--pico-card-background-color, #fff);
}

.photo {
  position: relative;
  aspect-ratio: 4 / 3;
  background-size: cover;
  background-position: center;
}

.badges {
  position: absolute;
  left: 0.5rem;
  bottom: 0.5rem;
}

.body {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 0.3rem;
  padding: 0.75rem;

  p {
    margin: 0;
  }
}

.headline {
  font-weight: 600;
}

.reason {
  padding: 0.4rem 0.6rem;
  border-left: 3px solid var(--rg-orange-200);
  background: var(--rg-orange-50);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.4rem;

  button {
    width: auto;
    margin: 0;
    padding: 0.35rem 0.8rem;
    font-size: 0.9rem;
  }
}
</style>
