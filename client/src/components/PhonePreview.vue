<script setup lang="ts">
import type { Guide } from '@/types'

/** Hero device mock showing a single card in the match rhythm. */
defineProps<{
  guide: Guide
  progressLabel: string
}>()
</script>

<template>
  <div class="phone" aria-label="Mobile match preview" role="img">
    <div class="phone-screen">
      <div class="phone-head">
        <strong>Discover</strong>
        <span class="tiny muted">{{ progressLabel }}</span>
      </div>

      <div class="mini-card">
        <div class="mini-img" :style="{ backgroundImage: `var(--rg-photo-scrim), ${guide.photo}` }">
          <div class="overlay">
            <strong class="mini-name">{{ guide.name }}, {{ guide.age }}</strong>
            <span>{{ guide.headline }}</span>
          </div>
        </div>

        <div class="mini-body">
          <strong>{{ guide.priceLabel }}</strong>
          <p class="tiny muted">{{ guide.includes }}</p>
        </div>

        <div class="mini-actions">
          <span class="reject">Pass</span>
          <span class="accept">Interested</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.phone {
  width: min(100%, 370px);
  margin-inline: auto;
  background: #171310;
  border-radius: 2rem;
  padding: 0.65rem;
  box-shadow: var(--rg-shadow);
}

.phone-screen {
  background: var(--rg-surface);
  border-radius: 1.55rem;
  overflow: hidden;
}

.phone-head {
  padding: 1rem 1rem 0.6rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.mini-card {
  margin: 0.5rem;
  border-radius: var(--rg-radius-panel);
  overflow: hidden;
  background: #fff;
  border: 1px solid var(--rg-line);
}

.mini-img {
  min-height: 320px;
  background-size: cover;
  background-position: center;
  position: relative;
}

.overlay {
  position: absolute;
  left: 1rem;
  right: 1rem;
  bottom: 1rem;
  color: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.mini-name {
  font-size: 1.5rem;
  line-height: 1.1;
}

.mini-body {
  padding: 0.85rem;

  p {
    margin: 0.25rem 0 0;
  }
}

// Static labels, not buttons — this is a picture of the product, so it must not
// be reachable by keyboard or announced as interactive.
.mini-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.6rem;
  padding: 0.85rem;

  span {
    text-align: center;
    padding: 0.6rem;
    border-radius: var(--pico-border-radius);
    font-size: 0.9rem;
    font-weight: 600;
    border: 1px solid;
  }
}

.reject {
  background: var(--rg-red-soft);
  border-color: var(--rg-red-border);
  color: var(--rg-red);
}

.accept {
  background: var(--rg-green-soft);
  border-color: var(--rg-green-border);
  color: var(--rg-green);
}
</style>
