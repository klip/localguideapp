<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  ApiError,
  api,
  session,
  type Complaint,
  type ComplaintMessage,
  type ComplaintOp,
  type ModerationPerson,
} from '@/api'

/**
 * One complaint (`disputes.resolve`): what was reported, both people side
 * by side (with what counts against them), what's between them — their
 * match, bookings and, only when asked for, their chat (every look is in the
 * audit log) — staff notes, and the actions: take it (under review), resolve
 * (a resolution, optionally a sanction tied to it) or dismiss (a reason).
 * A trip dispute (a started trip's cancellation the guide didn't agree to)
 * also needs the refund decided when resolving — 0 lets the trip stand — and
 * can't be dismissed;
 * reopen. Closing tells the reporter it was reviewed, never the outcome.
 */
const props = defineProps<{ id: string }>()

const complaint = ref<Complaint | null>(null)
const messages = ref<ComplaintMessage[] | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const fieldError = reactive({ field: '', message: '' })
const note = ref('')
const closing = reactive({
  mode: '' as '' | 'resolve' | 'dismiss',
  text: '',
  sanction: false,
  levels: 1,
  days: 30 as number | '',
  /** A trip dispute's refund to the traveller, in £. */
  refund: '' as number | '',
})
const disputeOpen = computed(() => !!complaint.value?.dispute?.open)

const open = computed(
  () => complaint.value?.status === 'open' || complaint.value?.status === 'reviewing',
)
const STATUS_LABEL = {
  open: 'Open',
  reviewing: 'Under review',
  resolved: 'Resolved',
  dismissed: 'Dismissed',
} as const

async function load() {
  loading.value = true
  error.value = ''
  messages.value = null
  try {
    complaint.value = await api.moderationComplaint(Number(props.id))
  } catch (err) {
    complaint.value = null
    error.value = err instanceof ApiError ? err.message : 'Could not load this complaint.'
  } finally {
    loading.value = false
  }
}

async function act(op: ComplaintOp, payload: Record<string, unknown>, done: string) {
  if (busy.value) return false
  busy.value = true
  error.value = ''
  notice.value = ''
  fieldError.field = ''
  try {
    complaint.value = await api.complaintAction(Number(props.id), op, payload)
    notice.value = done
    return true
  } catch (err) {
    if (err instanceof ApiError && err.field) {
      fieldError.field = err.field
      fieldError.message = err.message
    } else {
      error.value = err instanceof ApiError ? err.message : 'That didn’t work.'
    }
    return false
  } finally {
    busy.value = false
  }
}

async function addNote() {
  if (await act('note', { note: note.value }, 'Note added.')) note.value = ''
}

async function close() {
  const ok =
    closing.mode === 'resolve'
      ? await act(
          'resolve',
          {
            resolution: closing.text,
            ...(disputeOpen.value
              ? { refund: closing.refund === '' ? null : Number(closing.refund) }
              : {}),
            ...(closing.sanction
              ? {
                  sanction: {
                    levels: closing.levels,
                    days: closing.days === '' ? null : closing.days,
                  },
                }
              : {}),
          },
          'Resolved — the reporter was told it was reviewed.',
        )
      : await act(
          'dismiss',
          { resolution: closing.text },
          'Dismissed — the reporter was told it was reviewed.',
        )
  if (ok) Object.assign(closing, { mode: '', text: '', sanction: false, refund: '' })
}

async function showMessages() {
  try {
    messages.value = (await api.complaintMessages(Number(props.id))).messages
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load their messages.'
  }
}

function who(p: ModerationPerson | null, role: 'reporter' | 'subject') {
  return p
    ? p.name || `#${p.id}`
    : role === 'reporter'
      ? 'The reporter (deleted)'
      : 'The person reported (deleted)'
}

function sideOf(guestId: number) {
  const c = complaint.value
  if (!c) return ''
  return c.reporter?.id === guestId
    ? `${who(c.reporter, 'reporter')} travelled`
    : `${who(c.subject, 'subject')} travelled`
}

function dateTime(iso: string | null) {
  return iso ? new Date(iso).toLocaleString() : '—'
}

function mapLink(m: ComplaintMessage) {
  return m.location
    ? `https://www.google.com/maps?q=${m.location.latitude},${m.location.longitude}`
    : ''
}

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <section class="complaint-page">
    <p class="tiny"><RouterLink to="/moderation">← Complaints</RouterLink></p>
    <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>

    <template v-if="complaint">
      <header class="head">
        <h1>
          {{ complaint.title }} <small class="muted">#{{ complaint.id }}</small>
        </h1>
        <p>
          <span class="pill" :class="complaint.status" data-test="status">{{
            STATUS_LABEL[complaint.status]
          }}</span>
          <span class="tiny muted">
            reported {{ dateTime(complaint.createdAt) }}
            <template v-if="complaint.assignedTo"> · taken by {{ complaint.assignedTo }}</template>
            <template v-if="complaint.resolvedBy">
              · closed by {{ complaint.resolvedBy }}, {{ dateTime(complaint.resolvedAt) }}</template
            >
            <template v-if="complaint.reporterToldAt">
              · reporter told {{ dateTime(complaint.reporterToldAt) }}</template
            >
          </span>
        </p>
      </header>
      <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>

      <article>
        <h2>What they reported</h2>
        <p class="description" data-test="description">{{ complaint.description }}</p>
        <p v-if="complaint.resolution" class="resolution">
          <strong>{{ complaint.status === 'dismissed' ? 'Dismissed' : 'Resolution' }}:</strong>
          {{ complaint.resolution }}
        </p>
      </article>

      <div class="people">
        <article v-for="role in ['reporter', 'subject'] as const" :key="role" :data-person="role">
          <h3>{{ role === 'reporter' ? 'Reported by' : 'About' }}</h3>
          <template v-if="complaint[role]">
            <div class="person">
              <img
                v-if="complaint[role]!.image"
                :src="complaint[role]!.image!"
                alt=""
                class="avatar"
              />
              <div>
                <RouterLink :to="`/users/${complaint[role]!.id}`">{{
                  who(complaint[role], role)
                }}</RouterLink>
                <span v-if="complaint[role]!.freeze" class="pill frozen">frozen</span>
                <span v-if="complaint[role]!.verified" class="pill ok">✓ verified</span>
                <div class="tiny muted">
                  {{ complaint[role]!.email }} · joined
                  {{ new Date(complaint[role]!.createdAt).toLocaleDateString() }}
                </div>
                <div
                  class="tiny"
                  :class="{ warn: role === 'subject' && complaint[role]!.complaintsAbout > 1 }"
                >
                  {{ complaint[role]!.complaintsAbout }} complaint(s) about them ·
                  {{ complaint[role]!.complaintsBy }} made
                  <template v-if="complaint[role]!.activeSanctions">
                    · −{{ complaint[role]!.activeSanctions }} levels in force</template
                  >
                </div>
                <RouterLink
                  v-if="role === 'subject'"
                  class="tiny"
                  :to="`/audit?target=user:${complaint[role]!.id}`"
                  >Staff actions on them</RouterLink
                >
              </div>
            </div>
          </template>
          <p v-else class="muted">{{ who(null, role) }}</p>
        </article>
      </div>

      <article v-if="complaint.dispute" data-test="dispute">
        <h3>Trip dispute</h3>
        <p>
          Booking #{{ complaint.dispute.bookingId }} · £{{ complaint.dispute.amount.toFixed(2) }}
          {{ complaint.dispute.paymentStatus }}
          <template v-if="complaint.dispute.meetingAt">
            · meeting {{ dateTime(complaint.dispute.meetingAt) }}</template
          >
          <template v-if="complaint.dispute.startedAt">
            · started by the guide {{ dateTime(complaint.dispute.startedAt) }}</template
          >
        </p>
        <p v-if="complaint.dispute.guestReason">
          The traveller’s reason: “{{ complaint.dispute.guestReason }}”
        </p>
        <p v-if="complaint.dispute.open" class="tiny muted">
          The traveller cancelled after the guide started the trip, and the guide didn’t agree.
          Resolving it decides the refund.
        </p>
        <p v-else class="tiny muted">
          Decided: {{ complaint.dispute.status === 'cancelled' ? 'cancelled' : 'the trip stood' }}
          <template v-if="complaint.dispute.refund !== null">
            · refunded £{{ complaint.dispute.refund.toFixed(2) }}</template
          >
        </p>
      </article>

      <article v-if="complaint.between" data-test="between">
        <h2>Between them</h2>
        <p v-if="!complaint.between.matches.length" class="muted">They were never matched.</p>
        <ul v-else class="records">
          <li v-for="(m, i) in complaint.between.matches" :key="i">
            {{ m.active ? 'Matched' : 'Not matched now'
            }}<template v-if="m.matchedAt">
              (since {{ new Date(m.matchedAt).toLocaleDateString() }})</template
            >
            <span class="tiny muted">
              · traveller: {{ m.guestDecision ?? 'no answer' }}, guide:
              {{ m.guideDecision ?? 'no answer' }}</span
            >
          </li>
        </ul>
        <ul v-if="complaint.between.bookings.length" class="records">
          <li v-for="b in complaint.between.bookings" :key="b.id">
            Booking #{{ b.id }} — {{ b.status
            }}{{ b.status === 'confirmed' ? ` · trip ${b.tripStatus}` : '' }} ·
            {{ dateTime(b.meetingAt) }}
            <span class="tiny muted">
              · {{ sideOf(b.guestId) }} · {{ b.amount !== null ? `£${b.amount}` : 'free' }}
              {{ b.paymentStatus }}
              <template v-if="b.refund !== null"> · refunded £{{ b.refund }}</template>
              <template v-if="b.cancelReason"> · “{{ b.cancelReason }}”</template>
            </span>
          </li>
        </ul>
        <p class="tiny">
          {{ complaint.between.messageCount }} chat message{{
            complaint.between.messageCount === 1 ? '' : 's'
          }}
          between them.
          <button
            v-if="complaint.between.messageCount && messages === null"
            type="button"
            class="secondary outline small"
            data-test="show-messages"
            @click="showMessages"
          >
            Read their chat
          </button>
          <span v-if="complaint.between.messageCount && messages === null" class="muted">
            — opening it is recorded in the audit log.</span
          >
        </p>
        <ol v-if="messages" class="chat" data-test="messages">
          <li v-for="m in messages" :key="m.id" :class="m.from">
            <span class="tiny muted"
              >{{
                m.from === 'reporter'
                  ? who(complaint.reporter, 'reporter')
                  : who(complaint.subject, 'subject')
              }}
              · {{ dateTime(m.createdAt) }}</span
            >
            <div v-if="m.kind === 'location'">
              📍 <a :href="mapLink(m)" target="_blank" rel="noopener">Shared location</a>
            </div>
            <div v-else class="body">{{ m.body }}</div>
          </li>
        </ol>
      </article>

      <article>
        <h2>Staff notes</h2>
        <ul class="records" data-test="notes">
          <li v-for="n in complaint.notes" :key="n.id">
            <div class="body">{{ n.body }}</div>
            <span class="tiny muted">{{ n.by }} · {{ dateTime(n.createdAt) }}</span>
          </li>
          <li v-if="!complaint.notes.length" class="muted">None yet.</li>
        </ul>
        <form class="note-form" data-test="note-form" @submit.prevent="addNote">
          <textarea
            v-model="note"
            rows="2"
            maxlength="2000"
            placeholder="What you checked, who you contacted…"
            :aria-invalid="fieldError.field === 'note' ? 'true' : undefined"
          ></textarea>
          <small v-if="fieldError.field === 'note'" class="field-error">{{
            fieldError.message
          }}</small>
          <button type="submit" class="secondary" :disabled="busy || !note.trim()">Add note</button>
        </form>
        <p v-if="complaint.sanctions.length" class="tiny">
          Sanctions from this complaint:
          <template v-for="s in complaint.sanctions" :key="s.id">
            −{{ s.levels }}
            {{
              s.liftedAt
                ? '(lifted)'
                : s.expiresAt
                  ? `until ${new Date(s.expiresAt).toLocaleDateString()}`
                  : '(no end)'
            }}
            by {{ s.by }}.
          </template>
        </p>
      </article>

      <article data-test="actions">
        <h2>Decision</h2>
        <template v-if="open">
          <div class="buttons">
            <button
              v-if="complaint.status === 'open' || complaint.assignedTo !== session.me?.email"
              type="button"
              class="secondary outline"
              :disabled="busy"
              data-test="take"
              @click="act('take', {}, 'It’s yours — marked under review.')"
            >
              {{ complaint.status === 'open' ? 'Take it' : 'Take it over' }}
            </button>
            <button
              type="button"
              :class="closing.mode === 'resolve' ? '' : 'secondary'"
              data-test="resolve"
              @click="closing.mode = 'resolve'"
            >
              Resolve…
            </button>
            <button
              v-if="!disputeOpen"
              type="button"
              :class="closing.mode === 'dismiss' ? '' : 'secondary outline'"
              data-test="dismiss"
              @click="closing.mode = 'dismiss'"
            >
              Dismiss…
            </button>
          </div>
          <form
            v-if="closing.mode"
            class="close-form"
            data-test="close-form"
            @submit.prevent="close"
          >
            <label>
              {{ closing.mode === 'resolve' ? 'What was done' : 'Why no action is needed' }}
              <textarea
                v-model="closing.text"
                rows="3"
                maxlength="2000"
                required
                :aria-invalid="fieldError.field === 'resolution' ? 'true' : undefined"
              ></textarea>
              <small v-if="fieldError.field === 'resolution'" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label v-if="closing.mode === 'resolve' && complaint.dispute?.open">
              Refund to the traveller, £ (0 = the trip stands)
              <input
                v-model.number="closing.refund"
                type="number"
                min="0"
                :max="complaint.dispute.amount"
                step="0.01"
                required
                data-test="dispute-refund"
                :aria-invalid="fieldError.field === 'refund' ? 'true' : undefined"
              />
              <small v-if="fieldError.field === 'refund'" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <template v-if="closing.mode === 'resolve' && complaint.subject">
              <label class="check">
                <input v-model="closing.sanction" type="checkbox" data-test="with-sanction" /> Also
                sanction {{ who(complaint.subject, 'subject') }}
              </label>
              <div v-if="closing.sanction" class="inline">
                <label
                  >Levels <input v-model.number="closing.levels" type="number" min="1" max="7"
                /></label>
                <label
                  >For (days, empty = until lifted)
                  <input v-model="closing.days" type="number" min="1"
                /></label>
              </div>
              <small
                v-if="fieldError.field && !['resolution', 'refund'].includes(fieldError.field)"
                class="field-error"
                >{{ fieldError.message }}</small
              >
              <p class="tiny muted">For a freeze, use their user page.</p>
            </template>
            <p class="tiny muted">
              The reporter is told their report was reviewed — not what was decided.
            </p>
            <div class="buttons">
              <button type="submit" :disabled="busy" data-test="confirm-close">
                {{
                  closing.mode === 'resolve'
                    ? closing.sanction
                      ? 'Resolve and sanction'
                      : 'Resolve'
                    : 'Dismiss'
                }}
              </button>
              <button type="button" class="secondary outline" @click="closing.mode = ''">
                Cancel
              </button>
            </div>
          </form>
        </template>
        <div v-else class="buttons">
          <button
            type="button"
            class="secondary outline"
            :disabled="busy"
            data-test="reopen"
            @click="act('reopen', {}, 'Reopened — under review by you.')"
          >
            Reopen
          </button>
        </div>
      </article>
    </template>
  </section>
</template>

<style scoped>
.head h1 {
  margin-bottom: 0.25rem;
}

.pill {
  display: inline-block;
  margin-right: 0.35rem;
  padding: 0.05rem 0.55rem;
  border-radius: 1rem;
  font-size: 0.8rem;
  background: var(--pico-muted-border-color);
}

.pill.open {
  background: var(--pico-del-color);
  color: #fff;
}

.pill.reviewing {
  background: #b7791f;
  color: #fff;
}

.pill.resolved,
.pill.ok {
  background: #1f7a4d;
  color: #fff;
}

.pill.frozen {
  background: var(--pico-del-color);
  color: #fff;
}

.description,
.body {
  white-space: pre-wrap;
}

.resolution {
  padding: 0.5rem 0.75rem;
  border-left: 3px solid var(--pico-primary);
}

.people {
  display: grid;
  gap: 1rem;
}

@media (min-width: 800px) {
  .people {
    grid-template-columns: 1fr 1fr;
  }
}

.person {
  display: flex;
  gap: 0.75rem;
}

.avatar {
  width: 3rem;
  height: 3rem;
  border-radius: 50%;
  object-fit: cover;
}

.warn {
  color: var(--pico-del-color);
  font-weight: 600;
}

.records {
  list-style: none;
  padding: 0;
}

.records li {
  list-style: none;
  padding: 0.35rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.chat {
  list-style: none;
  padding: 0;
  max-height: 28rem;
  overflow-y: auto;
}

.chat li {
  list-style: none;
  max-width: 80%;
  margin: 0.35rem 0;
  padding: 0.4rem 0.6rem;
  border-radius: var(--pico-border-radius);
  background: var(--pico-muted-border-color);
}

.chat li.subject {
  margin-left: auto;
  background: color-mix(in srgb, var(--pico-del-color) 18%, transparent);
}

button.small {
  width: auto;
  margin: 0 0 0 0.5rem;
  padding: 0.1rem 0.6rem;
  font-size: 0.8rem;
}

.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.buttons button {
  width: auto;
  margin: 0;
}

.note-form button {
  width: auto;
}

.close-form {
  margin-top: 1rem;
}

.inline {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.inline input {
  width: 7rem;
}

.check {
  display: inline-flex;
  gap: 0.4rem;
  align-items: center;
}

.field-error {
  color: var(--pico-del-color);
}
</style>
