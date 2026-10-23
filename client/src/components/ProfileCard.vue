<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import DecisionRow from './DecisionRow.vue'
import { t } from '@/i18n'
import TagList from './TagList.vue'
import StandingBadges from './StandingBadges.vue'
import type { Standing } from '@/types'

/**
 * The discovery card (docs/design/sketches/04/05). Deliberately
 * domain-agnostic props rather than a `Guide` or `Visitor` object, because
 * both decks render the same card with different emphasis: guides lead with
 * price, visitors lead with party and schedule.
 *
 * Wide (the desktop deck column) it's landscape — photo left, every fact and
 * both buttons right, all above the fold; narrow (phones) one wide photo
 * across the top. The photo and the name open the full profile — there's no
 * separate link. One photo for now: a gallery waits for multi-photo upload.
 */
withDefaults(
  defineProps<{
    name: string
    age: number
    /** CSS background value for the photo area. */
    photo: string
    /** Line under the name — rating for guides, party for visitors. */
    meta?: string
    /** A short note on the photo, e.g. "3 km away". */
    photoNote?: string
    priceLabel?: string
    priceNote?: string
    bio?: string
    tags?: string[]
    /** Prose appended after the tags, e.g. what the fee includes. */
    includes?: string
    profileLink?: RouteLocationRaw
    rejectLabel?: string
    acceptLabel?: string
    decided?: boolean
    disabled?: boolean
    /** Earned badges (verified, top rated, trips) — see `StandingBadges`. */
    standing?: Standing
  }>(),
  { tags: () => [], decided: false, disabled: false },
)

const emit = defineEmits<{
  reject: []
  accept: []
}>()
</script>

<template>
  <article class="profile-card" :class="{ 'profile-card--decided': decided }">
    <div class="card-grid">
      <component
        :is="profileLink ? 'RouterLink' : 'div'"
        class="profile-photo"
        :style="{ backgroundImage: photo }"
        v-bind="profileLink ? { to: profileLink, tabindex: -1, 'aria-hidden': 'true' } : {}"
        data-test="card-photo"
      >
        <span v-if="photoNote" class="photo-note">{{ photoNote }}</span>
        <!-- Level, name and rating over the photo's bottom edge, as on the profile page. -->
        <span class="photo-copy">
          <StandingBadges v-if="standing" :standing="standing" on-photo class="card-badges" />
          <strong class="photo-name">{{ name }}, {{ age }}</strong>
          <span v-if="meta" class="photo-meta">{{ meta }}</span>
        </span>
      </component>

      <div class="profile-body">
        <h2 class="card-name visually-hidden">{{ name }}, {{ age }}</h2>
        <RouterLink v-if="profileLink" :to="profileLink" class="card-profile-link visually-hidden" data-test="card-name"
          >{{ name }}, {{ age }}</RouterLink
        >
        <div v-if="priceLabel" class="card-price">
          <strong>{{ priceLabel }}</strong>
          <span v-if="priceNote">{{ priceNote }}</span>
        </div>

        <p v-if="bio" class="card-bio">{{ bio }}</p>

        <TagList :tags="tags" />

        <p v-if="includes" class="card-includes">
          <strong>{{ t('common.profile_card.includes') }}</strong> {{ includes }}
        </p>

        <slot />

        <DecisionRow
          class="card-decision"
          :reject-label="rejectLabel"
          :accept-label="acceptLabel"
          :disabled="disabled"
          @reject="emit('reject')"
          @accept="emit('accept')"
        />
      </div>
    </div>
  </article>
</template>

<style scoped lang="scss">
.profile-card {
  container-type: inline-size;
  margin: 0;
  // Pico pads every <article>; the photo runs to the card's edges here.
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-card);
  background: #fff;
  box-shadow: var(--rg-shadow);
  animation: rg-rise 0.28s ease;
}

.profile-card--decided {
  opacity: 0.55;
}

.card-grid {
  display: grid;
}

.profile-photo {
  position: relative;
  display: block;
  aspect-ratio: 4 / 3.4;
  background-color: var(--rg-sunken);
  background-size: cover;
  background-position: center 30%;
}

.profile-photo::after {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--rg-photo-scrim);
  pointer-events: none;
}

.photo-copy {
  position: absolute;
  inset: auto 1rem 1rem;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  color: #fff;
}

.photo-name {
  font-family: var(--rg-font-display);
  font-size: 1.625rem;
  line-height: 1.1;
}

.photo-meta {
  font-size: 0.875rem;
}

.card-profile-link {
  font-size: 0.875rem;
  font-weight: 600;
}

.photo-note {
  position: absolute;
  inset-inline-start: 0.75rem;
  top: 0.75rem;
  padding: 0.2rem 0.5rem;
  border-radius: 0.375rem;
  background: rgba(42, 31, 23, 0.72);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 600;
}

.profile-body {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;

  p {
    margin: 0;
  }
}

.title-row {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.card-name {
  margin: 0;
  font-size: 1.375rem;

  a {
    color: var(--rg-ink);
    text-decoration: underline;
    text-decoration-color: var(--rg-line-strong);
    text-decoration-thickness: 2px;
    text-underline-offset: 0.2em;

    &:hover,
    &:focus-visible {
      text-decoration-color: var(--rg-orange-500);
    }
  }
}

.card-meta {
  color: var(--rg-muted);
  font-size: 0.875rem;
}

.card-price {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 0.5rem;
  padding: 0.5rem 0.75rem;
  border-radius: var(--rg-radius-control);
  background: var(--rg-sunken);

  strong {
    font-family: var(--rg-font-display);
    font-size: 1.125rem;
  }

  span {
    color: var(--rg-muted);
    font-size: 0.8125rem;
  }
}

.card-bio {
  color: var(--rg-ink);
  font-size: 0.9375rem;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.card-includes {
  color: var(--rg-muted);
  font-size: 0.8125rem;
}

.card-decision {
  margin-top: 0.25rem;
}

// Landscape: photo on the left at full card height, facts on the right
// with the buttons pinned to the bottom.
@container (min-width: 600px) {
  .card-grid {
    grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
    min-height: 34rem;
  }

  .profile-photo {
    aspect-ratio: auto;
    min-height: 100%;
  }

  .profile-body {
    padding: 1.5rem;
  }

  .card-name {
    font-size: 1.625rem;
  }

  .card-bio {
    -webkit-line-clamp: 5;
  }

  .card-decision {
    margin-top: auto;
  }
}
</style>
