import { afterEach, describe, it, expect, vi } from 'vitest'
import { currentLanguage, initialLanguage, setLanguage, t, tn, type Messages } from '@/i18n'

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
})
