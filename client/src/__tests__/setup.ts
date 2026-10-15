import { loadBundledEnglish } from '@/i18n'

// The bundled English is a lazily loaded chunk in the app (see src/i18n);
// tests render against it, so it's in place before any test runs.
await loadBundledEnglish()
