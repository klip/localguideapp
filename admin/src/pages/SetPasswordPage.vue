<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api } from '@/api'

/**
 * Where an invite or a reset email leads (`/set-password?token=…`, see
 * `Staff::createSetupLink()`): choose a password, then sign in with it.
 */
const MIN_LENGTH = 12
const route = useRoute()
const router = useRouter()
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))

const password = ref('')
const repeat = ref('')
const busy = ref(false)
const error = ref('')

async function submit() {
  if (busy.value) return
  error.value = ''
  if (password.value.length < MIN_LENGTH) {
    error.value = `Use at least ${MIN_LENGTH} characters.`
    return
  }
  if (password.value !== repeat.value) {
    error.value = 'The two passwords don’t match.'
    return
  }
  busy.value = true
  try {
    await api.setPassword(token.value, password.value)
    await router.replace({ name: 'login', query: { passwordSet: '1' } })
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not set the password.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="narrow">
    <header>
      <h1>Choose your password</h1>
      <p class="muted">
        At least {{ MIN_LENGTH }} characters. Every sign-in also needs a code we email you.
      </p>
    </header>
    <p v-if="!/^[0-9a-f]{64}$/.test(token)" class="notice error" role="alert">
      This link is incomplete. Use the one from your email, or
      <RouterLink to="/forgot-password">ask for a new one</RouterLink>.
    </p>
    <form v-else @submit.prevent="submit">
      <p v-if="error" class="notice error" role="alert">{{ error }}</p>
      <label>
        New password
        <input v-model="password" type="password" autocomplete="new-password" required />
      </label>
      <label>
        Repeat it
        <input v-model="repeat" type="password" autocomplete="new-password" required />
      </label>
      <button type="submit" :aria-busy="busy" :disabled="busy">Set password</button>
    </form>
  </article>
</template>
