<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import { ApiError, useApi } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { anchorTo, useMessagesStore } from '@/stores/messages'
import { formMessagePlacement } from '@/utils/formMessagePlacement'

const route = useRoute()
const router = useRouter()
const api = useApi()
const auth = useAuthStore()
const messages = useMessagesStore()

/** `/register?role=guide` (from the "I'm a guide" CTA) vs. the default visitor flow. */
const role = computed(() => (route.query.role === 'guide' ? 'guide' : 'guest'))
const isGuide = computed(() => role.value === 'guide')
const heading = computed(() => (isGuide.value ? 'Create your guide account' : 'Create your visitor account'))
const url = computed(() => (!isGuide.value ? '/register?role=guide' : '/register'))

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
    const user = await api.register({
      email: email.value,
      password: password.value,
      role: role.value,
    })
    auth.setUser(user)
    messages.success('Account created — welcome to RockGuide.', anchorTo('element', formRef.value))
    router.push(isGuide.value ? '/guide/discover' : '/unlock')
  } catch (err) {
    const text = err instanceof ApiError ? err.message : 'Could not create your account.'
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
    <SectionTitle eyebrow="Screen 01" :heading="heading" />

    <PanelCard class="narrow" :title="heading" subtitle="Takes less than a minute.">
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
            autocomplete="new-password"
            placeholder="••••••••"
            required
          />
        </label>

        <button type="submit" class="full" :aria-busy="submitting" :disabled="submitting">
          {{ submitting ? 'Creating account…' : 'Continue' }}
        </button>
        <button type="button" class="soft-btn full">Continue with Apple</button>

        <p class="tiny muted centred">
          Already registered? <RouterLink to="/login">Log in</RouterLink>
        </p>

        <p class="tiny muted centred">
           <RouterLink :to="url" class="secondary">{{ isGuide ? "I am visiting" : "I am a guide"}}</RouterLink>
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
