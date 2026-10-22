<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import MapPinLinks from '@/components/MapPinLinks.vue'
import SlotPicker from '@/components/SlotPicker.vue'
import MeetingPinField from '@/components/MeetingPinField.vue'
import PillBadge from '@/components/PillBadge.vue'
import ProtectedNotice from '@/components/ProtectedNotice.vue'
import { useCurrentPosition, type DevicePosition } from '@/composables/useCurrentPosition'
import { t } from '@/i18n'
import {
  useApi,
  type BookingOp,
  type BookingView,
  type MapPin,
  type TripPresence,
} from '@/plugins/api'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { formatHours, formatMoney, formatWhen, tripEnd, utcDate } from '@/utils/bookingTime'
import { percent } from '@/utils/levels'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Everything about one booking, for either side — see `Bookings.php` for the
 * rules. Lives under a row on `MatchesPage.vue`'s Matched tab (no booking yet,
 * or a pending one) and under a trip card on `TripsPage.vue` (confirmed or
 * finished), and emits `changed` after every successful step so the page
 * re-fetches (badges and the next step come from the server).
 *
 *   no booking yet  guest: Pay (meeting time, optional trip length and place)
 *   pending         guest: Withdraw payment · guide: Accept / Decline
 *   confirmed       guest: Request cancellation (reason), or Cancel trip if free
 *                   guide: Cancel booking, or Approve / Refuse a guest's request
 *                   guide: Start the trip — enabled from 15 minutes before the
 *                   meeting while, when both phones report where they are,
 *                   they're within 50 m (or both confirmed the meetup when GPS
 *                   is too vague); both apps report it every 30 s meanwhile
 *                   guest, once started: Cancel trip (a reason) — the guide
 *                   approves (full refund) or our team decides
 *                   both, once started: Mark trip finished → the other side
 *                   confirms, unless the trip finishes on its own
 *   finished        Leave a review; the guest can book again (`allowBooking`)
 *
 * While a booking is open, either side can set or change the meeting place:
 * a description and/or a pin dropped where they're standing (like chat's
 * "Share my location"), which the other side opens in their own maps app.
 */
const props = withDefaults(
  defineProps<{
    row: BookingView
    isGuest: boolean
    name: string
    /** Offer Pay / Book again — `TripsPage.vue` only allows it on the pair's latest trip. */
    allowBooking?: boolean
    /** The "where things stand" line — off on a trip card, which shows the date and price itself. */
    summary?: boolean
    /**
     * Render the Pay / Book again button here. Off on a trip card, which puts
     * "Book again" in its always-visible header and calls `startBooking()`.
     */
    bookButton?: boolean
  }>(),
  { allowBooking: true, summary: true, bookButton: true },
)

const emit = defineEmits<{ changed: [] }>()

const api = useApi()
const messages = useMessagesStore()

const root = ref<HTMLElement | null>(null)
const busy = ref(false)

type FormKind = 'pay' | 'guideCancel' | 'decline' | 'requestCancel' | 'freeCancel' | 'location'
const openForm = ref<FormKind | null>(null)
const meetingAt = ref('')
const durationHours = ref<number | ''>('')
/** With a guide's calendar: the start picked from their free times, and the trip length (`SlotPicker`). */
const slotStart = ref('')
const slotHours = ref(2)
/** The guide keeps no calendar — any future time, as before. */
const noCalendar = ref(false)
const location = ref('')
/** The meeting pin in the open form — from the device, or the booking's current one. */
const pin = ref<MapPin | null>(null)
const reason = ref('')

/** Matches `Bookings::MAX_LOCATION_LENGTH`. */
const MAX_LOCATION = 160

const pending = computed(() => props.row.booking_status === 'pending')
const confirmed = computed(
  () => props.row.booking_status === 'confirmed' && props.row.trip_status !== 'finished',
)
const finished = computed(
  () => props.row.booking_status === 'confirmed' && props.row.trip_status === 'finished',
)
const paid = computed(() => confirmed.value && props.row.payment_status === 'paid')
const free = computed(() => confirmed.value && props.row.payment_status !== 'paid')
const started = computed(() => confirmed.value && props.row.trip_status === 'started')
const notStarted = computed(() => confirmed.value && props.row.trip_status === 'pending')

const disputed = computed(() => started.value && !!props.row.disputed_at)

/** Match `Bookings::START_OPENS_SECONDS` and `NO_SHOW_DEFAULT_HOURS`. */
const START_OPENS_MS = 15 * 60 * 1000
const NO_SHOW_DEFAULT_HOURS = 3
/** How often both apps report where they are around the start. */
const PRESENCE_EVERY_MS = 30_000

const now = ref(Date.now())
const meetingDate = computed(() => utcDate(props.row.meeting_at))
const meetingPassed = computed(
  () => !!meetingDate.value && now.value >= meetingDate.value.getTime(),
)
/** From 15 minutes before the meeting until its no-show time (meeting + length). */
const aroundStart = computed(() => {
  if (!notStarted.value || !meetingDate.value) return false
  const meeting = meetingDate.value.getTime()
  const hours = props.row.booking_duration_hours ?? NO_SHOW_DEFAULT_HOURS
  return now.value >= meeting - START_OPENS_MS && now.value < meeting + hours * 3_600_000
})
/** The guide's Start button: shown for any upcoming trip, disabled until the start opens. */
const showStart = computed(() => !props.isGuest && notStarted.value)

const { locating, locate } = useCurrentPosition()
/** The server's last word on starting (`tripPresence`); null until the first answer. */
const presence = ref<TripPresence | null>(null)
const startEnabled = computed(
  () =>
    showStart.value &&
    (aroundStart.value || !meetingDate.value) &&
    presence.value?.start_ready !== false,
)
/** GPS can't tell they're together: both confirm the meetup instead. */
const askMeetup = computed(
  () => aroundStart.value && !!presence.value?.poor_gps && presence.value.meetup !== 'both',
)

const positionFields = (p: DevicePosition | null) =>
  p
    ? {
        latitude: p.latitude,
        longitude: p.longitude,
        ...(p.accuracy !== null ? { accuracy: p.accuracy } : {}),
      }
    : {}

let reporting = false
/** Tells the server where this side is (or that it can't tell), around the start. */
async function reportPresence() {
  if (!aroundStart.value || reporting || busy.value) return
  reporting = true
  try {
    const result = await locate()
    if (result === null) return
    presence.value = await api.tripPresence({
      targetId: props.row.counterpart_id,
      ...positionFields(result.ok ? result.position : null),
    })
  } catch {
    // Best effort: the server checks again when the guide presses Start.
  } finally {
    reporting = false
  }
}

let clock: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void reportPresence()
  clock = setInterval(() => {
    now.value = Date.now()
    void reportPresence()
  }, PRESENCE_EVERY_MS)
})
onBeforeUnmount(() => clearInterval(clock))
/** A guide who charges, and nothing booked right now — a first booking, or the next trip. */
const needsBooking = computed(
  () =>
    props.allowBooking &&
    props.row.active === 1 &&
    !pending.value &&
    !confirmed.value &&
    props.row.fee_amount > 0,
)
const lastDeclined = computed(() => props.row.booking_status === 'declined')
const cancelRequested = computed(
  () => paid.value && !!props.row.cancel_requested_at && !disputed.value,
)
const lateCancel = computed(
  () =>
    props.row.refund_if_cancelled !== null &&
    props.row.refund_if_cancelled > (props.row.booking_amount ?? 0),
)
const reviewLink = computed(() =>
  props.isGuest ? `/guides/${props.row.counterpart_id}` : `/visitors/${props.row.counterpart_id}`,
)

const money = formatMoney

const meetingLabel = computed(() => formatWhen(utcDate(props.row.meeting_at)))
const durationLabel = computed(() => formatHours(props.row.booking_duration_hours))
const endLabel = computed(() =>
  formatWhen(
    tripEnd(
      utcDate(props.row.started_at ?? props.row.meeting_at),
      props.row.booking_duration_hours,
    ),
  ),
)

/** `<input type="datetime-local">` wants local time without a zone. */
function localInputValue(date: Date) {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
const minMeeting = computed(() => localInputValue(new Date()))

function show(kind: FormKind) {
  openForm.value = kind
  meetingAt.value = ''
  durationHours.value = ''
  slotStart.value = ''
  noCalendar.value = false
  location.value = kind === 'location' ? (props.row.location ?? '') : ''
  pin.value = kind === 'location' ? (props.row.location_pin ?? null) : null
  reason.value = ''
}

const pinFields = () =>
  pin.value ? { latitude: pin.value.latitude, longitude: pin.value.longitude } : {}

/** Runs one server step, then closes any form, tells the page to reload, and confirms. */
async function run(step: () => Promise<unknown>, success: string, failure: string) {
  if (busy.value) return
  const placement = anchorTo('element', root.value)
  busy.value = true
  try {
    await step()
    openForm.value = null
    emit('changed')
    messages.success(success, placement)
  } catch (err) {
    reportApiError(err, failure, placement)
  } finally {
    busy.value = false
  }
}

function op(name: BookingOp, success: string, withReason = false) {
  const text = reason.value.trim()
  return run(
    () =>
      api.bookingAction({
        targetId: props.row.counterpart_id,
        op: name,
        ...(withReason && text ? { reason: text } : {}),
      }),
    success,
    t('common.booking_panel.update_failed'),
  )
}

/** The guide starts the trip, sending where they are when the device can tell. */
async function startTrip() {
  if (busy.value || locating.value) return
  const result = await locate()
  if (result === null) return
  return run(
    () =>
      api.bookingAction({
        targetId: props.row.counterpart_id,
        op: 'startTrip',
        ...positionFields(result.ok ? result.position : null),
      }),
    t('common.booking_panel.trip_started'),
    t('common.booking_panel.start_failed'),
  )
}

/** "We've met" when GPS can't tell — both confirming unlocks the guide's Start. */
async function confirmMeetup() {
  if (busy.value) return
  busy.value = true
  try {
    presence.value = await api.tripPresence({ targetId: props.row.counterpart_id, meetup: true })
  } catch (err) {
    reportApiError(err, t('common.booking_panel.update_failed'), anchorTo('element', root.value))
  } finally {
    busy.value = false
  }
}

function pay() {
  // A guide with a calendar: the start picked from their free times.
  const chosen = noCalendar.value ? meetingAt.value : slotStart.value
  const when = new Date(chosen)
  if (!chosen || Number.isNaN(when.getTime())) {
    messages.error(
      t(
        noCalendar.value
          ? 'common.booking_panel.meeting_required'
          : 'common.slot_picker.choose_time',
      ),
      anchorTo('element', root.value),
    )
    return
  }
  const hours = !noCalendar.value
    ? slotHours.value
    : durationHours.value === ''
      ? undefined
      : Number(durationHours.value)
  const place = location.value.trim()
  return run(
    () =>
      api.payGuideFee({
        targetId: props.row.counterpart_id,
        meetingAt: when.toISOString(),
        ...(hours !== undefined ? { durationHours: hours } : {}),
        ...(place ? { location: place } : {}),
        ...pinFields(),
      }),
    t('common.booking_panel.paid', {
      amount: money(props.row.fee_amount),
      name: props.name,
    }),
    t('common.booking_panel.pay_failed'),
  )
}

function saveLocation() {
  const place = location.value.trim()
  return run(
    () =>
      api.bookingAction({
        targetId: props.row.counterpart_id,
        op: 'setLocation',
        location: place,
        ...pinFields(),
      }),
    place || pin.value
      ? t('common.booking_panel.location_saved', { name: props.name })
      : t('common.booking_panel.location_removed'),
    t('common.booking_panel.location_failed'),
  )
}

/** Opens the pay form — for a page that shows its own "Book again" button (`bookButton: false`). */
function startBooking() {
  if (needsBooking.value && props.isGuest) show('pay')
}
defineExpose({ startBooking })

/** A free trip has nothing to refund, so the guest simply unmatches — `Selections::revoke()` cancels it. */
function freeCancel() {
  return run(
    () => api.revokeSelection({ targetId: props.row.counterpart_id }),
    t('common.booking_panel.trip_cancelled', { name: props.name }),
    t('common.booking_panel.trip_cancel_failed'),
  )
}

function requestCancellation() {
  if (!reason.value.trim()) {
    messages.error(
      t('common.booking_panel.reason_required', { name: props.name }),
      anchorTo('input', root.value?.querySelector('textarea')),
    )
    return
  }
  return op(
    'requestCancellation',
    t('common.booking_panel.cancel_requested', { name: props.name }),
    true,
  )
}

function guideCancel() {
  const text = reason.value.trim()
  return run(
    () =>
      api.cancelBooking({ targetId: props.row.counterpart_id, ...(text ? { reason: text } : {}) }),
    paid.value
      ? t('common.booking_panel.booking_cancelled_refunded', {
          name: props.name,
          amount: money(props.row.refund_if_cancelled ?? props.row.booking_amount),
        })
      : t('common.booking_panel.booking_cancelled', { name: props.name }),
    t('common.booking_panel.booking_cancel_failed'),
  )
}
</script>

<template>
  <div ref="root" class="booking">
    <!-- Where things stand -->
    <template v-if="summary">
      <p v-if="pending" class="booking-line">
        <PillBadge tone="neutral">{{
          isGuest
            ? t('common.booking_panel.waiting_confirm', { name })
            : t('common.booking_panel.booking_request')
        }}</PillBadge>
        <span class="tiny muted">
          {{
            t('common.booking_panel.paid_on', {
              amount: money(row.booking_amount),
              when: meetingLabel,
            })
          }}<template v-if="durationLabel"> · {{ durationLabel }}</template
          ><template v-if="row.location"> · {{ row.location }}</template>
        </span>
        <MapPinLinks
          v-if="row.location_pin"
          :latitude="row.location_pin.latitude"
          :longitude="row.location_pin.longitude"
          data-test="summary-pin"
        />
      </p>
      <p v-else-if="paid" class="booking-line">
        <PillBadge>{{
          t('common.booking_panel.paid_badge', { amount: money(row.booking_amount) })
        }}</PillBadge>
        <PillBadge v-if="started" tone="neutral">{{
          t('common.booking_panel.in_progress')
        }}</PillBadge>
        <span v-if="meetingLabel" class="tiny muted">
          {{ meetingLabel }}<template v-if="durationLabel"> · {{ durationLabel }}</template>
        </span>
      </p>
      <p v-else-if="confirmed" class="booking-line">
        <PillBadge tone="neutral">{{ t('common.booking_panel.free_guide') }}</PillBadge>
      </p>
      <p v-else-if="finished" class="booking-line">
        <PillBadge tone="neutral">{{ t('common.booking_panel.trip_finished') }}</PillBadge>
        <RouterLink :to="reviewLink" class="tiny"
          >{{ t('common.booking_panel.leave_review', { name }) }}
          <span class="rtl-flip" aria-hidden="true">→</span></RouterLink
        >
      </p>
    </template>

    <!-- What happens next -->
    <!-- Outside the note chain below: shown alongside whichever note applies. -->
    <p
      v-if="
        !isGuest &&
        row.guide_payout != null &&
        row.commission_rate != null &&
        row.payment_status === 'paid'
      "
      class="tiny muted note"
      data-test="guide-payout"
    >
      {{
        row.commission_rate === 0
          ? t('common.booking_panel.guide_payout_no_fee', { payout: money(row.guide_payout) })
          : t('common.booking_panel.guide_payout', {
              payout: money(row.guide_payout),
              rate: percent(row.commission_rate),
            })
      }}
    </p>
    <p v-if="needsBooking && isGuest" class="tiny muted note">
      <template v-if="lastDeclined"
        >{{ t('common.booking_panel.declined_before', { name }) }}
      </template>
      {{
        finished
          ? t('common.booking_panel.pay_to_book_again', { name, amount: money(row.fee_amount) })
          : t('common.booking_panel.pay_to_request_date', { name, amount: money(row.fee_amount) })
      }}
    </p>
    <p v-else-if="needsBooking" class="tiny muted note">
      {{ t('common.booking_panel.guide_awaiting_payment', { amount: money(row.fee_amount) }) }}
    </p>
    <p v-else-if="pending && isGuest" class="tiny muted note">
      {{ t('common.booking_panel.guest_pending_note', { name }) }}
    </p>
    <p v-else-if="pending" class="tiny muted note">
      {{ t('common.booking_panel.guide_pending_note') }}
    </p>
    <div v-else-if="cancelRequested" class="request">
      <p v-if="isGuest" class="tiny muted">
        {{
          t('common.booking_panel.guest_cancel_requested', {
            name,
            amount: money(row.refund_if_guest_cancels),
          })
        }}
      </p>
      <p v-else>
        <strong>{{
          t('common.booking_panel.cancel_requested_by', { name, reason: row.cancel_request_reason })
        }}</strong
        ><br />
        <span class="tiny muted">{{
          t(
            started
              ? 'common.booking_panel.approve_refund_note_started'
              : 'common.booking_panel.approve_refund_note',
            { amount: money(row.refund_if_guest_cancels) },
          )
        }}</span>
      </p>
    </div>

    <div v-if="askMeetup" class="note meetup" data-test="meetup">
      <p v-if="presence?.meetup === 'me'" class="tiny muted">
        {{ t('common.booking_panel.meetup_waiting', { name }) }}
      </p>
      <template v-else>
        <p class="tiny">{{ t('common.booking_panel.meetup_prompt') }}</p>
        <button
          type="button"
          class="accept"
          :aria-busy="busy"
          :disabled="busy"
          data-test="confirm-meetup"
          @click="confirmMeetup"
        >
          {{ t('common.booking_panel.confirm_meetup') }}
        </button>
      </template>
    </div>
    <p v-if="disputed" class="note" data-test="dispute-open">
      {{ t('common.booking_panel.dispute_open') }}
    </p>
    <template v-else-if="started && !cancelRequested">
      <p v-if="row.auto_finish && endLabel" class="tiny muted note">
        {{ t('common.booking_panel.auto_finish_note', { when: endLabel }) }}
      </p>
      <p v-else-if="row.finish_requested_by === 'me'" class="tiny muted note">
        {{ t('common.booking_panel.waiting_finish', { name }) }}
      </p>
      <p v-else-if="row.finish_requested_by === 'them'" class="note">
        {{ t('common.booking_panel.they_say_finished', { name }) }}
      </p>
    </template>

    <!-- Actions -->
    <div class="booking-actions">
      <button
        v-if="bookButton && needsBooking && isGuest && openForm !== 'pay'"
        type="button"
        class="accept"
        :disabled="busy"
        @click="show('pay')"
      >
        {{
          finished
            ? t('common.booking_panel.book_again', { amount: money(row.fee_amount) })
            : t('common.booking_panel.pay', { amount: money(row.fee_amount) })
        }}
      </button>

      <template v-if="pending && isGuest">
        <button
          type="button"
          class="soft-btn"
          :aria-busy="busy"
          :disabled="busy"
          @click="
            op(
              'withdraw',
              t('common.booking_panel.withdrawn', { amount: money(row.booking_amount) }),
            )
          "
        >
          {{ t('common.booking_panel.withdraw_payment') }}
        </button>
      </template>
      <template v-if="pending && !isGuest && openForm !== 'decline'">
        <button
          type="button"
          class="accept"
          :aria-busy="busy"
          :disabled="busy"
          @click="op('accept', t('common.booking_panel.accepted', { name }))"
        >
          <span aria-hidden="true">✓</span> {{ t('common.booking_panel.accept') }}
        </button>
        <button type="button" class="reject" :disabled="busy" @click="show('decline')">
          {{ t('common.booking_panel.decline') }}
        </button>
      </template>

      <template v-if="paid && isGuest">
        <button
          v-if="cancelRequested"
          type="button"
          class="soft-btn"
          :aria-busy="busy"
          :disabled="busy"
          @click="op('withdrawCancellation', t('common.booking_panel.cancel_request_withdrawn'))"
        >
          {{ t('common.booking_panel.keep_my_booking') }}
        </button>
        <button
          v-else-if="!started && !meetingPassed && openForm !== 'requestCancel'"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="show('requestCancel')"
        >
          {{ t('common.booking_panel.request_cancellation') }}
        </button>
        <button
          v-else-if="started && !disputed && openForm !== 'requestCancel'"
          type="button"
          class="reject"
          :disabled="busy"
          data-test="cancel-started"
          @click="show('requestCancel')"
        >
          {{ t('common.booking_panel.cancel_trip') }}
        </button>
      </template>
      <button
        v-if="free && isGuest && openForm !== 'freeCancel'"
        type="button"
        class="reject"
        :disabled="busy"
        @click="show('freeCancel')"
      >
        {{ t('common.booking_panel.cancel_trip') }}
      </button>
      <template v-if="confirmed && !isGuest && !disputed">
        <template v-if="cancelRequested">
          <button
            type="button"
            class="accept"
            :aria-busy="busy"
            :disabled="busy"
            @click="
              op(
                'approveCancellation',
                t('common.booking_panel.cancel_approved', {
                  name,
                  amount: money(row.refund_if_guest_cancels),
                }),
              )
            "
          >
            {{ t('common.booking_panel.approve_cancellation') }}
          </button>
          <button
            type="button"
            class="soft-btn"
            :disabled="busy"
            @click="
              op(
                'refuseCancellation',
                started
                  ? t('common.booking_panel.dispute_raised')
                  : t('common.booking_panel.cancel_refused', { name }),
              )
            "
          >
            {{ t('common.booking_panel.refuse') }}
          </button>
        </template>
        <button
          v-else-if="openForm !== 'guideCancel'"
          type="button"
          class="reject"
          :disabled="busy"
          @click="show('guideCancel')"
        >
          {{ t('common.booking_panel.cancel_booking') }}
        </button>
      </template>

      <button
        v-if="showStart"
        type="button"
        class="accept"
        :aria-busy="busy"
        :disabled="busy || !startEnabled"
        data-test="start-trip"
        @click="startTrip"
      >
        {{ t('common.booking_panel.start_trip') }}
      </button>

      <template v-if="started && !row.auto_finish && !cancelRequested && !disputed">
        <button
          v-if="row.finish_requested_by === null"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="op('requestFinish', t('common.booking_panel.finish_requested', { name }))"
        >
          {{ t('common.booking_panel.mark_finished') }}
        </button>
        <button
          v-else-if="row.finish_requested_by === 'me'"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="op('dismissFinish', t('common.booking_panel.finish_withdrawn'))"
        >
          {{ t('common.booking_panel.undo') }}
        </button>
        <template v-else>
          <button
            type="button"
            class="accept"
            :aria-busy="busy"
            :disabled="busy"
            @click="op('confirmFinish', t('common.booking_panel.finish_confirmed', { name }))"
          >
            {{ t('common.booking_panel.confirm_finished') }}
          </button>
          <button
            type="button"
            class="soft-btn"
            :disabled="busy"
            @click="op('dismissFinish', t('common.booking_panel.finish_refused', { name }))"
          >
            {{ t('common.booking_panel.not_yet') }}
          </button>
        </template>
      </template>

      <button
        v-if="(pending || confirmed) && openForm !== 'location'"
        type="button"
        class="soft-btn"
        :disabled="busy"
        @click="show('location')"
      >
        {{
          row.location
            ? t('common.booking_panel.change_location')
            : t('common.booking_panel.set_location')
        }}
      </button>
    </div>

    <!-- Forms -->
    <form
      v-if="openForm === 'pay'"
      class="inline-form"
      :aria-label="t('common.booking_panel.pay_form_label', { name })"
      @submit.prevent="pay"
    >
      <SlotPicker
        v-if="!noCalendar"
        v-model="slotStart"
        v-model:hours="slotHours"
        :guide-id="row.counterpart_id"
        :name="name"
        @unavailable="noCalendar = true"
      />
      <div v-else class="fields">
        <label>
          {{ t('common.booking_panel.meeting_question') }}
          <input v-model="meetingAt" type="datetime-local" :min="minMeeting" required />
        </label>
        <label>
          {{ t('common.booking_panel.trip_length') }}
          <span class="muted">{{ t('common.booking_panel.optional') }}</span>
          <input
            v-model.number="durationHours"
            type="number"
            min="0.5"
            max="24"
            step="0.5"
            inputmode="decimal"
          />
        </label>
      </div>
      <label>
        {{ t('common.booking_panel.where_to_meet') }}
        <span class="muted">{{ t('common.booking_panel.optional') }}</span>
        <input
          v-model="location"
          type="text"
          :maxlength="MAX_LOCATION"
          :placeholder="t('common.booking_panel.location_placeholder')"
        />
      </label>
      <MeetingPinField v-model="pin" />
      <p class="tiny muted">
        {{
          t('common.booking_panel.pay_note', {
            amount: money(row.fee_amount),
            name,
          })
        }}
      </p>
      <ProtectedNotice kind="fee" />
      <div class="form-actions">
        <button type="submit" class="accept" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.confirm_payment') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.not_now') }}
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'decline'"
      class="inline-form"
      :aria-label="t('common.booking_panel.decline_form_label', { name })"
      @submit.prevent="
        op(
          'decline',
          t('common.booking_panel.declined', { name, amount: money(row.booking_amount) }),
          true,
        )
      "
    >
      <p>
        {{ t('common.booking_panel.decline_note', { name, amount: money(row.booking_amount) }) }}
      </p>
      <label>
        {{ t('common.booking_panel.reason') }}
        <span class="muted">{{ t('common.booking_panel.reason_hint') }}</span>
        <textarea v-model="reason" rows="2" maxlength="256" />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.decline_booking') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.back') }}
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'requestCancel'"
      class="inline-form"
      :aria-label="t('common.booking_panel.request_cancel_form_label', { name })"
      @submit.prevent="requestCancellation"
    >
      <p>
        {{
          t(
            started
              ? 'common.booking_panel.request_cancel_note_started'
              : 'common.booking_panel.request_cancel_note',
            { name, amount: money(row.refund_if_guest_cancels) },
          )
        }}
      </p>
      <label>
        {{ t('common.booking_panel.cancel_reason_question') }}
        <textarea v-model="reason" rows="2" maxlength="256" required />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.send_request') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.back') }}
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'location'"
      class="inline-form"
      :aria-label="t('common.booking_panel.location_form_label', { name })"
      @submit.prevent="saveLocation"
    >
      <label>
        {{ t('common.booking_panel.where_to_meet') }}
        <input
          v-model="location"
          type="text"
          :maxlength="MAX_LOCATION"
          :placeholder="t('common.booking_panel.location_placeholder')"
        />
      </label>
      <MeetingPinField v-model="pin" />
      <p class="tiny muted">
        {{ t('common.booking_panel.location_note', { name }) }}
        {{ t('common.booking_panel.pin_note', { name }) }}
      </p>
      <div class="form-actions">
        <button type="submit" class="accept" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.save') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.back') }}
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'freeCancel'"
      class="inline-form"
      :aria-label="t('common.booking_panel.free_cancel_form_label', { name })"
      @submit.prevent="freeCancel"
    >
      <p class="cancel-summary">
        {{ t('common.booking_panel.free_cancel_note', { name }) }}
      </p>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.cancel_trip') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.keep_trip') }}
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'guideCancel'"
      class="inline-form"
      :aria-label="t('common.booking_panel.guide_cancel_form_label', { name })"
      @submit.prevent="guideCancel"
    >
      <p class="cancel-summary">
        <template v-if="paid">{{
          lateCancel
            ? t('common.booking_panel.guide_cancel_refund_late', {
                name,
                amount: money(row.refund_if_cancelled ?? row.booking_amount),
              })
            : t('common.booking_panel.guide_cancel_refund', {
                name,
                amount: money(row.refund_if_cancelled ?? row.booking_amount),
              })
        }}</template>
        <template v-else>{{ t('common.booking_panel.guide_cancel_free', { name }) }}</template>
        {{ t('common.booking_panel.guide_cancel_note') }}
      </p>
      <label>
        {{ t('common.booking_panel.reason') }}
        <span class="muted">{{ t('common.booking_panel.reason_hint') }}</span>
        <textarea v-model="reason" rows="2" maxlength="256" />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          {{ t('common.booking_panel.cancel_booking') }}
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          {{ t('common.booking_panel.keep_booking') }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped lang="scss">
.booking {
  grid-column: 1 / -1;
  min-width: 0;
}

.booking-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.6rem;
  margin: 0 0 0.25rem;
}

.note {
  margin: 0.35rem 0 0;
}

.request {
  margin: 0.4rem 0 0;
  padding: 0.6rem 0.75rem;
  border-left: 3px solid var(--rg-orange-300);
  background: var(--rg-orange-50);
  border-radius: 0.5rem;

  p {
    margin: 0;
    font-size: 0.9rem;
    overflow-wrap: anywhere;
  }
}

.booking-actions,
.form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;

  > * {
    width: auto;
    margin: 0;
    padding: 0.5rem 0.9rem;
    font-size: 0.9rem;
  }
}

.booking-actions:not(:empty) {
  margin-top: 0.6rem;
}

.inline-form {
  margin: 0.6rem 0 0;
  padding: 0.9rem;
  border: 1px solid var(--rg-line);
  border-radius: 0.75rem;
  background: var(--rg-orange-50);

  label {
    font-size: 0.9rem;
    font-weight: 600;
  }

  input,
  textarea {
    margin: 0.35rem 0 0.5rem;
    font-weight: 400;
  }

  p {
    margin: 0 0 0.6rem;
  }
}

.fields {
  display: grid;
  gap: 0 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
}

.cancel-summary {
  font-size: 0.95rem;
}

// Same soft green/red pair as DecisionRow.vue.
.accept {
  background: var(--rg-green-soft);
  border: 1px solid var(--rg-green-border);
  color: var(--rg-green);
  font-weight: 600;

  &:hover:not(:disabled),
  &:focus-visible {
    background: #dcefe4;
    border-color: var(--rg-green);
    color: var(--rg-green);
  }
}

.reject {
  background: var(--rg-red-soft);
  border: 1px solid var(--rg-red-border);
  color: var(--rg-red);
  font-weight: 600;

  &:hover:not(:disabled),
  &:focus-visible {
    background: #ffe4e0;
    border-color: var(--rg-red);
    color: var(--rg-red);
  }
}
</style>
