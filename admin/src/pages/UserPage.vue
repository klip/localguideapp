<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, api, can, type AppRole, type UserDetail, type UserEditOp } from '@/api'

/**
 * One app user (`users.view`), section by section — account, roles and what
 * they show in each, profile, levels (with sanctions and adjustments),
 * awarded badges, bonuses, verification, freezing, activity and, for admins,
 * security. Changes need `users.edit`; freezing `users.freeze`; deciding a
 * booking left open at a freeze `refunds`. Every change is in the audit log,
 * and the server answers each with the page's fresh data.
 */
const props = defineProps<{ id: string }>()

const user = ref<UserDetail | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const notice = ref('')
/** A field error from the server, pinned under the input of the form that sent it. */
const fieldError = reactive({ form: '', field: '', message: '' })

const canEdit = computed(() => can('users.edit'))
const canFreeze = computed(() => can('users.freeze'))
const canRefund = computed(() => can('refunds'))

const ROLE_LABEL: Record<AppRole, string> = { guest: 'Traveller', guide: 'Guide' }
const LEVEL_NAMES = [
  'New',
  'Starter',
  'Explorer',
  'Pathfinder',
  'Trailblazer',
  'Pioneer',
  'Legend',
  'Icon',
]
const METHOD_LABEL = {
  id_card: 'ID card',
  passport: 'Passport',
  driving_licence: 'Driving licence',
} as const
const SECTIONS = computed(
  () =>
    [
      ['account', 'Account'],
      ['roles', 'Roles'],
      ['levels', 'Levels'],
      ['badges', 'Badges'],
      ['bonuses', 'Bonuses'],
      ['verification', 'Verification'],
      ['freeze', 'Freeze'],
      ['activity', 'Activity'],
      ...(user.value?.security ? [['security', 'Security']] : []),
    ] as [string, string][],
)

// ---- forms -------------------------------------------------------------
const account = reactive({ name: '', email: '', phone: '', twoFactorMethod: 'none' })
const roleText = reactive<Record<AppRole, { headline: string; bio: string }>>({
  guest: { headline: '', bio: '' },
  guide: { headline: '', bio: '' },
})
const profile = reactive({
  dateOfBirth: '',
  gender: '',
  priceAmount: '' as number | '',
  priceLabel: '',
  priceNote: '',
  party: '',
  durationHours: '' as number | '',
})
const sanction = reactive({
  levels: 1,
  reason: '',
  days: '' as number | '',
  complaintId: '' as number | '',
})
const adjustment = reactive({
  role: '' as '' | AppRole,
  delta: 1,
  reason: '',
  days: '' as number | '',
})
const badge = reactive({ label: '', icon: '★', days: '' as number | '' })
const bonus = reactive({
  kind: 'free_picks' as 'free_picks' | 'no_fee',
  days: 7,
  note: '',
  startNow: false,
})
const extendDays = reactive<Record<number, number>>({})
const verification = ref<string>('')
const freeze = reactive({
  days: 7 as number | '',
  permanent: false,
  reason: '',
  bookings: {} as Record<number, { decision: 'leave' | 'cancel'; refund: number | '' }>,
})
const liftReason = ref('')
const decisions = reactive<Record<number, { refund: number | ''; note: string }>>({})
const confirming = ref('')

function fill(next: UserDetail) {
  user.value = next
  const a = next.account
  Object.assign(account, {
    name: a.name,
    email: a.email,
    phone: a.phone ?? '',
    twoFactorMethod: a.twoFactorMethod === 'email' ? 'email' : 'none',
  })
  for (const role of ['guest', 'guide'] as AppRole[]) {
    roleText[role].headline = next.roles[role].headline ?? ''
    roleText[role].bio = next.roles[role].bio ?? ''
  }
  const p = next.profile
  Object.assign(profile, {
    dateOfBirth: p.dateOfBirth ?? '',
    gender: p.gender ?? '',
    priceAmount: p.priceAmount ?? '',
    priceLabel: p.priceLabel ?? '',
    priceNote: p.priceNote ?? '',
    party: p.party ?? '',
    durationHours: p.durationHours ?? '',
  })
  verification.value = next.verification.method ?? ''
  freeze.bookings = Object.fromEntries(
    next.openBookings.map((b) => [b.id, { decision: 'leave', refund: b.amount ?? '' }]),
  )
  for (const b of next.openBookings) decisions[b.id] ??= { refund: b.amount ?? '', note: '' }
  confirming.value = ''
}

function clearMessages() {
  error.value = ''
  notice.value = ''
  fieldError.form = ''
  fieldError.field = ''
  fieldError.message = ''
}

function invalid(form: string, field: string) {
  return fieldError.form === form && fieldError.field === field ? 'true' : undefined
}

function errorFor(form: string, field?: string) {
  return fieldError.form === form && (field === undefined || fieldError.field === field)
    ? fieldError.message
    : ''
}

async function load() {
  loading.value = true
  clearMessages()
  try {
    fill(await api.userDetail(Number(props.id)))
  } catch (err) {
    user.value = null
    error.value = err instanceof ApiError ? err.message : 'Could not load this user.'
  } finally {
    loading.value = false
  }
}

/** Sends one change; `form` names where a field error is pinned. */
async function run(form: string, action: () => Promise<UserDetail>, done: string) {
  if (busy.value) return false
  clearMessages()
  busy.value = true
  try {
    fill(await action())
    notice.value = done
    return true
  } catch (err) {
    if (err instanceof ApiError && err.field) {
      fieldError.form = form
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

const id = computed(() => Number(props.id))
function edit(op: UserEditOp, payload: Record<string, unknown>, done: string, form: string = op) {
  return run(form, () => api.userEdit(id.value, op, payload), done)
}

function saveAccount() {
  void edit('account', { account: { ...account } }, 'Account saved.')
}
function removePhoto() {
  void edit('account', { account: { removePhoto: true } }, 'Photo removed.')
}
function saveRoleText(role: AppRole) {
  void edit(
    'roleText',
    { role, ...roleText[role] },
    `${ROLE_LABEL[role]} text saved.`,
    `roleText-${role}`,
  )
}
function saveProfile() {
  void edit('profile', { profile: { ...profile } }, 'Profile saved.')
}
function addRole(role: AppRole) {
  void edit(
    'addRole',
    { role },
    `${ROLE_LABEL[role]} role added — their app shows it within 15 minutes.`,
  )
}
function removeRole(role: AppRole) {
  void edit('removeRole', { role }, `${ROLE_LABEL[role]} role removed; they were signed out.`)
}
async function addSanction() {
  if (await edit('addSanction', { ...sanction }, 'Sanction added.'))
    Object.assign(sanction, { levels: 1, reason: '', days: '', complaintId: '' })
}
async function addAdjustment() {
  if (
    await edit('addAdjustment', { ...adjustment, role: adjustment.role || null }, 'Level adjusted.')
  )
    Object.assign(adjustment, { role: '', delta: 1, reason: '', days: '' })
}
async function addBadge() {
  if (await edit('addBadge', { ...badge }, 'Badge awarded.'))
    Object.assign(badge, { label: '', icon: '★', days: '' })
}
async function addBonus() {
  if (
    await edit(
      'addBonus',
      { ...bonus },
      bonus.startNow ? 'Bonus started.' : 'Bonus added — they claim it on their profile.',
    )
  )
    Object.assign(bonus, { days: 7, note: '', startNow: false })
}
function saveVerification() {
  void edit(
    'verification',
    { method: verification.value || null },
    verification.value ? 'Marked verified.' : 'Verification cleared.',
  )
}

async function doFreeze() {
  const bookings = Object.entries(freeze.bookings).map(([bookingId, choice]) => ({
    id: Number(bookingId),
    decision: choice.decision,
    refund: choice.decision === 'cancel' && choice.refund !== '' ? Number(choice.refund) : null,
  }))
  const ok = await run(
    'freeze',
    () =>
      api.userFreeze(id.value, {
        ...(freeze.permanent ? { permanent: true } : { days: Number(freeze.days) }),
        reason: freeze.reason,
        bookings,
      }),
    'Frozen — they were signed out everywhere.',
  )
  if (ok) freeze.reason = ''
}
async function lift() {
  if (!user.value?.freeze) return
  const freezeId = user.value.freeze.id
  if (
    await run(
      'lift',
      () => api.userUnfreeze(id.value, freezeId, liftReason.value),
      'Freeze lifted.',
    )
  )
    liftReason.value = ''
}
function decide(bookingId: number) {
  const choice = decisions[bookingId]!
  void run(
    `decision-${bookingId}`,
    () =>
      api.bookingDecision(
        id.value,
        bookingId,
        choice.refund === '' ? null : Number(choice.refund),
        choice.note,
      ),
    `Booking #${bookingId} cancelled; both sides were told.`,
  )
}

// ---- display helpers ---------------------------------------------------
function date(iso: string | null | undefined) {
  return iso ? new Date(iso).toLocaleDateString() : '—'
}
function dateTime(iso: string | null | undefined) {
  return iso ? new Date(iso).toLocaleString() : '—'
}
function money(n: number | null) {
  return n === null ? '—' : `£${n.toFixed(2).replace(/\.00$/, '')}`
}
function until(iso: string | null) {
  return iso ? `until ${date(iso)}` : 'no end'
}
function initial() {
  return (user.value?.account.name || user.value?.account.email || '?').charAt(0).toUpperCase()
}

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <section class="user-page">
    <p class="tiny"><RouterLink to="/users">← Users</RouterLink></p>
    <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>

    <template v-if="user">
      <header class="hero">
        <img v-if="user.account.image" :src="user.account.image" alt="" class="photo" />
        <span v-else class="photo initial" aria-hidden="true">{{ initial() }}</span>
        <div>
          <h1 data-test="name">
            {{ user.account.name || '(no name)' }}
            <small class="muted">#{{ user.account.id }}</small>
          </h1>
          <p class="muted">
            {{ user.account.email }} ·
            {{ user.account.roles.map((r) => ROLE_LABEL[r]).join(' & ') || 'no role' }} · joined
            {{ date(user.account.createdAt) }}
          </p>
          <p>
            <span v-if="user.freeze" class="pill frozen" data-test="frozen-pill">
              Frozen
              {{ user.freeze.until ? `until ${dateTime(user.freeze.until)}` : 'permanently' }}
            </span>
            <span v-if="user.verification.verifiedAt" class="pill ok">✓ Verified</span>
            <span v-for="b in user.badges.filter((x) => x.active)" :key="b.id" class="pill"
              >{{ b.icon }} {{ b.label }}</span
            >
          </p>
        </div>
      </header>

      <nav class="sections" aria-label="Sections of this page">
        <a v-for="[key, label] in SECTIONS" :key="key" :href="`#${key}`">{{ label }}</a>
      </nav>

      <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>

      <!-- Account -->
      <article id="account">
        <h2>Account</h2>
        <form data-test="account-form" @submit.prevent="saveAccount">
          <div class="grid">
            <label>
              Name
              <input v-model="account.name" type="text" maxlength="120" :disabled="!canEdit" />
            </label>
            <label>
              Email
              <input
                v-model="account.email"
                type="email"
                required
                :disabled="!canEdit"
                :aria-invalid="invalid('account', 'email')"
              />
              <small v-if="invalid('account', 'email')" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
          </div>
          <div class="grid">
            <label>
              Phone
              <input
                v-model="account.phone"
                type="tel"
                :disabled="!canEdit"
                :aria-invalid="invalid('account', 'phone')"
              />
              <small v-if="invalid('account', 'phone')" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label>
              Sign-in codes
              <select v-model="account.twoFactorMethod" :disabled="!canEdit">
                <option value="email">By email</option>
                <option value="none">Off</option>
              </select>
            </label>
          </div>
          <div v-if="canEdit" class="buttons">
            <button type="submit" :disabled="busy">Save account</button>
            <button
              v-if="user.account.image"
              type="button"
              class="secondary outline"
              :disabled="busy"
              @click="removePhoto"
            >
              Remove photo
            </button>
          </div>
        </form>
      </article>

      <!-- Roles & profile -->
      <article id="roles">
        <h2>Roles</h2>
        <div class="roles">
          <section v-for="role in ['guest', 'guide'] as AppRole[]" :key="role" :data-role="role">
            <h3>
              {{ ROLE_LABEL[role] }}
              <span v-if="!user.roles[role].held" class="tiny muted">— not held</span>
            </h3>
            <template v-if="user.roles[role].held">
              <form :data-test="`role-text-${role}`" @submit.prevent="saveRoleText(role)">
                <label>
                  Headline
                  <input
                    v-model="roleText[role].headline"
                    type="text"
                    maxlength="160"
                    :disabled="!canEdit"
                  />
                </label>
                <label>
                  About them
                  <textarea v-model="roleText[role].bio" rows="3" :disabled="!canEdit"></textarea>
                  <small v-if="errorFor(`roleText-${role}`)" class="field-error">{{
                    errorFor(`roleText-${role}`)
                  }}</small>
                </label>
                <dl v-if="Object.keys(user.roles[role].attributes).length" class="tiny">
                  <template
                    v-for="(values, category) in user.roles[role].attributes"
                    :key="category"
                  >
                    <dt>{{ category }}</dt>
                    <dd>{{ values.join(', ') }}</dd>
                  </template>
                </dl>
                <div v-if="canEdit" class="buttons">
                  <button type="submit" class="secondary" :disabled="busy">
                    Save {{ ROLE_LABEL[role].toLowerCase() }} text
                  </button>
                  <button
                    v-if="user.account.roles.length > 1"
                    type="button"
                    class="secondary outline"
                    :disabled="busy"
                    :data-test="`remove-${role}`"
                    @click="
                      confirming === `remove-${role}`
                        ? removeRole(role)
                        : (confirming = `remove-${role}`)
                    "
                  >
                    {{ confirming === `remove-${role}` ? 'Confirm: remove role' : 'Remove role…' }}
                  </button>
                </div>
              </form>
            </template>
            <button
              v-else-if="canEdit"
              type="button"
              class="secondary outline"
              :disabled="busy"
              :data-test="`add-${role}`"
              @click="addRole(role)"
            >
              Give them the {{ ROLE_LABEL[role].toLowerCase() }} role
            </button>
          </section>
        </div>

        <h3>Profile</h3>
        <form data-test="profile-form" @submit.prevent="saveProfile">
          <div class="grid">
            <label>
              Date of birth
              <input
                v-model="profile.dateOfBirth"
                type="date"
                :disabled="!canEdit"
                :aria-invalid="invalid('profile', 'dateOfBirth')"
              />
              <small v-if="invalid('profile', 'dateOfBirth')" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label>
              Gender
              <select v-model="profile.gender" :disabled="!canEdit">
                <option value="">Not given</option>
                <option value="male">Male</option>
                <option value="female">Female</option>
              </select>
            </label>
          </div>
          <div v-if="user.roles.guide.held" class="grid">
            <label>
              Fee (£, empty = free)
              <input
                v-model="profile.priceAmount"
                type="number"
                min="0"
                step="0.01"
                :disabled="!canEdit"
                :aria-invalid="invalid('profile', 'priceAmount')"
              />
              <small v-if="invalid('profile', 'priceAmount')" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label>
              Price label
              <input v-model="profile.priceLabel" type="text" maxlength="80" :disabled="!canEdit" />
            </label>
            <label>
              Price note
              <input v-model="profile.priceNote" type="text" maxlength="120" :disabled="!canEdit" />
            </label>
          </div>
          <div v-if="user.roles.guest.held" class="grid">
            <label>
              Travelling with
              <input v-model="profile.party" type="text" maxlength="160" :disabled="!canEdit" />
            </label>
            <label>
              Trip length (hours)
              <input
                v-model="profile.durationHours"
                type="number"
                min="1"
                max="720"
                :disabled="!canEdit"
              />
            </label>
          </div>
          <p class="tiny muted">
            <template v-if="user.profile.guideBase">
              Guides around {{ user.profile.guideBase.label }} ({{
                user.profile.guideBase.radiusKm
                  ? `${user.profile.guideBase.radiusKm} km`
                  : 'flexible'
              }}).
            </template>
            <template v-if="user.profile.travellerArea">
              Looking in {{ user.profile.travellerArea.label }} (set
              {{ date(user.profile.travellerArea.updatedAt) }}).
            </template>
            Interests and other picks are theirs to change in the app.
          </p>
          <button v-if="canEdit" type="submit" class="secondary" :disabled="busy">
            Save profile
          </button>
        </form>
      </article>

      <!-- Levels -->
      <article id="levels">
        <h2>Levels</h2>
        <table class="levels" data-test="levels">
          <thead>
            <tr>
              <th>Role</th>
              <th>Trips (paid)</th>
              <th>Points</th>
              <th>Earned</th>
              <th>Adjusted</th>
              <th>Now</th>
              <th>Reviews</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="role in (['guest', 'guide'] as AppRole[]).filter((r) => user!.roles[r].held)"
              :key="role"
            >
              <td>{{ ROLE_LABEL[role] }}</td>
              <td>{{ user.roles[role].trips }} ({{ user.roles[role].paidTrips }})</td>
              <td>{{ user.roles[role].points }}</td>
              <td>
                {{ user.roles[role].earnedLevel }} · {{ LEVEL_NAMES[user.roles[role].earnedLevel] }}
              </td>
              <td>
                {{ user.roles[role].adjustment > 0 ? '+' : ''
                }}{{ user.roles[role].adjustment || '—' }}
              </td>
              <td :data-test="`level-${role}`">
                <strong
                  >{{ user.roles[role].effectiveLevel }} ·
                  {{ LEVEL_NAMES[user.roles[role].effectiveLevel] }}</strong
                >
              </td>
              <td>
                {{
                  user.roles[role].reviews.count
                    ? `★ ${user.roles[role].reviews.average} (${user.roles[role].reviews.count})`
                    : '—'
                }}
                <span v-if="user.roles[role].topRated" class="pill ok">Top rated</span>
                <span v-if="user.roles[role].volunteer" class="pill">Volunteer</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p class="tiny muted">
          Earned levels come from paid trips. Sanctions lower both roles and pause bonuses;
          adjustments move one role, either way.
        </p>

        <h3>Sanctions</h3>
        <ul class="records">
          <li v-for="s in user.sanctions" :key="s.id" :class="{ past: !s.active }">
            <strong>−{{ s.levels }}</strong> {{ s.reason }}
            <span class="tiny muted"
              >· {{ date(s.createdAt) }} {{ s.by ? `by ${s.by}` : '' }} ·
              {{
                s.liftedAt
                  ? `lifted ${date(s.liftedAt)}`
                  : s.active
                    ? until(s.expiresAt)
                    : 'expired'
              }}</span
            >
            <button
              v-if="s.active && canEdit"
              type="button"
              class="secondary outline small"
              :disabled="busy"
              @click="edit('liftSanction', { sanctionId: s.id }, 'Sanction lifted.')"
            >
              Lift
            </button>
          </li>
          <li v-if="!user.sanctions.length" class="muted">None.</li>
        </ul>
        <form
          v-if="canEdit"
          class="inline-form"
          data-test="sanction-form"
          @submit.prevent="addSanction"
        >
          <label
            >Levels <input v-model.number="sanction.levels" type="number" min="1" max="7"
          /></label>
          <label class="grow"
            >Reason
            <input
              v-model="sanction.reason"
              type="text"
              maxlength="255"
              :aria-invalid="invalid('addSanction', 'reason')"
          /></label>
          <label
            >Days <input v-model="sanction.days" type="number" min="1" placeholder="∞"
          /></label>
          <label>Complaint # <input v-model="sanction.complaintId" type="number" min="1" /></label>
          <button type="submit" class="secondary" :disabled="busy">Sanction</button>
          <small v-if="errorFor('addSanction')" class="field-error">{{
            errorFor('addSanction')
          }}</small>
        </form>

        <h3>Adjustments</h3>
        <ul class="records">
          <li v-for="a in user.adjustments" :key="a.id" :class="{ past: !a.active }">
            <strong>{{ a.delta > 0 ? '+' : '' }}{{ a.delta }}</strong>
            {{ a.role ? ROLE_LABEL[a.role] : 'Both roles' }} — {{ a.reason }}
            <span class="tiny muted"
              >· {{ date(a.createdAt) }} by {{ a.by }} ·
              {{
                a.liftedAt
                  ? `lifted ${date(a.liftedAt)}`
                  : a.active
                    ? until(a.expiresAt)
                    : 'expired'
              }}</span
            >
            <button
              v-if="a.active && canEdit"
              type="button"
              class="secondary outline small"
              :disabled="busy"
              @click="edit('liftAdjustment', { adjustmentId: a.id }, 'Adjustment lifted.')"
            >
              Lift
            </button>
          </li>
          <li v-if="!user.adjustments.length" class="muted">None.</li>
        </ul>
        <form
          v-if="canEdit"
          class="inline-form"
          data-test="adjustment-form"
          @submit.prevent="addAdjustment"
        >
          <label>
            Role
            <select v-model="adjustment.role">
              <option value="">Both</option>
              <option value="guest">Traveller</option>
              <option value="guide">Guide</option>
            </select>
          </label>
          <label
            >By
            <input
              v-model.number="adjustment.delta"
              type="number"
              min="-7"
              max="7"
              :aria-invalid="invalid('addAdjustment', 'delta')"
          /></label>
          <label class="grow"
            >Reason <input v-model="adjustment.reason" type="text" maxlength="255"
          /></label>
          <label
            >Days <input v-model="adjustment.days" type="number" min="1" placeholder="∞"
          /></label>
          <button type="submit" class="secondary" :disabled="busy">Adjust</button>
          <small v-if="errorFor('addAdjustment')" class="field-error">{{
            errorFor('addAdjustment')
          }}</small>
        </form>
      </article>

      <!-- Badges -->
      <article id="badges">
        <h2>Badges</h2>
        <p class="tiny muted">
          Earned:
          {{
            [
              user.verification.verifiedAt && 'Verified',
              user.roles.guest.topRated && 'Top rated traveller',
              user.roles.guide.topRated && 'Top rated guide',
              user.roles.guide.volunteer && 'Volunteer',
            ]
              .filter(Boolean)
              .join(', ') || 'none'
          }}. Awarded badges show on their cards and profile as written (not translated).
        </p>
        <ul class="records">
          <li v-for="b in user.badges" :key="b.id" :class="{ past: !b.active }">
            {{ b.icon }} <strong>{{ b.label }}</strong>
            <span class="tiny muted"
              >· {{ date(b.createdAt) }} by {{ b.by }} ·
              {{
                b.revokedAt
                  ? `revoked ${date(b.revokedAt)}`
                  : b.active
                    ? until(b.expiresAt)
                    : 'expired'
              }}</span
            >
            <button
              v-if="b.active && canEdit"
              type="button"
              class="secondary outline small"
              :disabled="busy"
              @click="edit('revokeBadge', { badgeId: b.id }, 'Badge revoked.')"
            >
              Revoke
            </button>
          </li>
          <li v-if="!user.badges.length" class="muted">None awarded.</li>
        </ul>
        <form v-if="canEdit" class="inline-form" data-test="badge-form" @submit.prevent="addBadge">
          <label
            >Icon
            <input
              v-model="badge.icon"
              type="text"
              maxlength="2"
              class="icon"
              :aria-invalid="invalid('addBadge', 'icon')"
          /></label>
          <label class="grow"
            >Label
            <input
              v-model="badge.label"
              type="text"
              maxlength="40"
              placeholder="Founding guide"
              :aria-invalid="invalid('addBadge', 'label')"
          /></label>
          <label>Days <input v-model="badge.days" type="number" min="1" placeholder="∞" /></label>
          <button type="submit" class="secondary" :disabled="busy">Award</button>
          <small v-if="errorFor('addBadge')" class="field-error">{{ errorFor('addBadge') }}</small>
        </form>
      </article>

      <!-- Bonuses -->
      <article id="bonuses">
        <h2>Bonuses</h2>
        <table v-if="user.bonuses.length" data-test="bonuses">
          <tbody>
            <tr
              v-for="b in user.bonuses"
              :key="b.id"
              :class="{ past: b.state === 'used' || b.state === 'revoked' }"
            >
              <td>{{ b.kind === 'free_picks' ? 'Free picks' : 'No fee' }}, {{ b.days }} days</td>
              <td class="tiny">
                {{ b.note || b.reason }}<span v-if="b.by" class="muted"> · by {{ b.by }}</span>
              </td>
              <td class="tiny">
                {{ b.state
                }}<template v-if="b.startsAt">
                  · {{ date(b.startsAt) }}–{{ date(b.endsAt) }}</template
                >
              </td>
              <td class="actions">
                <template
                  v-if="
                    canEdit &&
                    (b.state === 'unclaimed' || b.state === 'queued' || b.state === 'running')
                  "
                >
                  <input
                    v-model.number="extendDays[b.id]"
                    type="number"
                    min="1"
                    max="365"
                    placeholder="+days"
                    aria-label="Days to add"
                  />
                  <button
                    type="button"
                    class="secondary outline small"
                    :disabled="busy || !extendDays[b.id]"
                    @click="
                      edit(
                        'extendBonus',
                        { bonusId: b.id, days: extendDays[b.id] },
                        'Bonus extended.',
                      )
                    "
                  >
                    Extend
                  </button>
                  <button
                    type="button"
                    class="secondary outline small"
                    :disabled="busy"
                    @click="edit('revokeBonus', { bonusId: b.id }, 'Bonus revoked.')"
                  >
                    Revoke
                  </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">None.</p>
        <form v-if="canEdit" class="inline-form" data-test="bonus-form" @submit.prevent="addBonus">
          <label>
            Kind
            <select v-model="bonus.kind">
              <option value="free_picks">Free picks (traveller)</option>
              <option value="no_fee">No fee (guide)</option>
            </select>
          </label>
          <label>Days <input v-model.number="bonus.days" type="number" min="1" max="365" /></label>
          <label class="grow"
            >Note <input v-model="bonus.note" type="text" maxlength="255" placeholder="Why"
          /></label>
          <label class="check"><input v-model="bonus.startNow" type="checkbox" /> Start now</label>
          <button type="submit" class="secondary" :disabled="busy">Give bonus</button>
          <small v-if="errorFor('addBonus')" class="field-error">{{ errorFor('addBonus') }}</small>
        </form>
      </article>

      <!-- Verification -->
      <article id="verification">
        <h2>Verification</h2>
        <p>
          <template v-if="user.verification.verifiedAt">
            Verified by {{ METHOD_LABEL[user.verification.method!] ?? 'document' }} on
            {{ date(user.verification.verifiedAt) }}.
          </template>
          <template v-else>Not verified.</template>
        </p>
        <form
          v-if="canEdit"
          class="inline-form"
          data-test="verification-form"
          @submit.prevent="saveVerification"
        >
          <label>
            Checked document
            <select v-model="verification">
              <option value="">Not verified</option>
              <option value="id_card">ID card</option>
              <option value="passport">Passport</option>
              <option value="driving_licence">Driving licence</option>
            </select>
          </label>
          <button type="submit" class="secondary" :disabled="busy">Save</button>
        </form>
      </article>

      <!-- Freeze -->
      <article id="freeze" :class="{ alert: user.freeze }">
        <h2>Freeze</h2>
        <template v-if="user.freeze">
          <p data-test="freeze-state">
            Frozen since {{ dateTime(user.freeze.startsAt) }},
            {{ user.freeze.until ? `until ${dateTime(user.freeze.until)}` : 'permanently' }} —
            {{ user.freeze.reason }}
          </p>
          <form v-if="canFreeze" class="inline-form" data-test="lift-form" @submit.prevent="lift">
            <label class="grow">
              Why lift it
              <input
                v-model="liftReason"
                type="text"
                maxlength="500"
                required
                :aria-invalid="invalid('lift', 'liftReason')"
              />
            </label>
            <button type="submit" :disabled="busy">Lift freeze</button>
            <small v-if="errorFor('lift')" class="field-error">{{ errorFor('lift') }}</small>
          </form>

          <template v-if="user.openBookings.length">
            <h3>Needs a decision</h3>
            <p class="tiny muted">
              Bookings left open when they were frozen. Cancelling refunds the guest and tells both
              sides.
            </p>
            <ul class="records">
              <li v-for="b in user.openBookings" :key="b.id" :data-booking="b.id">
                #{{ b.id }} {{ b.status }} with
                <RouterLink :to="`/users/${b.other.id}`">{{
                  b.other.name || `#${b.other.id}`
                }}</RouterLink>
                ({{ b.side === 'guest' ? 'they travel' : 'they guide' }}) ·
                {{ dateTime(b.meetingAt) }} · {{ b.paid ? money(b.amount) + ' paid' : 'free' }}
                <form v-if="canRefund" class="inline-form" @submit.prevent="decide(b.id)">
                  <label v-if="b.paid"
                    >Refund £
                    <input
                      v-model="decisions[b.id]!.refund"
                      type="number"
                      min="0"
                      :max="b.amount ?? 0"
                      step="0.01"
                  /></label>
                  <label class="grow"
                    >Note to both
                    <input v-model="decisions[b.id]!.note" type="text" maxlength="200"
                  /></label>
                  <button type="submit" class="secondary" :disabled="busy">Cancel booking</button>
                  <small v-if="errorFor(`decision-${b.id}`)" class="field-error">{{
                    errorFor(`decision-${b.id}`)
                  }}</small>
                </form>
              </li>
            </ul>
          </template>
        </template>

        <form v-else-if="canFreeze" data-test="freeze-form" @submit.prevent="doFreeze">
          <p class="tiny muted">
            They’re signed out everywhere at once, can’t sign in or open a new account from their
            browser, and disappear from decks, the planner and profiles; chats with them become
            read-only.
          </p>
          <div class="grid">
            <label>
              For (days)
              <input
                v-model="freeze.days"
                type="number"
                min="1"
                max="3650"
                :disabled="freeze.permanent"
                :aria-invalid="invalid('freeze', 'days')"
              />
              <small v-if="invalid('freeze', 'days')" class="field-error">{{
                fieldError.message
              }}</small>
            </label>
            <label class="check">
              <input v-model="freeze.permanent" type="checkbox" data-test="permanent" /> Permanently
            </label>
          </div>
          <label>
            Reason (kept with the freeze; not shown to them)
            <textarea
              v-model="freeze.reason"
              rows="2"
              maxlength="500"
              required
              :aria-invalid="invalid('freeze', 'reason')"
            ></textarea>
            <small v-if="invalid('freeze', 'reason')" class="field-error">{{
              fieldError.message
            }}</small>
          </label>

          <fieldset v-if="user.openBookings.length" data-test="freeze-bookings">
            <legend>Their open bookings</legend>
            <div
              v-for="b in user.openBookings"
              :key="b.id"
              class="booking-choice"
              :data-booking="b.id"
            >
              <span>
                #{{ b.id }} {{ b.status }} with {{ b.other.name || `#${b.other.id}` }} ·
                {{ dateTime(b.meetingAt) }} ·
                {{ b.paid ? money(b.amount) + ' paid' : 'free' }}
              </span>
              <label class="check"
                ><input v-model="freeze.bookings[b.id]!.decision" type="radio" value="leave" />
                Leave it</label
              >
              <label class="check"
                ><input v-model="freeze.bookings[b.id]!.decision" type="radio" value="cancel" />
                Cancel</label
              >
              <label v-if="freeze.bookings[b.id]!.decision === 'cancel' && b.paid">
                refund £
                <input
                  v-model="freeze.bookings[b.id]!.refund"
                  type="number"
                  min="0"
                  :max="b.amount ?? 0"
                  step="0.01"
                />
              </label>
            </div>
            <small v-if="invalid('freeze', 'bookings')" class="field-error">{{
              fieldError.message
            }}</small>
          </fieldset>

          <div class="buttons">
            <button
              v-if="confirming !== 'freeze'"
              type="button"
              class="contrast"
              :disabled="!freeze.reason.trim()"
              data-test="freeze"
              @click="confirming = 'freeze'"
            >
              Freeze…
            </button>
            <template v-else>
              <button type="submit" class="contrast" :disabled="busy" data-test="confirm-freeze">
                Confirm: freeze {{ freeze.permanent ? 'permanently' : `for ${freeze.days} days` }}
              </button>
              <button type="button" class="secondary outline" @click="confirming = ''">Keep</button>
            </template>
          </div>
        </form>

        <details v-if="user.freezes.length" class="history">
          <summary>Past freezes ({{ user.freezes.length }})</summary>
          <ul class="records">
            <li v-for="f in user.freezes" :key="f.id">
              {{ date(f.startsAt) }}–{{ f.endsAt ? date(f.endsAt) : 'permanent' }}: {{ f.reason }}
              <span class="tiny muted"
                >· by {{ f.by
                }}<template v-if="f.liftedAt">
                  · lifted {{ date(f.liftedAt) }} by {{ f.liftedBy }}: {{ f.liftReason }}</template
                ></span
              >
            </li>
          </ul>
        </details>
      </article>

      <!-- Activity -->
      <article id="activity">
        <h2>Activity</h2>
        <p class="counts">
          <span>{{ user.activity.counts.matches }} active matches</span>
          <span>{{ user.activity.counts.tripsFinished }} finished trips</span>
          <span>{{ user.activity.counts.reviewsWritten }} reviews written</span>
          <span>{{ user.activity.counts.reviewsReceived }} received</span>
          <span>{{ user.activity.counts.complaintsBy }} complaints made</span>
          <span :class="{ warn: user.activity.counts.complaintsAbout }"
            >{{ user.activity.counts.complaintsAbout }} about them</span
          >
        </p>
        <details>
          <summary>Latest bookings ({{ user.activity.bookings.length }})</summary>
          <table class="tiny">
            <tbody>
              <tr v-for="b in user.activity.bookings" :key="b.id">
                <td>#{{ b.id }}</td>
                <td>
                  {{ b.side === 'guest' ? 'Travelled with' : 'Guided' }}
                  <RouterLink :to="`/users/${b.other.id}`">{{
                    b.other.name || `#${b.other.id}`
                  }}</RouterLink>
                </td>
                <td>{{ b.status }}{{ b.status === 'confirmed' ? ` · ${b.tripStatus}` : '' }}</td>
                <td>{{ money(b.amount) }}</td>
                <td>{{ dateTime(b.meetingAt) }}</td>
              </tr>
            </tbody>
          </table>
        </details>
        <details>
          <summary>Latest reviews ({{ user.activity.reviews.length }})</summary>
          <ul class="records">
            <li v-for="r in user.activity.reviews" :key="r.id">
              {{ '★'.repeat(r.rating) }} {{ r.direction === 'written' ? 'about' : 'from' }}
              <RouterLink :to="`/users/${r.other.id}`">{{
                r.other.name || `#${r.other.id}`
              }}</RouterLink>
              <span class="tiny muted">· {{ date(r.createdAt) }}</span>
              <div v-if="r.comment" class="tiny">{{ r.comment }}</div>
            </li>
          </ul>
        </details>
        <details v-if="user.activity.complaints.length" open>
          <summary>Complaints ({{ user.activity.complaints.length }})</summary>
          <ul class="records">
            <li v-for="c in user.activity.complaints" :key="c.id">
              #{{ c.id }} {{ c.direction === 'by' ? 'they reported' : 'reported by' }}
              <RouterLink v-if="c.other" :to="`/users/${c.other.id}`">{{
                c.other.name || `#${c.other.id}`
              }}</RouterLink>
              — {{ c.title }}
              <span class="tiny muted">· {{ c.status }} · {{ date(c.createdAt) }}</span>
            </li>
          </ul>
        </details>
      </article>

      <!-- Security (admins) -->
      <article v-if="user.security" id="security" data-test="security">
        <h2>Security</h2>
        <h3>Browsers</h3>
        <table class="tiny">
          <tbody>
            <tr v-for="d in user.security.devices" :key="d.id">
              <td>{{ d.label || 'Unknown browser' }}</td>
              <td>{{ d.place ?? d.country ?? '—' }}</td>
              <td>{{ d.ip ?? '—' }}</td>
              <td>first {{ date(d.firstSeenAt) }}, last {{ dateTime(d.lastSeenAt) }}</td>
            </tr>
            <tr v-if="!user.security.devices.length">
              <td class="muted">None recorded.</td>
            </tr>
          </tbody>
        </table>
        <details>
          <summary>Sign-in history ({{ user.security.events.length }})</summary>
          <table class="tiny">
            <tbody>
              <tr v-for="e in user.security.events" :key="e.id">
                <td>{{ dateTime(e.at) }}</td>
                <td>{{ e.event }}</td>
                <td>{{ e.place ?? e.country ?? '' }} {{ e.ip ?? '' }}</td>
                <td>
                  <span v-if="e.riskScore" :class="{ warn: e.riskScore >= 40 }"
                    >risk {{ e.riskScore }}</span
                  >
                  {{ e.riskReasons?.join(', ') }}
                </td>
              </tr>
            </tbody>
          </table>
        </details>
        <h3>Linked accounts</h3>
        <ul class="records">
          <li v-for="l in user.security.links" :key="l.id">
            <RouterLink :to="`/users/${l.other.id}`">{{
              l.other.name || l.other.email
            }}</RouterLink>
            <span class="tiny muted">
              · {{ l.other.email }} · same {{ l.signal.replace('_', ' + ') }} (strength
              {{ l.strength }}, {{ l.hits }}×) · {{ date(l.lastSeenAt)
              }}{{ l.verdict ? ` · ${l.verdict}` : '' }}</span
            >
          </li>
          <li v-if="!user.security.links.length" class="muted">None.</li>
        </ul>
      </article>
    </template>
  </section>
</template>

<style scoped>
.hero {
  display: flex;
  gap: 1rem;
  align-items: center;
  margin-bottom: 1rem;
}

.hero h1 {
  margin: 0;
}

.hero p {
  margin: 0.25rem 0 0;
}

.photo {
  width: 5rem;
  height: 5rem;
  border-radius: 50%;
  object-fit: cover;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.photo.initial {
  background: var(--pico-muted-border-color);
  font-size: 2rem;
  font-weight: 700;
}

.pill {
  display: inline-block;
  margin-right: 0.35rem;
  padding: 0.05rem 0.5rem;
  border-radius: 1rem;
  font-size: 0.8rem;
  background: var(--pico-muted-border-color);
}

.pill.ok {
  background: #1f7a4d;
  color: #fff;
}

.pill.frozen {
  background: var(--pico-del-color);
  color: #fff;
  font-weight: 600;
}

.sections {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 0.5rem 0;
  background: var(--pico-background-color);
  border-bottom: 1px solid var(--pico-muted-border-color);
}

article {
  scroll-margin-top: 3.5rem;
}

article.alert {
  border: 2px solid var(--pico-del-color);
}

.roles {
  display: grid;
  gap: 1rem;
}

@media (min-width: 900px) {
  .roles {
    grid-template-columns: 1fr 1fr;
  }
}

dl {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.15rem 0.75rem;
}

dd {
  margin: 0;
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

.records {
  list-style: none;
  padding: 0;
}

.records li {
  list-style: none;
  padding: 0.3rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.records li.past,
tr.past {
  opacity: 0.6;
}

button.small {
  width: auto;
  margin: 0 0 0 0.5rem;
  padding: 0.1rem 0.6rem;
  font-size: 0.8rem;
}

.inline-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem;
  margin: 0.5rem 0 1rem;
}

.inline-form label {
  margin: 0;
  font-size: 0.85rem;
}

.inline-form input,
.inline-form select {
  margin: 0;
  padding-block: 0.3rem;
}

.inline-form input[type='number'] {
  width: 6.5rem;
}

.inline-form input.icon {
  width: 3.5rem;
  text-align: center;
}

.inline-form .grow {
  flex: 1 1 14rem;
}

.inline-form button {
  width: auto;
  margin: 0;
}

.inline-form .field-error {
  flex-basis: 100%;
}

.check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.field-error {
  color: var(--pico-del-color);
}

.booking-choice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--pico-muted-border-color);
}

.booking-choice input[type='number'] {
  width: 6rem;
  margin: 0;
}

.counts {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1.25rem;
}

.warn {
  color: var(--pico-del-color);
  font-weight: 600;
}

.actions {
  white-space: nowrap;
}

.actions input {
  width: 5.5rem;
  margin: 0;
  padding: 0.15rem 0.4rem;
}
</style>
