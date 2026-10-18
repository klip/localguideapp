<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import LegalDocument from '@/components/LegalDocument.vue'
import ModalDialog from '@/components/ModalDialog.vue'
import { currentLanguage, t } from '@/i18n'
import { useApi, type LegalDocumentView, type LegalSlug } from '@/plugins/api'

/**
 * Legal documents in a wide `ModalDialog`, fetched the first time it opens —
 * never before — in the page's language. A failed fetch offers Retry.
 *
 * `slugs` picks what's shown, in order (default: the terms and conditions,
 * with the privacy notice). Registration shows the terms plus the starting
 * role's agreement; adding a role on the profile page shows just that role's
 * agreement. The first document titles the dialog; each later one gets its
 * own heading.
 *
 * The `#footer` slot gets `{ documents, terms }` — every document once all
 * have loaded (else null), and the first of them: registration and the
 * profile page put Cancel / Accept there; without it the footer is a plain
 * Close. `section` ("9", "9.8") scrolls to that heading of the first
 * document once shown. `forget()` drops the loaded copies so the next
 * opening fetches them again.
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    section?: string | null
    slugs?: LegalSlug[]
  }>(),
  { section: null, slugs: () => ['terms'] },
)

const emit = defineEmits<{ close: [] }>()

const api = useApi()
const loaded = ref<LegalDocumentView[] | null>(null)
const loading = ref(false)
const failed = ref(false)
const body = ref<HTMLElement | null>(null)

const first = computed(() => loaded.value?.[0] ?? null)

async function load() {
  loading.value = true
  failed.value = false
  try {
    loaded.value = await Promise.all(
      props.slugs.map((slug) => api.legalDocument(slug, currentLanguage.value)),
    )
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function scrollToSection() {
  if (!props.section || !loaded.value) return
  await nextTick()
  body.value
    ?.querySelector(`[data-section="${props.section}"]`)
    ?.scrollIntoView?.({ block: 'start' })
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    if (!loaded.value && !loading.value) await load()
    await scrollToSection()
  },
  { immediate: true },
)

// A different set of documents (e.g. the role changed) needs a fresh fetch.
watch(
  () => props.slugs.join(','),
  () => {
    loaded.value = null
  },
)

defineExpose({
  forget() {
    loaded.value = null
  },
})
</script>

<template>
  <ModalDialog
    :open="open"
    :title="first?.title ?? t('common.terms_dialog.title')"
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
      <template v-else-if="loaded">
        <section v-for="(doc, index) in loaded" :key="doc.slug" class="legal-doc">
          <h2 v-if="index > 0">{{ doc.title }}</h2>
          <LegalDocument :body="doc.body" />
        </section>
      </template>
    </div>

    <template #footer>
      <slot name="footer" :documents="loaded" :terms="first">
        <button type="button" class="secondary outline" @click="emit('close')">
          {{ t('common.terms_dialog.close') }}
        </button>
      </slot>
    </template>
  </ModalDialog>
</template>

<style scoped lang="scss">
.legal-doc + .legal-doc {
  margin-top: 2rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--rg-line);
}
</style>
