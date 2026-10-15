import { afterEach, describe, it, expect, vi } from 'vitest'
import {
  currentLanguage,
  ensureScopes,
  initialLanguage,
  scopesForRoute,
  setLanguage,
  t,
  tn,
  type Messages,
  type Scope,
} from '@/i18n'

/** A fetcher serving fixed tables per language, the way `api.translations()` would. */
function fetcher(tables: Record<string, Messages>) {
  return vi.fn<(code: string) => Promise<Messages>>(async (code) => tables[code] ?? {})
}

afterEach(async () => {
  // Back to bundled English for the next test.
  await setLanguage('en', async () => ({}))
  localStorage.clear()
})

describe('i18n', () => {
  it('renders bundled English with placeholders filled, and the key itself when unknown', () => {
    expect(t('common.app_header.signed_in_as', { name: 'Ana' })).toBe('Signed in as Ana')
    expect(t('no.such.key')).toBe('no.such.key')
  })

  it('swaps in the fetched language and remembers it', async () => {
    await setLanguage('es', fetcher({ es: { 'discover.deck.title': 'Guías para ti' } }))

    expect(currentLanguage.value).toBe('es')
    expect(t('discover.deck.title')).toBe('Guías para ti')
    expect(localStorage.getItem('rockguide.language')).toBe('es')
    expect(initialLanguage()).toBe('es')
  })

  it('falls back to the database English, then the bundled copy, for untranslated keys', async () => {
    await setLanguage(
      'fr',
      fetcher({
        fr: { 'discover.deck.title': 'Guides pour vous' },
        en: { 'discover.filters.title': 'Filters (edited in the CMS)' },
      }),
    )

    expect(t('discover.deck.title')).toBe('Guides pour vous')
    expect(t('discover.filters.title')).toBe('Filters (edited in the CMS)')
    expect(t('discover.filters.hint')).toBe('Keep them light and optional.')
  })

  it('picks the CLDR plural form for the language, falling back to _other', async () => {
    await setLanguage(
      'ru',
      fetcher({
        ru: {
          'x.y.days_one': '{count} день',
          'x.y.days_few': '{count} дня',
          'x.y.days_many': '{count} дней',
          'x.y.days_other': '{count} дня',
        },
      }),
    )
    expect(tn('x.y.days', 1)).toBe('1 день')
    expect(tn('x.y.days', 3)).toBe('3 дня')
    expect(tn('x.y.days', 11)).toBe('11 дней')

    await setLanguage('ar', fetcher({ ar: { 'x.y.n_two': 'اثنان', 'x.y.n_other': '{count}' } }))
    expect(tn('x.y.n', 2)).toBe('اثنان')
    // No `_few` row for 3 → the `_other` form.
    expect(tn('x.y.n', 3)).toBe('3')
  })

  it('sets the document direction for right-to-left languages', async () => {
    await setLanguage('ar', fetcher({}))
    expect(document.documentElement.dir).toBe('rtl')
    expect(document.documentElement.lang).toBe('ar')

    await setLanguage('de', fetcher({}))
    expect(document.documentElement.dir).toBe('ltr')
  })

  it('with scopes, waits only for those and fetches the rest of the language in the background', async () => {
    const table: Messages = {
      'common.app_header.discover': 'Descubrir',
      'register.form.submit': 'Continuar',
      'matches.tabs.matched': 'Coincidencias',
    }
    let releaseRest: () => void = () => {}
    const restGate = new Promise<void>((resolve) => (releaseRest = resolve))
    const prefixed = (key: string, prefixes: readonly string[]) =>
      prefixes.some((p) => key.startsWith(`${p}.`))
    const fetch = vi.fn<(code: string, scope?: Scope) => Promise<Messages>>(async (code, scope) => {
      if (code !== 'es') return {}
      if (scope?.scopes)
        return Object.fromEntries(Object.entries(table).filter(([k]) => prefixed(k, scope.scopes!)))
      await restGate
      return Object.fromEntries(
        Object.entries(table).filter(([k]) => !prefixed(k, scope?.except ?? [])),
      )
    })

    await setLanguage('es', fetch, ['common.app_header', 'register.form'])

    expect(fetch).toHaveBeenCalledWith('es', { scopes: ['common.app_header', 'register.form'] })
    expect(fetch).toHaveBeenCalledWith('es', { except: ['common.app_header', 'register.form'] })
    expect(t('register.form.submit')).toBe('Continuar')
    // Not loaded yet: the bundled English stands in.
    expect(t('matches.tabs.matched')).toBe('Matched')

    // A route whose text is loaded doesn't wait; one whose isn't waits for the rest.
    await ensureScopes(['register.form'], 10)
    const waiting = ensureScopes(['matches.tabs'], 5000)
    releaseRest()
    await waiting
    expect(t('matches.tabs.matched')).toBe('Coincidencias')
    expect(t('register.form.submit')).toBe('Continuar')
  })

  it('names the shell plus the route as a page’s scopes', () => {
    const scopes = scopesForRoute('register')
    expect(scopes).toContain('common.app_header')
    expect(scopes).toContain('register.terms')
    expect(scopes).not.toContain('matches.tabs')
    expect(scopesForRoute(undefined)).toContain('common.app_header')
  })
})
