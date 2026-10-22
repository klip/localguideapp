import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  activeCurrency,
  applyRates,
  detectCurrency,
  formatCharge,
  formatMoney,
  guidePriceLabel,
  isConverted,
  setCurrency,
} from '@/utils/money'

function zone(timeZone: string, languages: string[] = ['en-US']) {
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    timeZone,
  } as Intl.ResolvedDateTimeFormatOptions)
  vi.spyOn(navigator, 'languages', 'get').mockReturnValue(languages)
}

afterEach(() => {
  vi.restoreAllMocks()
  setCurrency('')
  applyRates({ rates: { GBP: 1 } })
})

describe('the currency', () => {
  it('follows where the viewer is: Europe → EUR, the US → USD, London → GBP', () => {
    zone('Europe/Lisbon')
    expect(detectCurrency()).toBe('EUR')
    zone('America/Chicago')
    expect(detectCurrency()).toBe('USD')
    zone('Europe/London')
    expect(detectCurrency()).toBe('GBP')
    zone('Asia/Tokyo')
    expect(detectCurrency()).toBe('JPY')
    // No telling time zone: the browser's region.
    zone('UTC', ['de-CH'])
    expect(detectCurrency()).toBe('CHF')
  })

  it('stays in pounds until the rates arrive, then converts', () => {
    setCurrency('EUR')
    expect(activeCurrency.value).toBe('GBP')
    expect(formatMoney(50)).toBe('£50.00')

    applyRates({ rates: { GBP: 1, EUR: 1.2, USD: 1.3 }, date: '2026-10-22' })
    expect(activeCurrency.value).toBe('EUR')
    expect(isConverted.value).toBe(true)
    expect(formatMoney(50)).toBe('€60.00')
    expect(formatMoney(5, { trim: true })).toBe('€6')
    // What's charged is always shown in pounds.
    expect(formatCharge(5)).toBe('£5.00')
  })

  it('falls back to pounds for a currency the rates don’t cover', () => {
    applyRates({ rates: { GBP: 1, EUR: 1.2 } })
    setCurrency('XYZ')
    expect(activeCurrency.value).toBe('GBP')
  })
})

describe('a guide’s price', () => {
  it('puts the fee in the viewer’s currency and keeps what it covers', () => {
    applyRates({ rates: { GBP: 1, EUR: 1.2 } })
    setCurrency('EUR')
    expect(guidePriceLabel(50, '£50 / half day')).toBe('€60 / half day')
    expect(guidePriceLabel(50, 'per group')).toBe('€60 · per group')
    expect(guidePriceLabel(50, '')).toBe('€60')
    // A free guide keeps their own words.
    expect(guidePriceLabel(0, 'Free walks', 'Price on request')).toBe('Free walks')
    expect(guidePriceLabel(null, '', 'Price on request')).toBe('Price on request')
  })
})
