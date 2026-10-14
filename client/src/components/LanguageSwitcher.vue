<script setup lang="ts">
import { useRoute } from 'vue-router'
import { currentLanguage, isLanguage, LANGUAGES, rememberLanguage, t } from '@/i18n'

/**
 * The header's language picker. Each option is labelled in its own language
 * (so it's findable whatever the UI is currently showing). The language is
 * the URL's first segment (see `router/index.ts`), so switching saves the
 * choice and reloads the same page under the new prefix — `/es/matches` →
 * `/fr/matches` — where main.ts fetches that language before mounting.
 */
const route = useRoute()

function onChange(event: Event) {
  const code = (event.target as HTMLSelectElement).value
  if (!isLanguage(code) || code === currentLanguage.value) return
  rememberLanguage(code)
  window.location.assign(`${import.meta.env.BASE_URL}${code}${route.fullPath}`)
}
</script>

<template>
  <select
    class="language-switcher"
    :value="currentLanguage"
    :aria-label="t('common.language_switcher.label')"
    @change="onChange"
  >
    <option v-for="language in LANGUAGES" :key="language.code" :value="language.code" :lang="language.code">
      {{ language.label }}
    </option>
  </select>
</template>

<style scoped lang="scss">
.language-switcher {
  width: auto;
  margin: 0;
  padding: 0.3rem 2rem 0.3rem 0.6rem;
  font-size: 0.85rem;
}
</style>
