<script setup lang="ts">
import { ref } from 'vue'
import { ApiError, api } from '@/api'

const email = ref('')
const sent = ref(false)
const busy = ref(false)
const error = ref('')

async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await api.requestReset(email.value.trim())
    sent.value = true
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not send the link.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="narrow">
    <header>
      <h1>Forgot your password?</h1>
    </header>
    <p v-if="sent" class="notice" role="status">
      If that address belongs to an active staff member, a link to set a new password is on its way.
      It works once, for 30 minutes.
    </p>
    <form v-else @submit.prevent="submit">
      <p v-if="error" class="notice error" role="alert">{{ error }}</p>
      <label>
        Email
        <input v-model="email" type="email" autocomplete="username" required />
      </label>
      <button type="submit" :aria-busy="busy" :disabled="busy">Send the link</button>
    </form>
    <p class="tiny"><RouterLink to="/login">Back to sign-in</RouterLink></p>
  </article>
</template>
