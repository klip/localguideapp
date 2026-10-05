<script setup lang="ts">
import { ref } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { useApi } from '@/plugins/api'
import { anchorTo } from '@/stores/messages'
import { reportApiError } from '@/utils/reportApiError'

/**
 * "Forgot password?" — asks `api.requestPasswordReset()` to email a reset
 * link. The answer is the same whether or not the address has an account
 * (see `AccountSecurity::requestPasswordReset()`), so the page never says
 * which.
 */
const api = useApi()

const email = ref('')
const submitting = ref(false)
const sentTo = ref<string | null>(null)
const formRef = ref<HTMLFormElement | null>(null)

async function submit() {
  if (submitting.value) return
  submitting.value = true

  try {
    await api.requestPasswordReset({ email: email.value.trim() })
    sentTo.value = email.value.trim()
  } catch (err) {
    reportApiError(err, 'Could not send the reset email.', anchorTo('element', formRef.value))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle eyebrow="Account" heading="Forgot your password?" />

    <PanelCard v-if="sentTo" class="narrow" title="Check your email">
      <p>
        If <strong>{{ sentTo }}</strong> belongs to a RockGuide account, we’ve sent it a link to
        choose a new password. The link works once, for 30 minutes.
      </p>
      <p class="tiny muted">Nothing arrived? Check your spam folder, or try again in a minute.</p>
      <RouterLink to="/login" role="button" class="full">Back to log in</RouterLink>
    </PanelCard>

    <PanelCard
      v-else
      class="narrow"
      title="Reset your password"
      subtitle="We’ll email you a link to choose a new one."
    >
      <form ref="formRef" @submit.prevent="submit">
        <label>
          Email
          <input
            v-model="email"
            type="email"
            autocomplete="email"
            placeholder="you@example.com"
            required
          />
        </label>
        <button type="submit" class="full" :aria-busy="submitting" :disabled="submitting">
          {{ submitting ? 'Sending…' : 'Email me a reset link' }}
        </button>
        <p class="tiny muted centred">Remembered it? <RouterLink to="/login">Log in</RouterLink></p>
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

.centred {
  text-align: center;
  margin-bottom: 0;
}
</style>
