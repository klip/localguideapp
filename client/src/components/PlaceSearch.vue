<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { currentLanguage, t } from '@/i18n'
import { useApi, type Place, type PlacePoint } from '@/plugins/api'
import { useCurrentPosition } from '@/composables/useCurrentPosition'
import { currentPlace } from '@/utils/currentPlace'

/**
 * Type a town or city, pick one from the suggestions (`api.geocode()`), or
 * "Use my current location" (the device, once, then `api.reverseGeocode()`
 * for its name). A combobox: arrows move through the list, Enter picks,
 * Escape closes. `v-model` is the chosen place (`null` while none) — typing
 * alone doesn't change it, only picking does.
 */
const props = withDefaults(
  defineProps<{
    modelValue: PlacePoint | null
    placeholder?: string
    /** Suggestions close to this point come first. */
    near?: { latitude: number; longitude: number } | null
    allowCurrent?: boolean
    inputId?: string
    /** Focus the input on mount — when it opens in place of a "Change" button. */
    autofocus?: boolean
  }>(),
  { placeholder: undefined, near: null, allowCurrent: true, inputId: undefined, autofocus: false },
)

const emit = defineEmits<{ 'update:modelValue': [place: PlacePoint] }>()

const api = useApi()
const position = useCurrentPosition()
const listId = useId()
const fallbackInputId = useId()

const input = ref<HTMLInputElement | null>(null)
const query = ref(props.modelValue?.label ?? '')
const results = ref<Place[]>([])
const open = ref(false)
const active = ref(-1)
const searching = ref(false)
const status = ref<'idle' | 'empty' | 'failed'>('idle')
const locationError = ref<string | null>(null)

let timer: ReturnType<typeof setTimeout> | undefined
let request = 0

watch(
  () => props.modelValue?.label,
  (label) => {
    query.value = label ?? ''
  },
)

const showList = computed(
  () => open.value && (results.value.length > 0 || status.value !== 'idle' || searching.value),
)

function onInput() {
  open.value = true
  active.value = -1
  clearTimeout(timer)
  const text = query.value.trim()
  if (text.length < 2) {
    request++
    results.value = []
    status.value = 'idle'
    searching.value = false
    return
  }
  timer = setTimeout(() => void search(text), 300)
}

async function search(text: string) {
  const mine = ++request
  searching.value = true
  try {
    const { places } = await api.geocode(text, currentLanguage.value, props.near ?? undefined)
    if (mine !== request) return
    results.value = places
    status.value = places.length ? 'idle' : 'empty'
  } catch {
    if (mine !== request) return
    results.value = []
    status.value = 'failed'
  } finally {
    if (mine === request) searching.value = false
  }
}

function choose(place: Place | PlacePoint) {
  const chosen: PlacePoint = {
    label: place.label,
    countryCode: place.countryCode,
    latitude: place.latitude,
    longitude: place.longitude,
  }
  query.value = chosen.label
  open.value = false
  results.value = []
  status.value = 'idle'
  emit('update:modelValue', chosen)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowDown' && results.value.length) {
    event.preventDefault()
    open.value = true
    active.value = (active.value + 1) % results.value.length
  } else if (event.key === 'ArrowUp' && results.value.length) {
    event.preventDefault()
    active.value = active.value <= 0 ? results.value.length - 1 : active.value - 1
  } else if (event.key === 'Enter' && open.value && results.value.length) {
    event.preventDefault()
    choose(results.value[Math.max(active.value, 0)]!)
  } else if (event.key === 'Escape' && open.value) {
    event.preventDefault()
    open.value = false
  }
}

function onBlur() {
  // Let a click on a suggestion land first (it uses mousedown.prevent too).
  setTimeout(() => {
    open.value = false
    // Typed but never picked: show the chosen place again.
    query.value = props.modelValue?.label ?? query.value
  }, 120)
}

async function useCurrent() {
  locationError.value = null
  const result = await currentPlace(api, position)
  if (!result) return
  if (result.ok) choose(result.place)
  else locationError.value = result.message
}

onMounted(() => {
  if (props.autofocus) {
    input.value?.focus()
    input.value?.select()
  }
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  request++
})
</script>

<template>
  <div class="place-search">
    <input
      :id="inputId ?? fallbackInputId"
      ref="input"
      v-model="query"
      type="search"
      role="combobox"
      autocomplete="off"
      :placeholder="placeholder ?? t('common.place_search.placeholder')"
      :aria-expanded="showList"
      :aria-controls="listId"
      :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
      aria-autocomplete="list"
      data-test="place-input"
      @input="onInput"
      @keydown="onKeydown"
      @focus="open = results.length > 0"
      @blur="onBlur"
    />
    <ul v-show="showList" :id="listId" class="suggestions" role="listbox">
      <li v-if="searching && !results.length" class="note" aria-live="polite">
        {{ t('common.place_search.searching') }}
      </li>
      <li v-else-if="status === 'empty'" class="note" aria-live="polite">
        {{ t('common.place_search.no_results') }}
      </li>
      <li v-else-if="status === 'failed'" class="note" aria-live="polite">
        {{ t('common.place_search.failed') }}
      </li>
      <li
        v-for="(place, index) in results"
        :id="`${listId}-${index}`"
        :key="`${place.label}-${index}`"
        role="option"
        class="option"
        :class="{ active: index === active }"
        :aria-selected="index === active"
        :data-test="`place-option-${index}`"
        @mousedown.prevent="choose(place)"
      >
        {{ place.label }}
      </li>
    </ul>
    <button
      v-if="allowCurrent"
      type="button"
      class="link-btn tiny"
      :aria-busy="position.locating.value || undefined"
      :disabled="position.locating.value"
      data-test="place-current"
      @click="useCurrent"
    >
      {{
        position.locating.value
          ? t('common.place_search.locating')
          : t('common.place_search.use_current')
      }}
    </button>
    <p v-if="locationError" class="tiny muted" role="status">{{ locationError }}</p>
  </div>
</template>

<style scoped lang="scss">
.place-search {
  display: flex;
  flex-direction: column;
  align-items: flex-start;

  input {
    margin-bottom: 0.25rem;
  }
}

// In the flow, like AttributeCombobox's list: never covers what's below it.
.suggestions {
  width: 100%;
  margin: 0 0 0.4rem;
  padding: 0.25rem 0;
  list-style: none;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-soft);
  background: var(--pico-card-background-color, #fff);

  li {
    list-style: none;
    margin: 0;
    padding: 0.45rem 0.75rem;
  }
}

.option {
  cursor: pointer;

  &:hover,
  &.active {
    background: var(--rg-orange-50);
  }
}

.note {
  color: var(--pico-muted-color);
  font-size: 0.9rem;
}

.link-btn {
  width: auto;
  margin: 0 0 0.25rem;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  cursor: pointer;
}
</style>
