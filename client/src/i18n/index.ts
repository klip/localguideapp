import { computed, ref } from 'vue'
import en from './en.json'

/**
 * UI text. Every string lives in the `translations` table (see
 * `api/sql/018_translations.sql`), keyed `{page}.{widget}.{key}`, and is
 * fetched one language at a time through `api.translations()`.
 *
 * The database is the source of truth for every language, English included
 * (so a future CMS can edit it). `en.json` is a bundled copy of the English,
 * used only when the server's text isn't there: tests, a fetch that's slow or
 * fails, and any key a language hasn't translated yet — so a half-translated
 * language degrades to English key by key rather than to raw keys.
 *
 * State is module-level rather than a Pinia store so `t()` works anywhere —
 * templates, plain helpers, other stores — with nothing to install, and stays
 * reactive: a component re-renders when `messages` changes.
 */
/**
 * The languages travellers use most, merged with the languages of the most
 * visited destinations (UN Tourism source-market and arrivals data): the top
 * ten of each, deduplicated, plus Portuguese and Ukrainian. English first, then roughly by
 * combined weight; each labelled in its own language so it's findable
 * whatever the UI is showing. Arabic is right-to-left. Must match
 * `Translations::LANGUAGES` on the server.
 */
export const LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'es', label: 'Español' },
  { code: 'fr', label: 'Français' },
  { code: 'de', label: 'Deutsch' },
  { code: 'zh', label: '中文' },
  { code: 'it', label: 'Italiano' },
  { code: 'ja', label: '日本語' },
  { code: 'ru', label: 'Русский' },
  { code: 'ko', label: '한국어' },
  { code: 'ar', label: 'العربية', rtl: true },
  { code: 'tr', label: 'Türkçe' },
  { code: 'pt', label: 'Português' },
  { code: 'el', label: 'Ελληνικά' },
  { code: 'th', label: 'ไทย' },
  { code: 'uk', label: 'Українська' },
] as const

export type Language = (typeof LANGUAGES)[number]['code']

export type Messages = Record<string, string>

const STORAGE_KEY = 'rockguide.language'
const FALLBACK: Messages = en

const language = ref<Language>('en')
/** The current language's strings, from the database. */
const messages = ref<Messages>({})
/** English from the database — the fallback for keys a language hasn't translated. */
const english = ref<Messages>({})

export const currentLanguage = computed(() => language.value)

export function isLanguage(code: unknown): code is Language {
  return LANGUAGES.some((entry) => entry.code === code)
}

type Params = Record<string, string | number | null | undefined>

function interpolate(text: string, params?: Params) {
  if (!params) return text
  return text.replace(/\{(\w+)\}/g, (match, name: string) =>
    params[name] === undefined || params[name] === null ? match : String(params[name]),
  )
}

function lookup(key: string): string | undefined {
  return messages.value[key] ?? english.value[key] ?? FALLBACK[key]
}

/** The text for `key` in the current language, with `{placeholders}` filled from `params`. */
export function t(key: string, params?: Params): string {
  const text = lookup(key)
  if (text === undefined) {
    if (import.meta.env.DEV) console.warn(`[i18n] missing key: ${key}`)
    return key
  }
  return interpolate(text, params)
}

/**
 * A plural: picks `key_one` / `key_few` / `key_many` / `key_other` by the
 * current language's CLDR rules (Russian, Ukrainian and Polish need `few` and
 * `many`), falling back to `_other`. `{count}` is filled in automatically.
 */
export function tn(key: string, count: number, params?: Params): string {
  const category = new Intl.PluralRules(language.value).select(count)
  const full = { count, ...params }
  const exact = `${key}_${category}`
  return t(lookup(exact) !== undefined ? exact : `${key}_other`, full)
}

/** Saves `code` as this browser's choice — what a URL without a language falls back to. */
export function rememberLanguage(code: Language) {
  try {
    localStorage.setItem(STORAGE_KEY, code)
  } catch {
    // Not remembered — the next visit starts from the browser's language.
  }
}

/**
 * The language for a URL that doesn't name one: the stored choice, else the
 * browser's language if we offer it, else English. (A URL that does name one
 * always wins — see `router/index.ts`.)
 */
export function initialLanguage(): Language {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (isLanguage(stored)) return stored
  } catch {
    // Storage blocked — fall through to the browser's preference.
  }
  // The primary subtag: "pt-BR" → "pt", "fil-PH" → "fil".
  const browser =
    typeof navigator !== 'undefined' ? (navigator.language.split('-')[0] ?? '').toLowerCase() : ''
  return isLanguage(browser) ? browser : 'en'
}

function applyDocumentLanguage(code: Language) {
  if (typeof document === 'undefined') return
  const entry = LANGUAGES.find((item) => item.code === code)
  document.documentElement.lang = code
  document.documentElement.dir = entry && 'rtl' in entry && entry.rtl ? 'rtl' : 'ltr'
}

/**
 * Switches to `code`: fetches its strings with `fetcher` (the API call, passed
 * in so this module doesn't depend on the request layer), then swaps them in,
 * so the UI changes in one go rather than key by key. English is fetched like
 * any other language — the database copy wins over the bundled one. Remembered in
 * localStorage for the next visit. A failed fetch keeps the language choice
 * and falls back to English text (the database's, else the bundled copy).
 */
export async function setLanguage(code: Language, fetcher: (code: Language) => Promise<Messages>) {
  rememberLanguage(code)
  language.value = code
  applyDocumentLanguage(code)
  // English is the fallback layer for every other language, so it's fetched
  // once alongside the first non-English one (and is itself the first fetch
  // when English is chosen).
  const needEnglish = code !== 'en' && Object.keys(english.value).length === 0
  const [fetched, fetchedEnglish] = await Promise.all([
    fetcher(code).catch(() => ({}) as Messages),
    needEnglish ? fetcher('en').catch(() => ({}) as Messages) : Promise.resolve(null),
  ])
  if (fetchedEnglish) english.value = fetchedEnglish
  if (code === 'en') english.value = fetched
  // A later switch may have finished first; don't overwrite it.
  if (language.value === code) messages.value = fetched
}
