<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'

/**
 * A small type-ahead text input: filters `suggestions` by the typed text and
 * shows them in a dropdown, with an optional "+ Create '<text>'" row when
 * nothing matches exactly. Generic over what a "suggestion" means — used on
 * `ProfilePage.vue` for the phone country-code picker (`allowCreate: false`,
 * nothing to create) and for the interest category/option pickers
 * (`allowCreate: true`, so a user can grow the taxonomy — see
 * `Profiles::setAttributeValues()`).
 *
 * Deliberately not a full combobox widget (no arrow-key highlight tracking)
 * — Enter picks the top suggestion, or creates a new entry if nothing
 * matches; that covers this app's actual use cases without the extra state.
 * Selecting a suggestion is wired to `@mousedown.prevent` rather than
 * `@click` so the input never blurs before the click registers — no
 * blur-delay timer needed.
 *
 * The suggestion list renders in normal document flow (not
 * `position: absolute`) — it pushes whatever comes after it down the page
 * rather than floating over it. This combobox deliberately stays open
 * across a selection (see `choose()`/`createNew()`) so a user can add
 * several options in one sitting, so an absolutely-positioned list would
 * otherwise sit open indefinitely and could visually cover whatever's
 * below it — on `ProfilePage.vue` that was the "Save profile" button, so a
 * click aimed at it actually landed on the still-open dropdown instead and
 * silently never submitted. Confirmed live in the browser; not something a
 * unit test would catch (jsdom has no real layout/paint to detect
 * overlap).
 */
export interface ComboboxSuggestion {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    modelValue: string
    suggestions: ComboboxSuggestion[]
    placeholder?: string
    allowCreate?: boolean
    maxSuggestions?: number
    /**
     * Focuses the input as soon as it's mounted — for a combobox that only
     * exists in the DOM while "expanded" (see `ProfilePage.vue`'s
     * per-category option picker). Implemented as an explicit `.focus()`
     * call in `onMounted()` (via `inputRef`) rather than the native
     * `autofocus` HTML attribute — that attribute's browser-native
     * "autofocus processing model" wasn't reliably stealing focus away from
     * whatever input the user had just interacted with (e.g. the bottom
     * "add a category" combobox, focused via `@mousedown.prevent` right
     * before this one mounts) — confirmed live in the browser, not caught
     * by unit tests (jsdom doesn't model that heuristic).
     */
    autofocus?: boolean
  }>(),
  { placeholder: '', allowCreate: true, maxSuggestions: 8, autofocus: false },
)

const inputRef = ref<HTMLInputElement | null>(null)

onMounted(() => {
  if (props.autofocus) inputRef.value?.focus()
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  select: [suggestion: ComboboxSuggestion & { isNew: boolean }]
  /** Fired when the input loses focus — lets a parent collapse an expand-on-demand combobox back down (see `ProfilePage.vue`). */
  blur: []
}>()

const open = ref(false)

const normalizedQuery = computed(() => props.modelValue.trim().toLowerCase())

const filtered = computed(() => {
  if (!normalizedQuery.value) return props.suggestions.slice(0, props.maxSuggestions)
  return props.suggestions
    .filter((item) => item.label.toLowerCase().includes(normalizedQuery.value))
    .slice(0, props.maxSuggestions)
})

const hasExactMatch = computed(() =>
  props.suggestions.some((item) => item.label.trim().toLowerCase() === normalizedQuery.value),
)

const showCreateRow = computed(
  () => props.allowCreate && normalizedQuery.value !== '' && !hasExactMatch.value,
)

function onInput(event: Event) {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
  open.value = true
}

/**
 * Deliberately doesn't close the dropdown (`open` stays as-is) — the
 * combobox is meant to stay usable for picking several suggestions in a
 * row (see `ProfilePage.vue`'s per-category option picker) and only
 * actually closes on blur/Escape. The list re-renders with whatever the
 * parent now passes as `suggestions` (typically narrowed to exclude what
 * was just picked) once `modelValue` is cleared in response to `select`.
 */
function choose(item: ComboboxSuggestion) {
  emit('select', { ...item, isNew: false })
}

function createNew() {
  const label = props.modelValue.trim()
  if (!label) return
  emit('select', { value: label, label, isNew: true })
}

function onBlur() {
  open.value = false
  emit('blur')
}

function onEnter() {
  if (filtered.value.length > 0) {
    choose(filtered.value[0]!)
  } else if (showCreateRow.value) {
    createNew()
  }
}
</script>

<template>
  <div class="combobox">
    <input
      ref="inputRef"
      :value="modelValue"
      type="text"
      :placeholder="placeholder"
      autocomplete="off"
      @input="onInput"
      @focus="open = true"
      @blur="onBlur"
      @keydown.enter.prevent="onEnter"
      @keydown.escape="open = false"
    />
    <ul v-if="open && (filtered.length > 0 || showCreateRow)" class="combobox-list">
      <li v-for="item in filtered" :key="item.value" @mousedown.prevent="choose(item)">
        {{ item.label }}
      </li>
      <li v-if="showCreateRow" class="create-row" @mousedown.prevent="createNew">
        + Create "{{ modelValue.trim() }}"
      </li>
    </ul>
  </div>
</template>

<style scoped lang="scss">
.combobox-list {
  margin: 0.15rem 0 0;
  padding: 0.3rem 0;
  list-style: none;
  max-height: 12rem;
  overflow-y: auto;
  background: #fff;
  border: 1px solid var(--rg-line);
  border-radius: var(--rg-radius-panel, 0.5rem);
  box-shadow: var(--rg-shadow);

  li {
    padding: 0.4rem 0.75rem;
    cursor: pointer;
    font-size: 0.9rem;

    &:hover {
      background: var(--rg-cream, #f5f0ea);
    }
  }

  .create-row {
    font-weight: 600;
  }
}
</style>
