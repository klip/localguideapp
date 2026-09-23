<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import RangePicker from './RangePicker.vue'
import { useCategoriesStore } from '@/stores/categories'
import { useFiltersStore } from '@/stores/filters'
import { formatRange, rangeFormatFor } from '@/utils/formatRange'
import type { Gender, NumericRange } from '@/types'

/**
 * The discovery filter form: pick a category first, then pick values inside
 * it. Selected values sit as tags under that category's own title, with a
 * rule between one category's group and the next.
 *
 * Nothing here is hardcoded. The categories come from
 * `api.attributeCategories()` (plus the synthetic `age` one — see
 * `stores/categories.ts`), and each renders according to its `kind`: an
 * `options` category as toggleable tags, a `range` category as sliders plus
 * an Add button that collects several spans. The single exception is gender,
 * a human constant rather than a category, which stays a dropdown.
 *
 * `compact` tightens the type for the 250px desktop rail.
 */
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })

const emit = defineEmits<{ apply: [] }>()

const categoriesStore = useCategoriesStore()
const filtersStore = useFiltersStore()
const { filters, isPristine } = storeToRefs(filtersStore)

onMounted(() => categoriesStore.load())

/** The category currently being added to — step one of the hierarchy. */
const openKey = ref<string | null>(null)

/** Groups already carrying a selection, plus whichever one is open. */
const shownCategories = computed(() =>
  categoriesStore.filterable.filter(
    (category) =>
      category.key === openKey.value ||
      filtersStore.valuesFor(category.key).length > 0 ||
      filtersStore.rangesFor(category.key).length > 0,
  ),
)

/** What the "add a filter" row offers: everything not already shown. */
const addableCategories = computed(() =>
  categoriesStore.filterable.filter(
    (category) => !shownCategories.value.some((shown) => shown.key === category.key),
  ),
)

function openCategory(key: string) {
  openKey.value = openKey.value === key ? null : key
}

function onGenderChange(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  filtersStore.setGender(value ? (value as Gender) : null)
}

function addRange(key: string, range: NumericRange) {
  filtersStore.addRange(key, range)
}

function clearAll() {
  filtersStore.reset()
  openKey.value = null
}
</script>

<template>
  <form class="filter-panel" @submit.prevent="emit('apply')">
    <!-- The one dropdown: gender is a fact about a person, not a tag list. -->
    <label :class="{ tiny: compact }">
      Gender
      <select :value="filters.gender ?? ''" @change="onGenderChange">
        <option value="">No preference</option>
        <option value="male">Male</option>
        <option value="female">Female</option>
      </select>
    </label>

    <div v-for="category in shownCategories" :key="category.key" class="filter-group">
      <div class="group-head">
        <strong class="tiny">{{ category.label }}</strong>
        <button
          type="button"
          class="link-btn tiny"
          :aria-expanded="openKey === category.key"
          @click="openCategory(category.key)"
        >
          {{ openKey === category.key ? 'Done' : 'Edit' }}
        </button>
      </div>

      <!-- Range category: collected spans as chips, sliders while open. -->
      <template v-if="category.kind === 'range'">
        <ul v-if="openKey !== category.key && filtersStore.rangesFor(category.key).length" class="tags">
          <li v-for="(range, index) in filtersStore.rangesFor(category.key)" :key="index" class="tag tag--on">
            {{ formatRange(range, rangeFormatFor(category.key)) }}
            <button
              type="button"
              class="tag-remove"
              :aria-label="`Remove ${formatRange(range, rangeFormatFor(category.key))}`"
              @click="filtersStore.removeRange(category.key, index)"
            >
              ×
            </button>
          </li>
        </ul>

        <RangePicker
          v-if="openKey === category.key"
          :ranges="filtersStore.rangesFor(category.key)"
          :min="category.rangeMin ?? 0"
          :max="category.rangeMax ?? 100"
          :format="rangeFormatFor(category.key)"
          @add="(range) => addRange(category.key, range)"
          @remove="(index) => filtersStore.removeRange(category.key, index)"
        />
      </template>

      <!-- Options category: every option while open, just the picks when closed. -->
      <ul v-else class="tags">
        <template v-if="openKey === category.key">
          <li v-for="option in category.options" :key="option.value">
            <button
              type="button"
              class="tag"
              :class="{ 'tag--on': filtersStore.hasValue(category.key, option.value) }"
              :aria-pressed="filtersStore.hasValue(category.key, option.value)"
              @click="filtersStore.toggleValue(category.key, option.value)"
            >
              {{ option.label }}
            </button>
          </li>
          <li v-if="!category.options.length" class="tiny muted">Nobody has added an option here yet.</li>
        </template>

        <template v-else>
          <li v-for="value in filtersStore.valuesFor(category.key)" :key="value" class="tag tag--on">
            {{ categoriesStore.optionLabelFor(category.key, value) }}
            <button
              type="button"
              class="tag-remove"
              :aria-label="`Remove ${categoriesStore.optionLabelFor(category.key, value)}`"
              @click="filtersStore.toggleValue(category.key, value)"
            >
              ×
            </button>
          </li>
        </template>
      </ul>
    </div>

    <div v-if="addableCategories.length" class="add-row">
      <span class="tiny muted">Add a filter</span>
      <ul class="tags">
        <li v-for="category in addableCategories" :key="category.key">
          <button type="button" class="tag tag--add" @click="openCategory(category.key)">
            + {{ category.label }}
          </button>
        </li>
      </ul>
    </div>

    <p v-if="categoriesStore.loading && !categoriesStore.loaded" class="tiny muted">Loading filters…</p>

    <button type="submit" class="full">Show matches</button>
    <button v-if="!isPristine" type="button" class="soft-btn full" @click="clearAll">
      Clear filters
    </button>
  </form>
</template>

<style scoped lang="scss">
.filter-panel {
  margin-bottom: 0;
}

// The delimiter between one category's group and the next.
.filter-group {
  padding: 0.75rem 0;
  border-top: 1px solid var(--rg-line);

  & + .add-row {
    border-top: 1px solid var(--rg-line);
    padding-top: 0.75rem;
  }
}

.group-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.4rem;
}

.link-btn {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--pico-primary-hover);
  }
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  list-style: none;
  padding: 0;
  margin: 0;
}

.tag {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  width: auto;
  margin: 0;
  padding: 0.3rem 0.65rem;
  border-radius: 999px;
  border: 1px solid var(--rg-line);
  background: #fff;
  color: var(--rg-ink);
  font-size: 0.8rem;
  line-height: 1.3;

  &:hover,
  &:focus-visible {
    border-color: var(--rg-orange-300);
    background: var(--rg-orange-50);
    color: var(--rg-ink);
  }
}

.tag--on {
  border-color: var(--rg-orange-200);
  background: var(--rg-orange-50);
  color: var(--rg-orange-ink);
  font-weight: 600;
}

.tag--add {
  border-style: dashed;
}

.tag-remove {
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font-size: 1rem;
  line-height: 1;

  &:hover,
  &:focus-visible {
    background: none;
    color: var(--rg-red);
  }
}

.add-row {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.75rem 0;
}

button[type='submit'] {
  margin-bottom: 0.5rem;
}
</style>
