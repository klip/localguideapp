import { computed, ref } from 'vue'
import scopeMap from './scopes.json'

/**
 * UI text. Every string lives in the `translations` table (see
 * `api/sql/018_translations.sql`), keyed `{page}.{widget}.{key}`, and is
 * fetched one language at a time through `api.translations()`.
 *
 * **Loaded in two steps.** At boot only the text the first page needs is
 * fetched — the shell's widgets plus that route's (`scopes.json`, generated
 * by `scripts/i18n-scopes.mjs` from the imports of App.vue and each page) —
 * and the rest of the language follows in the background. A navigation
 * before that arrives waits for it briefly (`ensureScopes()`, from the
 * router). The legal text isn't UI text: it's fetched only when shown.
 *
 * The database is the source of truth for every language, English included
 * (so a future CMS can edit it). Its English is the fallback for keys a
 * language hasn't translated (fetched in the background too). `en.json` is
 * a bundled copy of the English, in its own chunk, loaded only when a key is
 * missing from both — a failed or slow fetch, a key not yet in the database,
 * tests — so a half-translated language degrades to English key by key
 * rather than to raw keys.
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

/** What a `translations` fetch asks for: key prefixes to fetch only, or to leave out. */
export interface Scope {
  scopes?: readonly string[]
  except?: readonly string[]
}

/** Fetches strings for a language — the API call, passed in so this module doesn't depend on the request layer. */
export type Fetcher = (code: Language, scope?: Scope) => Promise<Messages>

const STORAGE_KEY = 'rockguide.language'
/** How long a navigation waits for the rest of the language before rendering with what it has. */
const NAVIGATION_WAIT_MS = 1500

const language = ref<Language>('en')
/** The current language's strings, from the database. */
const messages = ref<Messages>({})
/** English from the database — the fallback for keys a language hasn't translated. */
const english = ref<Messages>({})
/** The bundled `en.json`, once its chunk has loaded (see `loadBundledEnglish()`). */
const bundled = ref<Messages>({})
let bundledLoad: Promise<void> | null = null

/** Which prefixes of the current language are in `messages` — `'all'` once the background fetch landed. */
let loaded: Set<string> | 'all' = new Set()
/** The background fetch of the rest of the language, while it runs. */
let rest: Promise<void> = Promise.resolve()

/** The shell's prefixes plus `routeName`'s — what that page needs to render. */
export function scopesForRoute(routeName: unknown): string[] {
  const routes = scopeMap.routes as Record<string, string[]>
  return [...scopeMap.shell, ...(typeof routeName === 'string' ? (routes[routeName] ?? []) : [])]
}

/**
 * Loads the bundled English (its own chunk) — called on the first key
 * missing from the database's text, or straight away when a fetch fails.
 * Tests await it in their setup file.
 */
export function loadBundledEnglish(): Promise<void> {
  bundledLoad ??= import('./en.json').then(
    (module) => {
      bundled.value = module.default
    },
    () => {
      bundledLoad = null
    },
  )
  return bundledLoad
}

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
  return messages.value[key] ?? english.value[key] ?? bundled.value[key]
}

/** The text for `key` in the current language, with `{placeholders}` filled from `params`. */
export function t(key: string, params?: Params): string {
  const text = lookup(key)
  if (text === undefined) {
    // Reactive: whoever rendered this re-renders once the bundled copy lands.
    if (!bundledLoad) void loadBundledEnglish()
    else if (import.meta.env.DEV && Object.keys(bundled.value).length)
      console.warn(`[i18n] missing key: ${key}`)
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
 * Switches to `code`: fetches its strings with `fetcher`, then swaps them in,
 * so the UI changes in one go rather than key by key. Remembered in
 * localStorage for the next visit. A failed fetch keeps the language choice
 * and falls back to English text (the database's, else the bundled copy).
 *
 * With `scopes` (main.ts passes the first route's), only those prefixes are
 * awaited; the rest of the language — and, for any language but English,
 * the database's English — is fetched in the background. Without them,
 * everything is fetched up front (tests).
 */
export async function setLanguage(code: Language, fetcher: Fetcher, scopes?: readonly string[]) {
  rememberLanguage(code)
  language.value = code
  applyDocumentLanguage(code)

  const failed = (): Messages => {
    void loadBundledEnglish()
    return {}
  }

  if (!scopes) {
    const needEnglish = code !== 'en' && Object.keys(english.value).length === 0
    const [fetched, fetchedEnglish] = await Promise.all([
      fetcher(code).catch(failed),
      needEnglish ? fetcher('en').catch(failed) : Promise.resolve(null),
    ])
    if (fetchedEnglish) english.value = fetchedEnglish
    if (code === 'en') english.value = fetched
    // A later switch may have finished first; don't overwrite it.
    if (language.value === code) {
      messages.value = fetched
      loaded = 'all'
    }
    return
  }

  const first = await fetcher(code, { scopes }).catch(failed)
  if (language.value !== code) return
  messages.value = first
  loaded = new Set(scopes)
  rest = loadRest(code, fetcher, scopes)
}

/** The background half of `setLanguage()`: everything outside `scopes`, plus the English fallback layer. */
async function loadRest(code: Language, fetcher: Fetcher, scopes: readonly string[]) {
  const needEnglish = code !== 'en' && Object.keys(english.value).length === 0
  const [others, fetchedEnglish] = await Promise.all([
    fetcher(code, { except: scopes }).catch(() => null),
    needEnglish ? fetcher('en').catch(() => null) : Promise.resolve(null),
  ])
  if (language.value !== code) return
  if (fetchedEnglish) english.value = fetchedEnglish
  if (others) {
    messages.value = { ...others, ...messages.value }
    if (code === 'en') english.value = messages.value
  } else {
    void loadBundledEnglish()
  }
  // Even after a failure: what's missing now comes from the bundled copy.
  loaded = 'all'
}

/**
 * Resolves once `scopes` are loaded for the current language — at once if
 * they already are, else when the background fetch lands or after
 * `timeoutMs`, whichever is first (the page then renders and swaps text in
 * as it arrives). The router calls it before each navigation.
 */
export function ensureScopes(
  scopes: readonly string[],
  timeoutMs = NAVIGATION_WAIT_MS,
): Promise<void> {
  const ready = loaded
  if (ready === 'all' || scopes.every((scope) => ready.has(scope))) return Promise.resolve()
  return Promise.race([rest, new Promise<void>((resolve) => setTimeout(resolve, timeoutMs))])
}
