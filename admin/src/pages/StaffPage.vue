<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, api, session, type StaffMember, type StaffRole } from '@/api'

/**
 * Admins only (`staff.manage`): who has CMS access, inviting someone (they
 * get a set-password link by email), changing a role, disabling. A change
 * ends that member's sign-ins at once; nobody can change their own role or
 * disable themselves, and there's always an active admin (the server
 * enforces both). Every change is in the audit log.
 */
const ROLES: StaffRole[] = ['admin', 'manager', 'editor']

const staff = ref<StaffMember[]>([])
const loading = ref(true)
const error = ref('')
const notice = ref('')
const confirmingDisable = ref<number | null>(null)
const invite = reactive({ email: '', name: '', role: 'editor' as StaffRole, busy: false })

async function load() {
  try {
    staff.value = (await api.staffList()).staff
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not load the staff list.'
  } finally {
    loading.value = false
  }
}

function replace(member: StaffMember) {
  staff.value = staff.value.map((m) => (m.id === member.id ? member : m))
}

async function run(action: () => Promise<void>, done: string) {
  error.value = ''
  notice.value = ''
  try {
    await action()
    notice.value = done
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'That didn’t work.'
    await load()
  }
}

function sendInvite() {
  if (invite.busy) return
  invite.busy = true
  void run(async () => {
    const { staff: created } = await api.staffInvite({
      email: invite.email.trim(),
      name: invite.name.trim(),
      role: invite.role,
    })
    staff.value = [created, ...staff.value]
    invite.email = ''
    invite.name = ''
  }, 'Invited — they’ll get an email with a link to set their password.').finally(() => {
    invite.busy = false
  })
}

function changeRole(member: StaffMember, role: StaffRole) {
  void run(
    async () => replace((await api.staffUpdate(member.id, { role })).staff),
    `${member.email} is now ${role}.`,
  )
}

function setDisabled(member: StaffMember, disabled: boolean) {
  confirmingDisable.value = null
  void run(
    async () => replace((await api.staffUpdate(member.id, { disabled })).staff),
    disabled ? `${member.email} is disabled and signed out.` : `${member.email} can sign in again.`,
  )
}

function resendInvite(member: StaffMember) {
  void run(async () => {
    await api.staffResendInvite(member.id)
  }, `A new link is on its way to ${member.email}.`)
}

function when(iso: string | null) {
  return iso ? new Date(iso).toLocaleString() : '—'
}

onMounted(load)
</script>

<template>
  <section>
    <h1>Staff</h1>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>
    <p v-if="notice" class="notice" role="status" data-test="notice">{{ notice }}</p>

    <details open>
      <summary>Invite someone</summary>
      <form class="grid" data-test="invite" @submit.prevent="sendInvite">
        <input
          v-model="invite.email"
          type="email"
          placeholder="Email"
          aria-label="Email"
          required
        />
        <input v-model="invite.name" type="text" placeholder="Name" aria-label="Name" />
        <select v-model="invite.role" aria-label="Role">
          <option v-for="role in ROLES" :key="role" :value="role">{{ role }}</option>
        </select>
        <button type="submit" :aria-busy="invite.busy" :disabled="invite.busy">Invite</button>
      </form>
    </details>

    <p v-if="loading" aria-busy="true" class="muted">Loading…</p>
    <div v-else class="overflow-auto">
      <table class="striped" data-test="staff-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last sign-in</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="member in staff" :key="member.id" :data-staff="member.id">
            <td>{{ member.name || '—' }}</td>
            <td>{{ member.email }}</td>
            <td>
              <select
                :value="member.role"
                :disabled="member.id === session.me?.id || member.disabled"
                :aria-label="`Role of ${member.email}`"
                @change="
                  changeRole(member, ($event.target as HTMLSelectElement).value as StaffRole)
                "
              >
                <option v-for="role in ROLES" :key="role" :value="role">{{ role }}</option>
              </select>
            </td>
            <td>
              <span v-if="member.disabled">Disabled</span>
              <span v-else-if="!member.hasPassword">Invited</span>
              <span v-else>Active</span>
            </td>
            <td class="tiny">{{ when(member.lastSignInAt) }}</td>
            <td class="actions">
              <template v-if="member.id !== session.me?.id">
                <button
                  v-if="!member.disabled && !member.hasPassword"
                  type="button"
                  class="secondary outline"
                  @click="resendInvite(member)"
                >
                  Resend invite
                </button>
                <button
                  v-if="member.disabled"
                  type="button"
                  class="secondary outline"
                  @click="setDisabled(member, false)"
                >
                  Enable
                </button>
                <template v-else-if="confirmingDisable === member.id">
                  <button
                    type="button"
                    class="contrast"
                    data-test="confirm-disable"
                    @click="setDisabled(member, true)"
                  >
                    Confirm: disable
                  </button>
                  <button type="button" class="secondary outline" @click="confirmingDisable = null">
                    Keep
                  </button>
                </template>
                <button
                  v-else
                  type="button"
                  class="secondary outline"
                  data-test="disable"
                  @click="confirmingDisable = member.id"
                >
                  Disable
                </button>
              </template>
              <span v-else class="tiny muted">You</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.actions {
  white-space: nowrap;
}

.actions button {
  margin: 0 0.25rem 0 0;
  padding: 0.3rem 0.6rem;
  font-size: 0.85rem;
}

td select {
  margin: 0;
  padding-block: 0.3rem;
}
</style>
