<script setup lang="ts">
import { computed, ref } from 'vue'
import PillBadge from '@/components/PillBadge.vue'
import { useApi, type BookingOp, type BookingView } from '@/plugins/api'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { formatHours, formatMoney, formatWhen, tripEnd, utcDate } from '@/utils/bookingTime'
import { reportApiError } from '@/utils/reportApiError'

/**
 * Everything about one booking, for either side — see `Bookings.php` for the
 * rules. Lives under a row on `MatchesPage.vue`'s Matched tab (no booking yet,
 * or a pending one) and under a trip card on `TripsPage.vue` (confirmed or
 * finished), and emits `changed` after every successful step so the page
 * re-fetches (contact details, badges and the next step all come from the
 * server).
 *
 *   no booking yet  guest: Pay (meeting time, optional trip length and place)
 *   pending         guest: Withdraw payment · guide: Accept / Decline
 *   confirmed       guest: Request cancellation (reason), or Cancel trip if free
 *                   guide: Cancel booking, or Approve / Refuse a guest's request
 *                   both: Mark trip finished → the other side confirms, unless
 *                   the trip finishes on its own (time + length known)
 *   finished        Leave a review; the guest can book again (`allowBooking`)
 *
 * While a booking is open, either side can set or change the meeting place.
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
  }>(),
  { allowBooking: true, summary: true },
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
const location = ref('')
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
const cancelRequested = computed(() => paid.value && !!props.row.cancel_requested_at)
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
  formatWhen(tripEnd(utcDate(props.row.meeting_at), props.row.booking_duration_hours)),
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
  location.value = kind === 'location' ? (props.row.location ?? '') : ''
  reason.value = ''
}

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
    'Could not update this booking.',
  )
}

function pay() {
  const when = new Date(meetingAt.value)
  if (!meetingAt.value || Number.isNaN(when.getTime())) {
    messages.error('Choose when you’d like to meet.', anchorTo('element', root.value))
    return
  }
  const hours = durationHours.value === '' ? undefined : Number(durationHours.value)
  const place = location.value.trim()
  return run(
    () =>
      api.payGuideFee({
        targetId: props.row.counterpart_id,
        meetingAt: when.toISOString(),
        ...(hours !== undefined ? { durationHours: hours } : {}),
        ...(place ? { location: place } : {}),
      }),
    `Payment of ${money(props.row.fee_amount)} received — ${props.name} will now confirm the date. Contact details unlock once they do.`,
    'The payment didn’t go through.',
  )
}

function saveLocation() {
  const place = location.value.trim()
  return run(
    () =>
      api.bookingAction({ targetId: props.row.counterpart_id, op: 'setLocation', location: place }),
    place ? `Meeting place saved — ${props.name} has been told.` : 'Meeting place removed.',
    'Could not save the meeting place.',
  )
}

/** A free trip has nothing to refund, so the guest simply unmatches — `Selections::revoke()` cancels it. */
function freeCancel() {
  return run(
    () => api.revokeSelection({ targetId: props.row.counterpart_id }),
    `Trip cancelled — ${props.name} has been told.`,
    'Could not cancel this trip.',
  )
}

function requestCancellation() {
  if (!reason.value.trim()) {
    messages.error(
      `Tell ${props.name} why you need to cancel.`,
      anchorTo('input', root.value?.querySelector('textarea')),
    )
    return
  }
  return op('requestCancellation', `Cancellation requested — ${props.name} will decide.`, true)
}

function guideCancel() {
  const text = reason.value.trim()
  return run(
    () =>
      api.cancelBooking({ targetId: props.row.counterpart_id, ...(text ? { reason: text } : {}) }),
    paid.value
      ? `Booking cancelled — ${props.name} has been refunded ${money(props.row.refund_if_cancelled ?? props.row.booking_amount)}.`
      : `Booking cancelled — ${props.name} has been told.`,
    'Could not cancel this booking.',
  )
}
</script>

<template>
  <div ref="root" class="booking">
    <!-- Where things stand -->
    <template v-if="summary">
      <p v-if="pending" class="booking-line">
        <PillBadge tone="neutral">{{
          isGuest ? `Waiting for ${name} to confirm` : 'Booking request'
        }}</PillBadge>
        <span class="tiny muted">
          {{ money(row.booking_amount) }} paid · {{ meetingLabel
          }}<template v-if="durationLabel"> · {{ durationLabel }}</template
          ><template v-if="row.location"> · {{ row.location }}</template>
        </span>
      </p>
      <p v-else-if="paid" class="booking-line">
        <PillBadge>Paid {{ money(row.booking_amount) }}</PillBadge>
        <PillBadge v-if="started" tone="neutral">Trip in progress</PillBadge>
        <span v-if="meetingLabel" class="tiny muted">
          {{ meetingLabel }}<template v-if="durationLabel"> · {{ durationLabel }}</template>
        </span>
      </p>
      <p v-else-if="confirmed" class="booking-line">
        <PillBadge tone="neutral">Free guide</PillBadge>
      </p>
      <p v-else-if="finished" class="booking-line">
        <PillBadge tone="neutral">Trip finished</PillBadge>
        <RouterLink :to="reviewLink" class="tiny">Leave {{ name }} a review →</RouterLink>
      </p>
    </template>

    <!-- What happens next -->
    <p v-if="needsBooking && isGuest" class="tiny muted note">
      <template v-if="lastDeclined"
        >{{ name }} couldn’t make the last time you asked for — you were refunded.
      </template>
      Pay {{ name }}’s {{ money(row.fee_amount) }} fee to
      {{ finished ? 'book another trip' : 'request a date' }}. Contact details unlock for both of
      you once {{ name }} confirms it.
    </p>
    <p v-else-if="needsBooking" class="tiny muted note">
      Contact details unlock once the visitor pays your {{ money(row.fee_amount) }} fee and you
      confirm the date.
    </p>
    <p v-else-if="pending && isGuest" class="tiny muted note">
      You can withdraw until {{ name }} confirms — you’d be refunded in full. If they don’t answer
      before the meeting time, it’s called off and refunded automatically.
    </p>
    <p v-else-if="pending" class="tiny muted note">
      Accepting commits you to this date — contact details are shared then. Declining refunds the
      visitor in full.
    </p>
    <div v-else-if="cancelRequested" class="request">
      <p v-if="isGuest" class="tiny muted">
        You asked to cancel. If {{ name }} agrees, you’re refunded
        {{ money(row.refund_if_guest_cancels) }}.
      </p>
      <p v-else>
        <strong>{{ name }} asked to cancel:</strong> “{{ row.cancel_request_reason }}”<br />
        <span class="tiny muted"
          >Approving refunds them {{ money(row.refund_if_guest_cancels) }} (the fee minus 5%).</span
        >
      </p>
    </div>

    <template v-if="confirmed">
      <p v-if="row.auto_finish && endLabel" class="tiny muted note">
        The trip finishes by itself at {{ endLabel }}.
      </p>
      <p v-else-if="row.finish_requested_by === 'me'" class="tiny muted note">
        Waiting for {{ name }} to confirm the trip is finished.
      </p>
      <p v-else-if="row.finish_requested_by === 'them'" class="note">
        {{ name }} says the trip is finished.
      </p>
    </template>

    <!-- Actions -->
    <div class="booking-actions">
      <button
        v-if="needsBooking && isGuest && openForm !== 'pay'"
        type="button"
        class="accept"
        :disabled="busy"
        @click="show('pay')"
      >
        {{ finished ? 'Book again' : 'Pay' }} {{ money(row.fee_amount) }}
      </button>

      <template v-if="pending && isGuest">
        <button
          type="button"
          class="soft-btn"
          :aria-busy="busy"
          :disabled="busy"
          @click="
            op('withdraw', `Payment withdrawn — you’ve been refunded ${money(row.booking_amount)}.`)
          "
        >
          Withdraw payment
        </button>
      </template>
      <template v-if="pending && !isGuest && openForm !== 'decline'">
        <button
          type="button"
          class="accept"
          :aria-busy="busy"
          :disabled="busy"
          @click="
            op('accept', `Booking confirmed — it’s in My trips, with ${name}’s contact details.`)
          "
        >
          ✓ Accept
        </button>
        <button type="button" class="reject" :disabled="busy" @click="show('decline')">
          Decline
        </button>
      </template>

      <template v-if="paid && isGuest">
        <button
          v-if="cancelRequested"
          type="button"
          class="soft-btn"
          :aria-busy="busy"
          :disabled="busy"
          @click="
            op('withdrawCancellation', 'Cancellation request withdrawn — your booking stands.')
          "
        >
          Keep my booking
        </button>
        <button
          v-else-if="!started && openForm !== 'requestCancel'"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="show('requestCancel')"
        >
          Request cancellation
        </button>
      </template>
      <button
        v-if="free && isGuest && openForm !== 'freeCancel'"
        type="button"
        class="reject"
        :disabled="busy"
        @click="show('freeCancel')"
      >
        Cancel trip
      </button>
      <template v-if="confirmed && !isGuest">
        <template v-if="cancelRequested">
          <button
            type="button"
            class="accept"
            :aria-busy="busy"
            :disabled="busy"
            @click="
              op(
                'approveCancellation',
                `Cancellation approved — ${name} has been refunded ${money(row.refund_if_guest_cancels)}.`,
              )
            "
          >
            Approve cancellation
          </button>
          <button
            type="button"
            class="soft-btn"
            :disabled="busy"
            @click="op('refuseCancellation', `You kept the booking — ${name} has been told.`)"
          >
            Refuse
          </button>
        </template>
        <button
          v-else-if="openForm !== 'guideCancel'"
          type="button"
          class="reject"
          :disabled="busy"
          @click="show('guideCancel')"
        >
          Cancel booking
        </button>
      </template>

      <template v-if="confirmed && !row.auto_finish">
        <button
          v-if="row.finish_requested_by === null"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="op('requestFinish', `Marked as finished — ${name} will be asked to confirm.`)"
        >
          Mark trip finished
        </button>
        <button
          v-else-if="row.finish_requested_by === 'me'"
          type="button"
          class="soft-btn"
          :disabled="busy"
          @click="op('dismissFinish', 'Finish request withdrawn.')"
        >
          Undo
        </button>
        <template v-else>
          <button
            type="button"
            class="accept"
            :aria-busy="busy"
            :disabled="busy"
            @click="op('confirmFinish', `Trip finished — you can leave ${name} a review now.`)"
          >
            Confirm finished
          </button>
          <button
            type="button"
            class="soft-btn"
            :disabled="busy"
            @click="op('dismissFinish', `Told ${name} the trip isn’t over yet.`)"
          >
            Not yet
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
        {{ row.location ? 'Change meeting place' : 'Set meeting place' }}
      </button>
    </div>

    <!-- Forms -->
    <form
      v-if="openForm === 'pay'"
      class="inline-form"
      :aria-label="`Pay ${name}’s fee`"
      @submit.prevent="pay"
    >
      <div class="fields">
        <label>
          When would you like to meet?
          <input v-model="meetingAt" type="datetime-local" :min="minMeeting" required />
        </label>
        <label>
          Trip length (hours) <span class="muted">optional</span>
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
        Where to meet <span class="muted">optional</span>
        <input
          v-model="location"
          type="text"
          :maxlength="MAX_LOCATION"
          placeholder="e.g. Casemates Square, by the fountain"
        />
      </label>
      <p class="tiny muted">
        You’ll pay {{ money(row.fee_amount) }} now; {{ name }} then confirms the date. With a trip
        length the trip finishes by itself — otherwise you both mark it finished. If
        {{ name }} declines or later cancels, you’re refunded in full.
      </p>
      <div class="form-actions">
        <button type="submit" class="accept" :aria-busy="busy" :disabled="busy">
          Confirm payment
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Not now
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'decline'"
      class="inline-form"
      :aria-label="`Decline ${name}’s booking`"
      @submit.prevent="
        op('decline', `Declined — ${name} has been refunded ${money(row.booking_amount)}.`, true)
      "
    >
      <p>
        {{ name }} will be refunded {{ money(row.booking_amount) }} in full and can suggest another
        time.
      </p>
      <label>
        Reason <span class="muted">(optional, shared with them)</span>
        <textarea v-model="reason" rows="2" maxlength="256" />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          Decline booking
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Back
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'requestCancel'"
      class="inline-form"
      :aria-label="`Ask ${name} to cancel`"
      @submit.prevent="requestCancellation"
    >
      <p>
        If {{ name }} agrees, you’re refunded
        <strong>{{ money(row.refund_if_guest_cancels) }}</strong> — the fee minus 5% — and get your
        pick back.
      </p>
      <label>
        Why do you need to cancel?
        <textarea v-model="reason" rows="2" maxlength="256" required />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          Send request
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Back
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'location'"
      class="inline-form"
      :aria-label="`Meeting place for your trip with ${name}`"
      @submit.prevent="saveLocation"
    >
      <label>
        Where to meet
        <input
          v-model="location"
          type="text"
          :maxlength="MAX_LOCATION"
          placeholder="e.g. Casemates Square, by the fountain"
        />
      </label>
      <p class="tiny muted">{{ name }} is told when you change it. Leave it empty to remove it.</p>
      <div class="form-actions">
        <button type="submit" class="accept" :aria-busy="busy" :disabled="busy">Save</button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Back
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'freeCancel'"
      class="inline-form"
      :aria-label="`Cancel your trip with ${name}`"
      @submit.prevent="freeCancel"
    >
      <p class="cancel-summary">
        {{ name }} will be told the trip is off, and you’ll no longer be matched. You get your pick
        back.
      </p>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">Cancel trip</button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Keep trip
        </button>
      </div>
    </form>

    <form
      v-if="openForm === 'guideCancel'"
      class="inline-form"
      :aria-label="`Cancel the booking with ${name}`"
      @submit.prevent="guideCancel"
    >
      <p class="cancel-summary">
        <template v-if="paid">
          {{ name }} will be refunded
          <strong>{{ money(row.refund_if_cancelled ?? row.booking_amount) }}</strong
          ><template v-if="lateCancel"> — that includes 5% for cancelling at short notice</template
          >.
        </template>
        <template v-else>{{ name }} will be told the booking is off.</template>
        They get their pick back and a message by email.
      </p>
      <label>
        Reason <span class="muted">(optional, shared with them)</span>
        <textarea v-model="reason" rows="2" maxlength="256" />
      </label>
      <div class="form-actions">
        <button type="submit" class="reject" :aria-busy="busy" :disabled="busy">
          Cancel booking
        </button>
        <button type="button" class="soft-btn" :disabled="busy" @click="openForm = null">
          Keep booking
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
