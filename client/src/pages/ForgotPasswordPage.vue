<script setup lang="ts">
import { ref } from 'vue'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t } from '@/i18n'
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
    reportApiError(err, t('forgot_password.toast.failed'), anchorTo('element', formRef.value))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('forgot_password.header.eyebrow')"
      :heading="t('forgot_password.header.heading')"
    />

    <PanelCard v-if="sentTo" class="narrow" :title="t('forgot_password.sent.title')">
      <p>
        {{ t('forgot_password.sent.body', { email: sentTo }) }}
      </p>
      <p class="tiny muted">{{ t('forgot_password.sent.nothing_arrived') }}</p>
      <RouterLink to="/login" role="button" class="full">{{
        t('forgot_password.sent.back_to_login')
      }}</RouterLink>
    </PanelCard>

    <PanelCard
      v-else
      class="narrow"
      :title="t('forgot_password.form.title')"
      :subtitle="t('forgot_password.form.subtitle')"
    >
      <form ref="formRef" @submit.prevent="submit">
        <label>
          {{ t('common.form.email') }}
          <input
            v-model="email"
            type="email"
            autocomplete="email"
            :placeholder="t('common.form.email_placeholder')"
            required
          />
        </label>
        <button type="submit" class="full" :aria-busy="submitting" :disabled="submitting">
          {{ submitting ? t('forgot_password.form.sending') : t('forgot_password.form.submit') }}
        </button>
        <p class="tiny muted centred">
          {{ t('forgot_password.form.remembered') }}
          <RouterLink to="/login">{{ t('forgot_password.form.log_in') }}</RouterLink>
        </p>
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
