<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t } from '@/i18n'
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
    messages.error(
      t('reset_password.toast.too_short', { count: MIN_LENGTH }),
      anchorTo('input', newInputRef.value),
    )
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    messages.error(t('reset_password.toast.mismatch'), anchorTo('input', confirmInputRef.value))
    return
  }

  submitting.value = true
  try {
    await api.resetPassword({ token: token.value, newPassword: newPassword.value })
    auth.clear()
    messages.success(t('reset_password.toast.changed'))
    router.push('/login')
  } catch (err) {
    const text = err instanceof ApiError ? err.message : t('reset_password.toast.failed')
    if (/invalid or has expired/i.test(text)) linkDead.value = true
    else messages.error(text, anchorTo('element', formRef.value))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('reset_password.header.eyebrow')"
      :heading="t('reset_password.header.heading')"
    />

    <PanelCard
      v-if="!tokenLooksValid || linkDead"
      class="narrow"
      :title="t('reset_password.dead_link.title')"
    >
      <p>
        {{ t('reset_password.dead_link.body') }}
      </p>
      <RouterLink to="/forgot-password" role="button" class="full">{{ t('reset_password.dead_link.send_new') }}</RouterLink>
    </PanelCard>

    <PanelCard
      v-else
      class="narrow"
      :title="t('reset_password.form.title')"
      :subtitle="t('reset_password.form.subtitle')"
    >
      <form ref="formRef" @submit.prevent="submit">
        <label>
          {{ t('reset_password.form.new_password') }}
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
          {{ t('reset_password.form.repeat_password') }}
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
          {{ submitting ? t('reset_password.form.saving') : t('reset_password.form.submit') }}
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
