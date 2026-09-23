<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { ApiError, useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'
import { formMessagePlacement } from '@/utils/formMessagePlacement'

const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()
const shortlist = useShortlistStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)

const formRef = ref<HTMLFormElement | null>(null)
const emailInputRef = ref<HTMLInputElement | null>(null)
const passwordInputRef = ref<HTMLInputElement | null>(null)

async function submit() {
  if (submitting.value) return
  submitting.value = true

  try {
    const user = await api.login({ email: email.value, password: password.value })
    auth.setUser(user)

    // Restores real, DB-backed pack capacity that a fresh Pinia session
    // otherwise has no record of. Best-effort: login already succeeded, so
    // a hiccup here shouldn't block the user or need a toast of its own —
    // worst case they see "0 unlocked" and can revisit /unlock.
    try {
      const state = await api.paymentState()
      shortlist.setPacksUnlocked(state.packsUnlocked)
    } catch {
      // non-fatal — see comment above
    }

    // Guides never pay/unlock — land them straight on their own deck rather
    // than the visitor-side /discover (paywall box included), mirroring
    // RegisterPage.vue's role-based redirect.
    router.push(user.role === 'guide' ? '/guide/discover' : '/discover')
  } catch (err) {
    const text = err instanceof ApiError ? err.message : 'Could not log you in.'
    messages.error(
      text,
      formMessagePlacement(text, {
        email: emailInputRef.value,
        password: passwordInputRef.value,
        form: formRef.value,
      }),
    )
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="section shell">
    <SectionTitle eyebrow="Screen 01" heading="Welcome back" />

    <PanelCard class="narrow" title="Log in" subtitle="Pick up where you left off.">
      <form ref="formRef" @submit.prevent="submit">
        <label>
          Email
          <input
            ref="emailInputRef"
            v-model="email"
            type="email"
            autocomplete="email"
            placeholder="you@example.com"
            required
          />
        </label>
        <label>
          Password
          <input
            ref="passwordInputRef"
            v-model="password"
            type="password"
            autocomplete="current-password"
            placeholder="••••••••"
            required
          />
        </label>

        <button type="submit" class="full" :aria-busy="submitting" :disabled="submitting">
          {{ submitting ? 'Logging in…' : 'Log in' }}
        </button>
        <button type="button" class="soft-btn full">Continue with Apple</button>

        <p class="tiny muted centred">
          New here? <RouterLink to="/register">Create an account</RouterLink>
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

button {
  margin-bottom: 0.5rem;
}

.centred {
  text-align: center;
  margin-bottom: 0;
}
</style>
