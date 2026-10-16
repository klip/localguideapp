<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@/i18n'
import { mapsLinksFor } from '@/utils/mapsLink'

/**
 * "Open in Apple Maps · Open in Google Maps" for a map pin — the viewer's own
 * device's maps app first (`utils/mapsLink.ts`). A booking's meeting pin;
 * chat bubbles render their own with the same links.
 */
const props = defineProps<{ latitude: number; longitude: number; label?: string }>()

const links = computed(() => mapsLinksFor(props.latitude, props.longitude, undefined, props.label))
</script>

<template>
  <span class="map-pin-links">
    <a
      v-for="(link, index) in links"
      :key="link.provider"
      :href="link.href"
      :class="{ primary: index === 0 }"
      :target="link.provider === 'geo' ? undefined : '_blank'"
      rel="noopener noreferrer"
      :data-provider="link.provider"
      >{{ t(`common.map_links.open_${link.provider}`) }}</a
    >
  </span>
</template>

<style scoped lang="scss">
.map-pin-links {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  font-size: 0.85rem;

  .primary {
    font-weight: 700;
  }
}
</style>
