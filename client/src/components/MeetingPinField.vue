<script setup lang="ts">
import { ref } from 'vue'
import MapPinLinks from '@/components/MapPinLinks.vue'
import { useCurrentPosition } from '@/composables/useCurrentPosition'
import { t } from '@/i18n'
import type { MapPin } from '@/plugins/api'
import { anchorTo, useMessagesStore } from '@/stores/messages'

/**
 * A booking's meeting pin inside `BookingPanel`'s pay and meeting-place
 * forms: "Pin where I am now" reads the device once (like chat's "Share my
 * location") and only fills the form — the form's own Save sends it. Shows a
 * set pin with links to the viewer's maps app and "Remove pin". Closing the
 * form unmounts this, which drops a position request still in flight.
 */
const pin = defineModel<MapPin | null>({ required: true })

const messages = useMessagesStore()
const position = useCurrentPosition()
const root = ref<HTMLElement | null>(null)

async function pinHere() {
  if (position.locating.value) return
  const placement = anchorTo('element', root.value)
  const result = await position.locate()
  if (!result) return
  if (!result.ok) {
    messages.error(t(`common.booking_panel.pin_${result.reason}`), placement)
    return
  }
  pin.value = { latitude: result.position.latitude, longitude: result.position.longitude }
}
</script>

<template>
  <div ref="root" class="pin-field" data-test="pin-field">
    <template v-if="pin">
      <span class="pin-set"
        ><span aria-hidden="true">📍 </span>{{ t('common.booking_panel.pin_set') }}</span
      >
      <MapPinLinks :latitude="pin.latitude" :longitude="pin.longitude" />
      <button type="button" class="link-btn" @click="pin = null">
        {{ t('common.booking_panel.pin_remove') }}
      </button>
    </template>
    <button
      v-else
      type="button"
      class="soft-btn"
      data-test="pin-here"
      :aria-busy="position.locating.value || undefined"
      :disabled="position.locating.value"
      @click="pinHere"
    >
      <span aria-hidden="true">📍 </span
      >{{
        position.locating.value
          ? t('common.booking_panel.pin_locating')
          : t('common.booking_panel.pin_here')
      }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.pin-field {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 0.75rem;
  margin-bottom: 0.75rem;
}

.pin-set {
  font-weight: 600;
  font-size: 0.9rem;
}

.link-btn {
  all: unset;
  cursor: pointer;
  font-size: 0.85rem;
  text-decoration: underline;
  color: var(--rg-muted, inherit);
}
</style>
