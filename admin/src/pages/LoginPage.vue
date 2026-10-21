<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api, type CodeChallenge } from '@/api'

/**
 * Staff sign-in: the password, then — always — a 6-digit code sent by email
 * (`CmsAuthHttp`). There's no "remember me": a staff sign-in lasts 12 hours.
 */
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const code = ref('')
const challenge = ref<CodeChallenge | null>(null)
const busy = ref(false)
const error = ref('')

async function submitPassword() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    challenge.value = await api.login(email.value.trim(), password.value)
    password.value = ''
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not sign in.'
  } finally {
    busy.value = false
  }
}

async function submitCode() {
  if (busy.value || !challenge.value) return
  busy.value = true
  error.value = ''
  try {
    await api.verifyCode(challenge.value.challengeToken, code.value)
    await router.replace({ name: 'home' })
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not sign in.'
    if (/expired|sign in again/i.test(error.value)) {
      challenge.value = null
      code.value = ''
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="narrow">
    <header>
      <h1>BeLikeLocal CMS</h1>
      <p class="muted">Staff sign-in</p>
    </header>

    <p v-if="route.query.passwordSet && !challenge" class="notice" data-test="password-set">
      Your password is set. Sign in with it.
    </p>
    <p v-if="error" class="notice error" role="alert" data-test="error">{{ error }}</p>

    <form v-if="!challenge" data-test="password-step" @submit.prevent="submitPassword">
      <label>
        Email
        <input v-model="email" type="email" autocomplete="username" required />
      </label>
      <label>
        Password
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <button type="submit" :aria-busy="busy" :disabled="busy">Continue</button>
      <p class="tiny"><RouterLink to="/forgot-password">Forgot your password?</RouterLink></p>
    </form>

    <form v-else data-test="code-step" @submit.prevent="submitCode">
      <p>
        We emailed you a 6-digit code. It works for
        {{ Math.round(challenge.expiresInSeconds / 60) }} minutes.
      </p>
      <label>
        Code
        <input
          v-model="code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="7"
          required
        />
      </label>
      <button type="submit" :aria-busy="busy" :disabled="busy">Sign in</button>
      <button type="button" class="secondary outline" @click="challenge = null">Back</button>
    </form>
  </article>
</template>
