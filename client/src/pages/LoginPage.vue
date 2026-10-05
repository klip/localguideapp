<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import {
  ApiError,
  isTwoFactorChallenge,
  useApi,
  type AuthResult,
  type TwoFactorChallenge,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { useShortlistStore } from '@/stores/shortlist'
import { formMessagePlacement } from '@/utils/formMessagePlacement'

/**
 * Logging in is one step, or two when the account has two-factor on: the
 * password then earns a `TwoFactorChallenge` instead of a session, a 6-digit
 * code goes by email, and `api.verifyTwoFactor()` trades it for the session
 * (see `AccountSecurity.php`).
 */
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

const challenge = ref<TwoFactorChallenge | null>(null)
const code = ref('')
const codeInputRef = ref<HTMLInputElement | null>(null)
const resending = ref(false)
const resendIn = ref(0)
let resendTimer: ReturnType<typeof setInterval> | null = null

const codeComplete = computed(() => code.value.replace(/\D/g, '').length === 6)

function startResendCountdown(seconds: number) {
  resendIn.value = seconds
  if (resendTimer) clearInterval(resendTimer)
  resendTimer = setInterval(() => {
    resendIn.value = Math.max(0, resendIn.value - 1)
    if (resendIn.value === 0 && resendTimer) {
      clearInterval(resendTimer)
      resendTimer = null
    }
  }, 1000)
}

onBeforeUnmount(() => {
  if (resendTimer) clearInterval(resendTimer)
})

async function finish(user: AuthResult) {
  auth.setUser(user)

  // Restores real, DB-backed pack capacity that a fresh Pinia session
  // otherwise has no record of. Best-effort: login already succeeded, so
  // a hiccup here shouldn't block the user or need a toast of its own —
  // worst case they see "0 unlocked" and can revisit /unlock.
  try {
    const state = await api.paymentState()
    shortlist.applyPaymentState(state)
  } catch {
    // non-fatal — see comment above
  }

  // Guides never pay/unlock — land them straight on their own deck rather
  // than the visitor-side /discover (paywall box included), mirroring
  // RegisterPage.vue's role-based redirect.
  router.push(user.role === 'guide' ? '/guide/discover' : '/discover')
}

async function submit() {
  if (submitting.value) return
  submitting.value = true

  try {
    const result = await api.login({ email: email.value, password: password.value })
    if (isTwoFactorChallenge(result)) {
      challenge.value = result
      code.value = ''
      startResendCountdown(result.resendAfterSeconds)
      await nextTick()
      codeInputRef.value?.focus()
      return
    }
    await finish(result)
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

async function verify() {
  if (submitting.value || !challenge.value) return
  submitting.value = true

  try {
    const user = await api.verifyTwoFactor({
      challengeToken: challenge.value.challengeToken,
      code: code.value,
    })
    await finish(user)
  } catch (err) {
    const text = err instanceof ApiError ? err.message : 'Could not check that code.'
    messages.error(text, anchorTo('input', codeInputRef.value))
    // A spent challenge can't be retried — back to the password step.
    if (/log in again/i.test(text)) backToPassword()
    else code.value = ''
  } finally {
    submitting.value = false
  }
}

async function resend() {
  if (resending.value || resendIn.value > 0 || !challenge.value) return
  resending.value = true

  try {
    challenge.value = await api.resendTwoFactor({ challengeToken: challenge.value.challengeToken })
    code.value = ''
    startResendCountdown(challenge.value.resendAfterSeconds)
    messages.success(
      `New code sent to ${challenge.value.destination}.`,
      anchorTo('input', codeInputRef.value),
    )
  } catch (err) {
    const text = err instanceof ApiError ? err.message : 'Could not send a new code.'
    messages.error(text, anchorTo('input', codeInputRef.value))
    if (/log in again/i.test(text)) backToPassword()
  } finally {
    resending.value = false
  }
}

function backToPassword() {
  challenge.value = null
  code.value = ''
  password.value = ''
  if (resendTimer) clearInterval(resendTimer)
  resendTimer = null
  resendIn.value = 0
}
</script>

<template>
  <section class="section shell">
    <SectionTitle eyebrow="Screen 01" heading="Welcome back" />

    <PanelCard
      v-if="challenge"
      class="narrow"
      title="Check your email"
      :subtitle="`We sent a 6-digit code to ${challenge.destination}.`"
    >
      <form @submit.prevent="verify">
        <label>
          Login code
          <input
            ref="codeInputRef"
            v-model="code"
            class="code-input"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="\s*(\d\s*){6}"
            maxlength="7"
            placeholder="123456"
            required
          />
        </label>

        <button
          type="submit"
          class="full"
          :aria-busy="submitting"
          :disabled="submitting || !codeComplete"
        >
          {{ submitting ? 'Checking…' : 'Verify and log in' }}
        </button>
        <button
          type="button"
          class="soft-btn full"
          :aria-busy="resending"
          :disabled="resending || resendIn > 0"
          @click="resend"
        >
          {{ resendIn > 0 ? `Send a new code in ${resendIn}s` : 'Send a new code' }}
        </button>

        <p class="tiny muted centred">
          The code works for {{ Math.round(challenge.expiresInSeconds / 60) }} minutes.
          <button type="button" class="link-btn" @click="backToPassword">
            Use a different account
          </button>
        </p>
      </form>
    </PanelCard>

    <PanelCard v-else class="narrow" title="Log in" subtitle="Pick up where you left off.">
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
          <RouterLink to="/forgot-password" class="tiny forgot">Forgot password?</RouterLink>
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

.forgot {
  display: inline-block;
  margin-top: -0.4rem;
  font-weight: 400;
}

.code-input {
  font-size: 1.4rem;
  letter-spacing: 0.4em;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.link-btn {
  display: inline;
  width: auto;
  margin: 0;
  padding: 0;
  border: 0;
  background: none;
  color: var(--pico-primary);
  font-size: inherit;
  text-decoration: underline;
  cursor: pointer;
}
</style>
