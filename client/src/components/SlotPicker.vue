<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useApi } from '@/plugins/api'
import { currentLanguage, t } from '@/i18n'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Choosing when to meet a guide who keeps a calendar (`Availability.php`,
 * docs/availability-plan.md): the trip length, then a month of days where
 * only days with a free start time can be picked, then that day's free start
 * times. Shown in the viewer's own time, with the guide's beside it when
 * their zones differ. Used by `BookingPanel`'s pay form.
 *
 * `v-model` is the chosen start (ISO UTC, `''` until one is picked);
 * `v-model:hours` the trip length. Emits `unavailable` when the guide has no
 * calendar — the form then falls back to a free date-time input.
 */
const props = defineProps<{ guideId: number; name: string }>()
const start = defineModel<string>({ default: '' })
const hours = defineModel<number>('hours', { default: 2 })
const emit = defineEmits<{ unavailable: [] }>()

const api = useApi()
const ready = ref(false)
const loadingDays = ref(false)
const loadingTimes = ref(false)
const timeZone = ref('UTC')
const month = ref('')
const firstMonth = ref('')
const days = ref<string[]>([])
const day = ref('')
const starts = ref<string[]>([])
/** The length the shown days were fetched for — so adopting the guide's usual one doesn't refetch. */
let loadedHours = 0

/** Half hours from 30 minutes to 12 hours, then whole hours to 24. */
const LENGTHS = [
  ...Array.from({ length: 24 }, (_, i) => (i + 1) / 2),
  ...Array.from({ length: 12 }, (_, i) => 13 + i),
]

const viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone
const sameZone = computed(() => offsetOf(viewerZone) === offsetOf(timeZone.value))

function offsetOf(zone: string) {
  try {
    return new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'longOffset' }).format(
      new Date(),
    )
  } catch {
    return zone
  }
}

/** The month grid: leading blanks to Monday, then each date. */
const grid = computed(() => {
  if (!month.value) return []
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const count = new Date(Date.UTC(y, m, 0)).getUTCDate()
  const lead = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7
  const cells: (string | null)[] = Array.from({ length: lead }, () => null)
  for (let d = 1; d <= count; d++) cells.push(`${month.value}-${String(d).padStart(2, '0')}`)
  return cells
})

const weekdays = computed(() => {
  const format = new Intl.DateTimeFormat(currentLanguage.value, {
    weekday: 'narrow',
    timeZone: 'UTC',
  })
  // 2024-01-01 was a Monday.
  return Array.from({ length: 7 }, (_, i) => format.format(new Date(Date.UTC(2024, 0, 1 + i))))
})

const monthLabel = computed(() => {
  if (!month.value) return ''
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  return new Intl.DateTimeFormat(currentLanguage.value, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(Date.UTC(y, m - 1, 1)))
})

function shiftMonth(step: number) {
  const [y, m] = month.value.split('-').map(Number) as [number, number]
  const next = new Date(Date.UTC(y, m - 1 + step, 1))
  month.value = `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`
}

function time(iso: string, zone: string) {
  return new Intl.DateTimeFormat(currentLanguage.value, {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: zone,
  }).format(new Date(iso))
}

function dayNumber(date: string) {
  return Number(date.slice(8))
}

function dayLabel(date: string) {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number]
  return new Intl.DateTimeFormat(currentLanguage.value, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  }).format(new Date(Date.UTC(y, m - 1, d)))
}

async function loadDays() {
  loadingDays.value = true
  try {
    const result = await api.guideCalendar(
      props.guideId,
      month.value || undefined,
      ready.value ? hours.value : undefined,
    )
    if (!result?.hasCalendar) {
      emit('unavailable')
      return
    }
    timeZone.value = result.timeZone
    loadedHours = result.durationHours
    if (!ready.value) hours.value = result.durationHours
    month.value = result.month ?? month.value
    firstMonth.value ||= month.value
    days.value = result.days ?? []
    if (day.value && !days.value.includes(day.value)) {
      day.value = ''
      start.value = ''
      starts.value = []
    }
    ready.value = true
  } catch (err) {
    reportApiError(err, t('common.slot_picker.load_failed'))
  } finally {
    loadingDays.value = false
  }
}

async function pickDay(date: string) {
  day.value = date
  start.value = ''
  loadingTimes.value = true
  try {
    const result = await api.guideStartTimes(props.guideId, date, hours.value)
    starts.value = result.hasCalendar ? (result.starts ?? []) : []
  } catch (err) {
    reportApiError(err, t('common.slot_picker.load_failed'))
  } finally {
    loadingTimes.value = false
  }
}

onMounted(loadDays)
watch(month, (next, before) => {
  if (ready.value && before && next !== before) void loadDays()
})
watch(hours, async (next, before) => {
  if (!ready.value || next === before || next === loadedHours) return
  await loadDays()
  if (day.value) await pickDay(day.value)
})
</script>

<template>
  <div class="slot-picker" data-test="slot-picker">
    <p v-if="!ready" aria-busy="true" class="muted">{{ t('common.slot_picker.loading') }}</p>
    <template v-else>
      <div class="month" role="group" :aria-label="t('common.slot_picker.pick_day')">
        <div class="month-head">
          <button
            type="button"
            class="nav"
            :disabled="month <= firstMonth || loadingDays"
            :aria-label="t('common.slot_picker.previous_month')"
            @click="shiftMonth(-1)"
          >
            <span class="rtl-flip" aria-hidden="true">‹</span>
          </button>
          <strong class="month-label" aria-live="polite">{{ monthLabel }}</strong>
          <button
            type="button"
            class="nav"
            :disabled="loadingDays"
            :aria-label="t('common.slot_picker.next_month')"
            data-test="next-month"
            @click="shiftMonth(1)"
          >
            <span class="rtl-flip" aria-hidden="true">›</span>
          </button>
        </div>
        <div class="grid" :aria-busy="loadingDays">
          <span v-for="(w, i) in weekdays" :key="`w${i}`" class="weekday" aria-hidden="true">{{
            w
          }}</span>
          <template v-for="(cell, i) in grid" :key="cell ?? `blank${i}`">
            <span v-if="!cell" />
            <button
              v-else
              type="button"
              class="day"
              :class="{ chosen: cell === day }"
              :disabled="!days.includes(cell)"
              :aria-pressed="cell === day"
              :aria-label="dayLabel(cell)"
              :data-day="cell"
              @click="pickDay(cell)"
            >
              {{ dayNumber(cell) }}
            </button>
          </template>
        </div>
        <p v-if="!loadingDays && !days.length" class="tiny muted" data-test="no-days">
          {{ t('common.slot_picker.no_days', { name }) }}
        </p>
      </div>

      <div v-if="day" class="times" role="group" :aria-label="t('common.slot_picker.pick_time')">
        <p class="tiny">
          <strong>{{ dayLabel(day) }}</strong>
          <span v-if="!sameZone" class="muted"> · {{ t('common.slot_picker.your_time') }}</span>
        </p>
        <p v-if="loadingTimes" aria-busy="true" class="muted">
          {{ t('common.slot_picker.loading') }}
        </p>
        <p v-else-if="!starts.length" class="tiny muted">{{ t('common.slot_picker.no_times') }}</p>
        <div v-else class="time-list">
          <button
            v-for="s in starts"
            :key="s"
            type="button"
            class="time"
            :class="{ chosen: s === start }"
            :aria-pressed="s === start"
            :data-start="s"
            @click="start = s"
          >
            {{ time(s, viewerZone) }}
            <small v-if="!sameZone">{{
              t('common.slot_picker.their_time', { time: time(s, timeZone), name })
            }}</small>
          </button>
        </div>
      </div>
      <!-- The length last: the month (with its year) and its days come first. -->
      <label class="length">
        <span class="field-label"
          ><span class="field-label-text">{{ t('common.slot_picker.trip_length') }}</span></span
        >
        <select v-model.number="hours" data-test="slot-length">
          <option v-for="h in LENGTHS" :key="h" :value="h">
            {{ t('common.slot_picker.hours', { hours: h }) }}
          </option>
        </select>
      </label>
    </template>
  </div>
</template>

<style scoped lang="scss">
.slot-picker {
  display: grid;
  gap: 0.75rem;
}

// Pico lays out any [role=group] as a row of buttons; these stack: the
// month and year above the days, the day's name above its times.
.month,
.times {
  display: block;
}

.length {
  margin: 0;

  select {
    max-width: 12rem;
    margin: 0;
  }
}

// Month and year, large, above the days.
.month-label {
  font-family: var(--rg-font-display);
  font-size: 1.125rem;
  text-transform: capitalize;
}

.month-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.25rem;
}

.nav {
  width: 2.25rem;
  height: 2.25rem;
  margin: 0;
  padding: 0;
  border-radius: 50%;
  background: transparent;
  color: inherit;
  border: 1px solid var(--pico-muted-border-color);
  font-size: 1.2rem;
  line-height: 1;
}

.grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.25rem;
  text-align: center;
}

.weekday {
  font-size: 0.75rem;
  color: var(--pico-muted-color);
}

.day {
  margin: 0;
  padding: 0.4rem 0;
  border-radius: 0.5rem;
  font-size: 0.9rem;
  background: var(--pico-card-sectioning-background-color, #f3f4f6);
  color: inherit;
  border: 1px solid transparent;
}

.day:disabled {
  background: transparent;
  color: var(--pico-muted-color);
  opacity: 0.45;
  cursor: default;
}

.day.chosen,
.time.chosen {
  background: var(--pico-primary-background);
  color: var(--pico-primary-inverse);
  border-color: var(--pico-primary-background);
}

.time-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.time {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  width: auto;
  margin: 0;
  padding: 0.35rem 0.75rem;
  border-radius: 0.5rem;
  background: transparent;
  color: inherit;
  border: 1px solid var(--pico-muted-border-color);
  font-size: 0.9rem;

  small {
    font-size: 0.7rem;
    opacity: 0.8;
  }
}
</style>
