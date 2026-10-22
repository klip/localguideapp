<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { currentLanguage, t } from '@/i18n'
import { useApi, ApiError, type CalendarException, type MyCalendar } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'

/**
 * A guide's availability calendar (guide mode; docs/availability-plan.md):
 * weekly working hours, booking rules, days off and extra hours, and the
 * bookings coming up. Travellers can then only book times the guide is free
 * (`SlotPicker` in `BookingPanel`); bookings hold their slot by themselves.
 * Until a guide saves one, travellers pick any time, as before.
 */
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()

const loading = ref(true)
const saving = ref(false)
const view = ref<MyCalendar | null>(null)
const fieldError = reactive({ field: '', message: '' })
const formRef = ref<HTMLFormElement | null>(null)

/** ISO weekday (1 = Monday) → windows as "HH:MM" pairs, for the time inputs. */
const weekly = reactive<Record<number, { start: string; end: string }[]>>({
  1: [],
  2: [],
  3: [],
  4: [],
  5: [],
  6: [],
  7: [],
})
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
const rules = reactive({
  timeZone: browserZone,
  slotMinutes: 30,
  bufferMinutes: 30,
  defaultTripHours: 3,
  minNoticeHours: 12,
  maxDaysAhead: 180,
})
const exception = reactive({
  day: '',
  kind: 'off' as 'off' | 'extra',
  allDay: true,
  start: '09:00',
  end: '13:00',
  note: '',
})

const isGuide = computed(() => auth.activeRole === 'guide')
const hasCalendar = computed(() => !!view.value?.calendar)
const zones = (() => {
  try {
    return (Intl as unknown as { supportedValuesOf(key: string): string[] }).supportedValuesOf(
      'timeZone',
    )
  } catch {
    return [browserZone]
  }
})()
const LENGTHS = [
  ...Array.from({ length: 24 }, (_, i) => (i + 1) / 2),
  ...Array.from({ length: 12 }, (_, i) => 13 + i),
]
const today = new Date().toISOString().slice(0, 10)

const weekdayNames = computed(() => {
  const format = new Intl.DateTimeFormat(currentLanguage.value, {
    weekday: 'long',
    timeZone: 'UTC',
  })
  return Array.from({ length: 7 }, (_, i) => format.format(new Date(Date.UTC(2024, 0, 1 + i))))
})

function hhmm(minutes: number) {
  return minutes >= 1440
    ? '24:00'
    : `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

function minutes(value: string) {
  const [h, m] = value.split(':').map(Number) as [number, number]
  return h * 60 + m
}

function fill(next: MyCalendar) {
  view.value = next
  const c = next.calendar
  for (let d = 1; d <= 7; d++) {
    // A window to midnight shows as 00:00 (a time input has no 24:00); saving turns it back.
    weekly[d] = (c?.weekly[String(d)] ?? []).map(([s, e]) => ({
      start: hhmm(s),
      end: e >= 1440 ? '00:00' : hhmm(e),
    }))
  }
  if (c) {
    Object.assign(rules, {
      timeZone: c.timeZone,
      slotMinutes: c.slotMinutes,
      bufferMinutes: c.bufferMinutes,
      defaultTripHours: c.defaultTripHours,
      minNoticeHours: c.minNoticeHours,
      maxDaysAhead: c.maxDaysAhead,
    })
  }
}

async function load() {
  loading.value = true
  try {
    fill(await api.myCalendar())
  } catch (err) {
    reportApiError(err, t('calendar.page.load_failed'))
  } finally {
    loading.value = false
  }
}

function addWindow(day: number) {
  const windows = weekly[day]!
  const last = windows[windows.length - 1]
  weekly[day]!.push(
    last
      ? { start: last.end, end: hhmm(Math.min(1440, minutes(last.end) + 240)) }
      : { start: '09:00', end: '13:00' },
  )
}

function copyMondayToWeekdays() {
  for (let d = 2; d <= 5; d++) weekly[d] = weekly[1]!.map((w) => ({ ...w }))
}

/** The profile's hours (`hour_of_day`) on every day — a place to start. */
function useProfileHours() {
  const windows = (view.value?.suggestedHours ?? []).map(([s, e]) => ({
    start: hhmm(s),
    end: hhmm(e),
  }))
  for (let d = 1; d <= 7; d++) weekly[d] = windows.map((w) => ({ ...w }))
}

function pin(err: unknown) {
  if (err instanceof ApiError && err.field) {
    fieldError.field = err.field
    fieldError.message = err.message
    return true
  }
  return false
}

async function save() {
  if (saving.value) return
  fieldError.field = ''
  const placement = anchorTo('element', formRef.value)
  saving.value = true
  try {
    fill(
      await api.saveCalendar({
        ...rules,
        weekly: Object.entries(weekly).flatMap(([day, windows]) =>
          windows.map((w) => ({
            weekday: Number(day),
            start: minutes(w.start),
            end: minutes(w.end === '00:00' ? '24:00' : w.end),
          })),
        ),
      }),
    )
    messages.success(t('calendar.page.saved'), placement)
  } catch (err) {
    if (!pin(err)) reportApiError(err, t('calendar.page.save_failed'), placement)
  } finally {
    saving.value = false
  }
}

async function addException() {
  fieldError.field = ''
  try {
    fill(
      await api.addDayOff({
        day: exception.day,
        kind: exception.kind,
        ...(exception.kind === 'extra' || !exception.allDay
          ? { start: minutes(exception.start), end: minutes(exception.end) }
          : {}),
        note: exception.note,
      }),
    )
    exception.note = ''
    messages.success(t('calendar.exceptions.added'))
  } catch (err) {
    if (!pin(err)) reportApiError(err, t('calendar.page.save_failed'))
  }
}

async function removeException(item: CalendarException) {
  try {
    fill(await api.removeDayOff(item.id))
  } catch (err) {
    reportApiError(err, t('calendar.page.save_failed'))
  }
}

function dateLabel(day: string) {
  const [y, m, d] = day.split('-').map(Number) as [number, number, number]
  return new Intl.DateTimeFormat(currentLanguage.value, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
  }).format(new Date(Date.UTC(y, m - 1, d)))
}

/** A booking's day and hours in the calendar's own zone. */
function bookingLabel(start: string, hours: number | null) {
  const zone = rules.timeZone
  const from = new Date(start)
  const day = new Intl.DateTimeFormat(currentLanguage.value, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    timeZone: zone,
  }).format(from)
  const time = (d: Date) =>
    new Intl.DateTimeFormat(currentLanguage.value, {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: zone,
    }).format(d)
  const length = hours ?? rules.defaultTripHours
  return `${day} · ${time(from)}–${time(new Date(from.getTime() + length * 3_600_000))}`
}

function exceptionHours(item: CalendarException) {
  return item.start === null
    ? t('calendar.exceptions.all_day')
    : `${hhmm(item.start)}–${hhmm(item.end ?? 1440)}`
}

onMounted(() => {
  if (isGuide.value) void load()
  else loading.value = false
})
</script>

<template>
  <section class="section shell calendar-page">
    <SectionTitle
      :eyebrow="t('calendar.page.eyebrow')"
      :heading="t('calendar.page.title')"
      :lead="t('calendar.page.lead')"
    />

    <PanelCard v-if="!isGuide">
      <p>{{ t('calendar.page.guides_only') }}</p>
    </PanelCard>
    <p v-else-if="loading" aria-busy="true" class="muted">{{ t('calendar.page.loading') }}</p>

    <template v-else>
      <p v-if="!hasCalendar" class="setup" data-test="no-calendar" role="status">
        {{ t('calendar.page.not_set_up') }}
        <button
          v-if="view?.suggestedHours.length"
          type="button"
          class="soft-btn"
          data-test="use-profile-hours"
          @click="useProfileHours"
        >
          {{ t('calendar.page.use_profile_hours') }}
        </button>
      </p>

      <form ref="formRef" class="calendar-form" data-test="calendar-form" @submit.prevent="save">
        <PanelCard :title="t('calendar.weekly.title')" :subtitle="t('calendar.weekly.hint')">
          <div class="week">
            <div v-for="day in 7" :key="day" class="day-row" :data-weekday="day">
              <strong class="day-name">{{ weekdayNames[day - 1] }}</strong>
              <div class="windows">
                <span v-if="!weekly[day]!.length" class="muted tiny">{{
                  t('calendar.weekly.not_working')
                }}</span>
                <span v-for="(w, i) in weekly[day]" :key="i" class="window">
                  <input
                    v-model="w.start"
                    type="time"
                    step="300"
                    required
                    :aria-label="t('calendar.weekly.from_label', { day: weekdayNames[day - 1] })"
                  />
                  <span aria-hidden="true">–</span>
                  <input
                    v-model="w.end"
                    type="time"
                    step="300"
                    required
                    :aria-label="t('calendar.weekly.to_label', { day: weekdayNames[day - 1] })"
                  />
                  <button
                    type="button"
                    class="icon-btn"
                    :aria-label="t('calendar.weekly.remove', { from: w.start, to: w.end })"
                    @click="weekly[day]!.splice(i, 1)"
                  >
                    <span aria-hidden="true">✕</span>
                  </button>
                </span>
                <button
                  type="button"
                  class="soft-btn small"
                  :data-test="`add-${day}`"
                  @click="addWindow(day)"
                >
                  <span aria-hidden="true">+</span> {{ t('calendar.weekly.add') }}
                </button>
              </div>
            </div>
          </div>
          <button
            type="button"
            class="link-btn"
            data-test="copy-monday"
            @click="copyMondayToWeekdays"
          >
            {{ t('calendar.weekly.copy_monday') }}
          </button>
          <small
            v-if="fieldError.field === 'weekly'"
            class="field-error"
            data-test="weekly-error"
            >{{ fieldError.message }}</small
          >
        </PanelCard>

        <PanelCard :title="t('calendar.rules.title')">
          <div class="rules">
            <label>
              {{ t('calendar.rules.time_zone') }}
              <select
                v-model="rules.timeZone"
                data-test="time-zone"
                :aria-invalid="fieldError.field === 'timeZone' ? 'true' : undefined"
              >
                <option v-for="zone in zones" :key="zone" :value="zone">
                  {{ zone.replace(/_/g, ' ') }}
                </option>
              </select>
              <small v-if="fieldError.field === 'timeZone'" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label>
              {{ t('calendar.rules.slot') }}
              <select v-model.number="rules.slotMinutes">
                <option v-for="m in [15, 30, 60]" :key="m" :value="m">
                  {{ t('calendar.rules.minutes', { minutes: m }) }}
                </option>
              </select>
            </label>
            <label>
              {{ t('calendar.rules.default_length') }}
              <select v-model.number="rules.defaultTripHours">
                <option v-for="h in LENGTHS" :key="h" :value="h">
                  {{ t('common.slot_picker.hours', { hours: h }) }}
                </option>
              </select>
            </label>
            <label>
              {{ t('calendar.rules.buffer') }}
              <input
                v-model.number="rules.bufferMinutes"
                type="number"
                min="0"
                max="240"
                step="5"
              />
            </label>
            <label>
              {{ t('calendar.rules.notice') }}
              <input v-model.number="rules.minNoticeHours" type="number" min="0" max="336" />
            </label>
            <label>
              {{ t('calendar.rules.ahead') }}
              <input v-model.number="rules.maxDaysAhead" type="number" min="1" max="365" />
            </label>
          </div>
        </PanelCard>

        <div class="actions">
          <button
            type="submit"
            class="accept"
            :aria-busy="saving"
            :disabled="saving"
            data-test="save-calendar"
          >
            {{ t('calendar.page.save') }}
          </button>
        </div>
      </form>

      <PanelCard :title="t('calendar.exceptions.title')" :subtitle="t('calendar.exceptions.hint')">
        <ul class="exceptions" data-test="exceptions">
          <li v-for="item in view?.calendar?.daysOff ?? []" :key="item.id">
            <strong>{{ dateLabel(item.day) }}</strong>
            ·
            {{
              item.kind === 'off'
                ? t('calendar.exceptions.kind_off')
                : t('calendar.exceptions.kind_extra')
            }}
            · {{ exceptionHours(item) }}
            <span v-if="item.note" class="muted"> — {{ item.note }}</span>
            <button type="button" class="link-btn" @click="removeException(item)">
              {{ t('calendar.exceptions.remove') }}
            </button>
          </li>
          <li v-if="!view?.calendar?.daysOff.length" class="muted">
            {{ t('calendar.exceptions.none') }}
          </li>
        </ul>
        <form
          v-if="hasCalendar"
          class="exception-form"
          data-test="exception-form"
          @submit.prevent="addException"
        >
          <label>
            {{ t('calendar.exceptions.day') }}
            <input
              v-model="exception.day"
              type="date"
              :min="today"
              required
              :aria-invalid="fieldError.field === 'day' ? 'true' : undefined"
            />
          </label>
          <label>
            {{ t('calendar.exceptions.kind') }}
            <select v-model="exception.kind">
              <option value="off">{{ t('calendar.exceptions.kind_off') }}</option>
              <option value="extra">{{ t('calendar.exceptions.kind_extra') }}</option>
            </select>
          </label>
          <label v-if="exception.kind === 'off'" class="check">
            <input v-model="exception.allDay" type="checkbox" />
            {{ t('calendar.exceptions.all_day') }}
          </label>
          <template v-if="exception.kind === 'extra' || !exception.allDay">
            <label>
              {{ t('calendar.exceptions.from') }}
              <input v-model="exception.start" type="time" step="300" required />
            </label>
            <label>
              {{ t('calendar.exceptions.to') }}
              <input v-model="exception.end" type="time" step="300" required />
            </label>
          </template>
          <label class="grow">
            {{ t('calendar.exceptions.note') }}
            <input v-model="exception.note" type="text" maxlength="120" />
          </label>
          <button type="submit" class="soft-btn">{{ t('calendar.exceptions.add') }}</button>
          <small
            v-if="['day', 'start', 'kind', 'note'].includes(fieldError.field)"
            class="field-error"
            >{{ fieldError.message }}</small
          >
        </form>
        <p v-else class="tiny muted">{{ t('calendar.exceptions.save_first') }}</p>
      </PanelCard>

      <PanelCard :title="t('calendar.upcoming.title')">
        <ul class="upcoming" data-test="upcoming">
          <li v-for="b in view?.bookings ?? []" :key="b.id" :class="b.status">
            <strong>{{ bookingLabel(b.start, b.hours) }}</strong>
            · {{ b.guest.name }}
            <span class="pill">{{
              b.status === 'pending'
                ? t('calendar.upcoming.pending')
                : t('calendar.upcoming.confirmed')
            }}</span>
          </li>
          <li v-if="!view?.bookings.length" class="muted">{{ t('calendar.upcoming.none') }}</li>
        </ul>
      </PanelCard>
    </template>
  </section>
</template>

<style scoped lang="scss">
.calendar-page {
  display: grid;
  gap: 1.25rem;
}

.calendar-form {
  display: grid;
  gap: 1.25rem;
}

.setup {
  padding: 0.75rem 1rem;
  border-radius: var(--pico-border-radius);
  background: color-mix(in srgb, var(--pico-primary-background) 12%, transparent);
}

.setup .soft-btn {
  margin: 0.5rem 0 0;
  display: block;
}

.week {
  display: grid;
  gap: 0.5rem;
}

.day-row {
  display: grid;
  grid-template-columns: 7.5rem 1fr;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.windows {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.75rem;
}

.window {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;

  input {
    width: 7rem;
    margin: 0;
    padding: 0.25rem 0.4rem;
  }
}

.icon-btn {
  width: auto;
  margin: 0;
  padding: 0.15rem 0.45rem;
  background: transparent;
  color: inherit;
  border: 1px solid var(--pico-muted-border-color);
  font-size: 0.8rem;
}

.small {
  width: auto;
  margin: 0;
  padding: 0.2rem 0.6rem;
  font-size: 0.85rem;
}

.link-btn {
  width: auto;
  margin: 0.5rem 0 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  text-decoration: underline;
  font-size: 0.9rem;
}

.rules {
  display: grid;
  gap: 0.25rem 1rem;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.actions button {
  width: auto;
}

.exceptions,
.upcoming {
  list-style: none;
  padding: 0;
}

.exceptions li,
.upcoming li {
  list-style: none;
  padding: 0.35rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.exceptions .link-btn {
  margin: 0 0 0 0.5rem;
}

.exception-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem 0.75rem;

  label {
    margin: 0;
  }

  input,
  select {
    margin: 0;
  }

  .grow {
    flex: 1 1 12rem;
  }

  button {
    width: auto;
    margin: 0;
  }
}

.check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.pill {
  margin-left: 0.4rem;
  padding: 0 0.5rem;
  border-radius: 1rem;
  font-size: 0.75rem;
  background: var(--pico-muted-border-color);
}

.upcoming .confirmed .pill {
  background: #1f7a4d;
  color: #fff;
}

.field-error {
  display: block;
  color: var(--pico-del-color);
}

@media (max-width: 600px) {
  .day-row {
    grid-template-columns: 1fr;
  }
}
</style>
