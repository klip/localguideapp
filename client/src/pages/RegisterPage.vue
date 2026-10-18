<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PanelCard from '@/components/PanelCard.vue'
import SectionTitle from '@/components/SectionTitle.vue'
import TermsDialog from '@/components/TermsDialog.vue'
import { t } from '@/i18n'
import {
  ApiError,
  roleAgreementSlug,
  useApi,
  type LegalDocumentView,
  type LegalSlug,
} from '@/plugins/api'
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
const heading = computed(() =>
  isGuide.value ? t('register.header.guide_heading') : t('register.header.visitor_heading'),
)
const url = computed(() => (!isGuide.value ? '/register?role=guide' : '/register'))

const email = ref('')
const password = ref('')
const submitting = ref(false)

const formRef = ref<HTMLFormElement | null>(null)
const emailInputRef = ref<HTMLInputElement | null>(null)
const passwordInputRef = ref<HTMLInputElement | null>(null)

/**
 * "Continue" doesn't create the account: it opens the terms and conditions
 * (fetched only now, in the page's language), and "Accept" registers —
 * sending the version that was shown, which the server records as accepted
 * (and refuses if a newer one has been published since).
 */
const termsOpen = ref(false)
const termsDialog = ref<InstanceType<typeof TermsDialog> | null>(null)

function submit() {
  if (submitting.value) return
  termsOpen.value = true
}

/** The terms, then the starting role's agreement — both accepted together. */
const legalSlugs = computed<LegalSlug[]>(() => ['terms', roleAgreementSlug(role.value)])

async function accept(documents: LegalDocumentView[] | null) {
  const [terms, agreement] = documents ?? []
  if (submitting.value || !terms || !agreement) return
  submitting.value = true

  try {
    const user = await api.register({
      email: email.value,
      password: password.value,
      role: role.value,
      termsVersion: terms.version,
      agreementSlug: agreement.slug as LegalSlug,
      agreementVersion: agreement.version,
      language: terms.language,
    })
    termsOpen.value = false
    auth.setUser(user)
    messages.success(t('register.toast.created'), anchorTo('element', formRef.value))
    // A guide's card is empty until they fill it in, so they start on their
    // profile. A visitor lands back on the deck, which asks for the first
    // pack itself.
    router.push(isGuide.value ? '/profile' : '/discover')
  } catch (err) {
    // Back to the form, where the message points at the field to fix. The
    // terms are fetched again next time, in case a newer version was the
    // problem.
    termsOpen.value = false
    termsDialog.value?.forget()
    const text = err instanceof ApiError ? err.message : t('register.toast.failed')
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
    <SectionTitle :eyebrow="t('register.header.eyebrow')" :heading="heading" />

    <PanelCard class="narrow" :title="heading" :subtitle="t('register.form.subtitle')">
      <form ref="formRef" @submit.prevent="submit">
        <label>
          {{ t('common.form.email') }}
          <input
            ref="emailInputRef"
            v-model="email"
            type="email"
            autocomplete="email"
            :placeholder="t('common.form.email_placeholder')"
            required
          />
        </label>
        <label>
          {{ t('common.form.password') }}
          <input
            ref="passwordInputRef"
            v-model="password"
            type="password"
            autocomplete="new-password"
            placeholder="••••••••"
            required
          />
        </label>

        <button type="submit" class="full" :disabled="submitting">
          {{ t('register.form.submit') }}
        </button>
        <button type="button" class="soft-btn full">
          {{ t('common.form.continue_with_apple') }}
        </button>

        <p class="tiny muted centred">
          {{ t('register.form.already_registered') }}
          <RouterLink to="/login">{{ t('register.form.log_in') }}</RouterLink>
        </p>

        <p class="tiny muted centred">
          <RouterLink :to="url" class="secondary">{{
            isGuide ? t('register.form.switch_to_visitor') : t('register.form.switch_to_guide')
          }}</RouterLink>
        </p>
      </form>
    </PanelCard>

    <TermsDialog ref="termsDialog" :open="termsOpen" :slugs="legalSlugs" @close="termsOpen = false">
      <template #intro>
        <p class="muted">{{ t('register.terms.intro') }}</p>
      </template>
      <template #footer="{ documents }">
        <button
          type="button"
          class="secondary outline"
          :disabled="submitting"
          @click="termsOpen = false"
        >
          {{ t('register.terms.cancel') }}
        </button>
        <button
          type="button"
          class="accept"
          :disabled="!documents || submitting"
          :aria-busy="submitting"
          @click="accept(documents)"
        >
          {{ submitting ? t('register.form.submitting') : t('register.terms.accept') }}
        </button>
      </template>
    </TermsDialog>
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
