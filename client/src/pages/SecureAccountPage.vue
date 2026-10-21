<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { t } from '@/i18n'
import { useApi } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'

/**
 * Where "Not you? Secure your account" leads, from a new-sign-in email
 * (`/secure-account?token=…`, see `AccountSecurity::notifyNewSignIn()`).
 * Redeeming the link ends every sign-in of the account — this browser's too
 * — and forgets every remembered browser; the server hands back a one-time
 * reset token, so the next step is choosing a new password straight away.
 */
const route = useRoute()
const router = useRouter()
const api = useApi()
const messages = useMessagesStore()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const failed = ref(!/^[0-9a-f]{64}$/.test(token.value))

onMounted(async () => {
  if (failed.value) return
  try {
    const { resetToken } = await api.secureAccount(token.value)
    messages.success(t('secure_account.toast.secured'))
    await router.replace({ path: '/reset-password', query: { token: resetToken } })
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <section class="section shell">
    <SectionTitle
      :eyebrow="t('secure_account.header.eyebrow')"
      :heading="t('secure_account.header.heading')"
    />

    <PanelCard v-if="failed" class="narrow" :title="t('secure_account.failed.title')">
      <p>{{ t('secure_account.failed.body') }}</p>
      <RouterLink to="/forgot-password" role="button" class="full">{{
        t('secure_account.failed.reset')
      }}</RouterLink>
    </PanelCard>

    <PanelCard v-else class="narrow" :title="t('secure_account.working.title')">
      <p aria-busy="true">{{ t('secure_account.working.body') }}</p>
    </PanelCard>
  </section>
</template>

<style scoped lang="scss">
.full {
  width: 100%;
}
</style>
