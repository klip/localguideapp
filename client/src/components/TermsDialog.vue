<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import LegalDocument from '@/components/LegalDocument.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import { currentLanguage, t } from '@/i18n'
import { useApi, type LegalDocumentView } from '@/plugins/api'

/**
 * The terms and conditions (with the privacy notice) in a wide
 * `ModalDialog`, fetched the first time it opens — never before — in the
 * page's language. A failed fetch offers Retry.
 *
 * The `#footer` slot gets `{ terms }` (null until loaded): registration puts
 * Cancel / Accept there; without it the footer is a plain Close. `section`
 * ("9", "9.8") scrolls to that heading once the text is shown. `forget()`
 * drops the loaded copy so the next opening fetches it again.
 */
const props = defineProps<{
  open: boolean
  section?: string | null
}>()

const emit = defineEmits<{ close: [] }>()

const api = useApi()
const terms = ref<LegalDocumentView | null>(null)
const loading = ref(false)
const failed = ref(false)
const body = ref<HTMLElement | null>(null)

async function load() {
  loading.value = true
  failed.value = false
  try {
    terms.value = await api.legalDocument('terms', currentLanguage.value)
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function scrollToSection() {
  if (!props.section || !terms.value) return
  await nextTick()
  body.value
    ?.querySelector(`[data-section="${props.section}"]`)
    ?.scrollIntoView?.({ block: 'start' })
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    if (!terms.value && !loading.value) await load()
    await scrollToSection()
  },
  { immediate: true },
)

defineExpose({
  forget() {
    terms.value = null
  },
})
</script>

<template>
  <ModalDialog
    :open="open"
    :title="terms?.title ?? t('common.terms_dialog.title')"
    wide
    @close="emit('close')"
  >
    <slot name="intro" />
    <div ref="body">
      <p v-if="loading" aria-busy="true">{{ t('common.terms_dialog.loading') }}</p>
      <div v-else-if="failed" role="alert">
        <p>{{ t('common.terms_dialog.load_failed') }}</p>
        <button type="button" class="secondary" @click="load">
          {{ t('common.terms_dialog.retry') }}
        </button>
      </div>
      <LegalDocument v-else-if="terms" :body="terms.body" />
    </div>

    <template #footer>
      <slot name="footer" :terms="terms">
        <button type="button" class="secondary outline" @click="emit('close')">
          {{ t('common.terms_dialog.close') }}
        </button>
      </slot>
    </template>
  </ModalDialog>
</template>
