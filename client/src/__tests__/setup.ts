import { afterEach } from 'vitest'
import { loadBundledEnglish } from '@/i18n'

// The bundled English is a lazily loaded chunk in the app (see src/i18n);
// tests render against it, so it's in place before any test runs.
await loadBundledEnglish()

// The deck's search point and "asked for location" live in sessionStorage
// (stores/filters.ts, LocationPrompt.vue); one test's must not leak into the next.
afterEach(() => {
  sessionStorage.clear()
})
