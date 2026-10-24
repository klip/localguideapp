<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import PlaceSearch from './PlaceSearch.vue'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useCurrentPosition } from '@/composables/useCurrentPosition'
import { useAuthStore } from '@/stores/auth'
import { useFiltersStore } from '@/stores/filters'
import type { PlacePoint } from '@/types'
import { currentPlace } from '@/utils/currentPlace'

/**
 * Asks a signed-in user, **once per session and role**, where to search —
 * a short explanation first, since a cold browser prompt on login is usually
 * refused. "Use my location" reads the device once (the browser's own
 * permission prompt follows), "Choose a place" opens the place search, "Not
 * now" closes it for the session. It skips asking when:
 *
 * - a search point is already set (this session, or from a link);
 * - acting as a guide with a base on their profile — that's used silently;
 * - the browser already granted location — it's read silently.
 */
const api = useApi()
const auth = useAuthStore()
const filtersStore = useFiltersStore()
const position = useCurrentPosition()

const visible = ref(false)
const choosing = ref(false)
const error = ref<string | null>(null)
const role = computed(() => auth.activeRole ?? 'guest')
const askedKey = computed(() => `belikelocal.locationAsked.${role.value}`)

function asked(): boolean {
  try {
    return sessionStorage.getItem(askedKey.value) === '1'
  } catch {
    return false
  }
}

function markAsked() {
  try {
    sessionStorage.setItem(askedKey.value, '1')
  } catch {
    // Then it may ask again after a reload; harmless.
  }
}

function settle(place: PlacePoint) {
  filtersStore.setNear(place)
  markAsked()
  visible.value = false
}

async function useLocation() {
  error.value = null
  const result = await currentPlace(api, position)
  if (!result) return
  if (result.ok) {
    settle(result.place)
  } else {
    error.value = result.message
    choosing.value = true
  }
}

function notNow() {
  position.cancel()
  markAsked()
  visible.value = false
}

async function locationGranted(): Promise<boolean> {
  try {
    const status = await navigator.permissions?.query({ name: 'geolocation' })
    return status?.state === 'granted'
  } catch {
    return false
  }
}

onMounted(async () => {
  if (!auth.isAuthenticated || filtersStore.filters.near || asked()) return

  if (role.value === 'guide') {
    try {
      const { base } = await api.myGuideBase()
      if (base) {
        settle({
          label: base.label,
          countryCode: base.countryCode,
          latitude: base.latitude,
          longitude: base.longitude,
        })
        return
      }
    } catch {
      // Ask instead.
    }
  }

  if (await locationGranted()) {
    const result = await currentPlace(api, position)
    if (result?.ok) {
      settle(result.place)
      return
    }
  }

  if (!filtersStore.filters.near) visible.value = true
})

// Set some other way (the filter bar, a link) — nothing left to ask.
watch(
  () => filtersStore.filters.near,
  (near) => {
    if (near && visible.value) {
      markAsked()
      visible.value = false
    }
  },
)
</script>

<template>
  <div
    v-if="visible"
    class="location-prompt"
    role="region"
    :aria-label="t(`common.location_prompt.title_${role}`)"
    data-test="location-prompt"
  >
    <p class="prompt-title">
      <span aria-hidden="true">📍</span> {{ t(`common.location_prompt.title_${role}`) }}
    </p>
    <p class="tiny muted">{{ t(`common.location_prompt.body_${role}`) }}</p>

    <PlaceSearch
      v-if="choosing"
      :model-value="null"
      :allow-current="false"
      autofocus
      @update:model-value="(place) => place && settle(place)"
    />
    <p v-if="error" class="tiny muted" role="status">{{ error }}</p>

    <div class="prompt-actions">
      <button
        type="button"
        :aria-busy="position.locating.value || undefined"
        :disabled="position.locating.value"
        data-test="prompt-use-location"
        @click="useLocation"
      >
        {{
          position.locating.value
            ? t('common.place_search.locating')
            : t('common.location_prompt.use_location')
        }}
      </button>
      <button
        v-if="!choosing"
        type="button"
        class="secondary outline"
        data-test="prompt-choose"
        @click="choosing = true"
      >
        {{ t('common.location_prompt.choose_place') }}
      </button>
      <button type="button" class="link-btn" data-test="prompt-not-now" @click="notNow">
        {{ t('common.location_prompt.not_now') }}
      </button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.location-prompt {
  margin: 0 0 1rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--rg-orange-200);
  border-radius: var(--rg-radius-soft);
  background: var(--rg-orange-50);

  p {
    margin: 0 0 0.5rem;
  }
}

.prompt-title {
  font-weight: 700;
}

.prompt-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;

  button {
    width: auto;
    margin: 0;
    padding: 0.4rem 0.9rem;
    font-size: 0.9rem;
  }
}

.link-btn {
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  cursor: pointer;
}
</style>
