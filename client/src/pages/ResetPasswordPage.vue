<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { ApiError, useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'

/**
 * The page a reset email links to (`/reset-password?token=…`, see
 * `AccountSecurity::requestPasswordReset()`). Sets the new password with
 * `api.resetPassword()`; the server spends the link and signs the account
 * out everywhere, so this browser's cached session (if any) is cleared too
 * and the user logs in again with the new password.
 */
const route = useRoute()
const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()

const MIN_LENGTH = 8

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const tokenLooksValid = computed(() => /^[0-9a-f]{64}$/.test(token.value))

const newPassword = ref('')
const confirmPassword = ref('')
const submitting = ref(false)
const linkDead = ref(false)

const formRef = ref<HTMLFormElement | null>(null)
const newInputRef = ref<HTMLInputElement | null>(null)
const confirmInputRef = ref<HTMLInputElement | null>(null)

async function submit() {
  if (submitting.value) return

  if (newPassword.value.length < MIN_LENGTH) {
    messages.error(`Use at least ${MIN_LENGTH} characters.`, anchorTo('input', newInputRef.value))
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    messages.error('The two passwords don’t match.', anchorTo('input', confirmInputRef.value))
    return
  }

  submitting.value = true
  try {
    await api.resetPassword({ token: token.value, newPassword: newPassword.value })
    auth.clear()
    messages.success('Password changed — log in with your new password.')
    router.push('/login')
  } catch (err) {
    const text = err instanceof ApiError ? err.message : 'Could not change your password.'
    if (/invalid or has expired/i.test(text)) linkDead.value = true
    else messages.error(text, anchorTo('element', formRef.value))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle eyebrow="Account" heading="Choose a new password" />

    <PanelCard v-if="!tokenLooksValid || linkDead" class="narrow" title="This link doesn’t work">
      <p>
        Reset links work once, for 30 minutes, and only the newest one you asked for. Ask for a
        fresh one and use it straight away.
      </p>
      <RouterLink to="/forgot-password" role="button" class="full">Send a new link</RouterLink>
    </PanelCard>

    <PanelCard
      v-else
      class="narrow"
      title="New password"
      subtitle="You’ll be signed out everywhere."
    >
      <form ref="formRef" @submit.prevent="submit">
        <label>
          New password
          <input
            ref="newInputRef"
            v-model="newPassword"
            type="password"
            autocomplete="new-password"
            :minlength="MIN_LENGTH"
            required
          />
        </label>
        <label>
          Repeat new password
          <input
            ref="confirmInputRef"
            v-model="confirmPassword"
            type="password"
            autocomplete="new-password"
            :minlength="MIN_LENGTH"
            required
          />
        </label>
        <button type="submit" class="full" :aria-busy="submitting" :disabled="submitting">
          {{ submitting ? 'Saving…' : 'Set new password' }}
        </button>
      </form>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
label {
  margin-bottom: 1rem;
  display: block;
}

.full {
  width: 100%;
}
</style>
