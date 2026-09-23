<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import DecisionRow from './DecisionRow.vue'
import TagList from './TagList.vue'

/**
 * The swipeable card. Deliberately domain-agnostic props rather than a `Guide`
 * or `Visitor` object, because both decks render the same card with different
 * emphasis: guides lead with price, visitors lead with party and schedule.
 */
withDefaults(
  defineProps<{
    name: string
    age: number
    /** CSS background value for the photo area. */
    photo: string
    /** Line under the name — rating for guides, party for visitors. */
    meta?: string
    priceLabel?: string
    priceNote?: string
    bio?: string
    tags?: string[]
    /** Prose appended after the tags, e.g. what the fee includes. */
    includes?: string
    profileLink?: RouteLocationRaw
    rejectLabel?: string
    acceptLabel?: string
    /** Renders the photo wide instead of portrait, for desktop rails. */
    wide?: boolean
    decided?: boolean
    disabled?: boolean
  }>(),
  { tags: () => [], wide: false, decided: false, disabled: false },
)

const emit = defineEmits<{
  reject: []
  accept: []
}>()
</script>

<template>
  <article class="profile-card" :class="{ 'profile-card--decided': decided }">
    <div class="profile-photo" :class="{ 'profile-photo--wide': wide }" :style="{ backgroundImage: `var(--rg-photo-scrim), ${photo}` }">
      <div class="photo-copy">
        <strong class="photo-name">{{ name }}, {{ age }}</strong>
        <span v-if="meta">{{ meta }}</span>
      </div>
    </div>

    <div class="profile-body">
      <div v-if="priceLabel || profileLink" class="profile-meta">
        <div v-if="priceLabel">
          <strong>{{ priceLabel }}</strong>
          <div v-if="priceNote" class="tiny muted">{{ priceNote }}</div>
        </div>
        <RouterLink v-if="profileLink" class="tiny" :to="profileLink">Full profile →</RouterLink>
      </div>

      <p v-if="bio">{{ bio }}</p>

      <TagList :tags="tags" />

      <p v-if="includes" class="tiny muted"><strong>Includes:</strong> {{ includes }}</p>

      <slot />

      <DecisionRow
        :reject-label="rejectLabel"
        :accept-label="acceptLabel"
        :disabled="disabled"
        @reject="emit('reject')"
        @accept="emit('accept')"
      />
    </div>
  </article>
</template>

<style scoped lang="scss">
.profile-card {
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel);
  overflow: hidden;
  background: #fff;
  margin-bottom: 0;
}

.profile-card--decided {
  opacity: 0.55;
}

.profile-photo {
  aspect-ratio: 4 / 4.35;
  background-size: cover;
  background-position: center;
  position: relative;
}

.profile-photo--wide {
  aspect-ratio: 16 / 10;
}

.photo-copy {
  position: absolute;
  inset: auto 1rem 1rem;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.photo-name {
  font-size: 1.65rem;
  line-height: 1.1;
}

.profile-body {
  padding: 1rem;

  p {
    margin-bottom: 0.5rem;
  }
}

.profile-meta {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 0.5rem;
}
</style>
