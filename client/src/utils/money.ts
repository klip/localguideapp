import { computed, ref } from 'vue'
import { currentLanguage } from '@/i18n'

/**
 * Money in the viewer's currency. Every amount the server sends is GBP — what
 * prices are stored and charged in — and is converted here for display only,
 * with today's rates (`exchangeRates`, ECB reference rates). The currency is
 * the viewer's choice (`CurrencySwitcher.vue`, kept in localStorage), or —
 * with no choice — guessed from where they are: the device's time zone, then
 * the browser's region ("Europe/Lisbon" → EUR, "America/Chicago" → USD).
 * Until the rates arrive, or for a currency they don't cover, it's pounds.
 *
 * Module-level state (like `t()`), so plain helpers and templates both follow
 * a change at once.
 */

/** What every server amount is in. */
export const BASE_CURRENCY = 'GBP'
/** The price of a selection pack, in pounds (`Payments::PACK_PRICE`). */
export const PACK_PRICE = 5

/** Offered in the switcher, most travelled first; the rest the rates cover follow A–Z. */
const PREFERRED = ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CNY', 'KRW', 'THB', 'TRY', 'AUD', 'CAD']

const STORAGE_KEY = 'belikelocal.currency'

function readSaved(): string {
  try {
    return localStorage.getItem(STORAGE_KEY) ?? ''
  } catch {
    return ''
  }
}

/** The viewer's own choice; '' means "automatic". */
export const chosenCurrency = ref(readSaved())
/** GBP → currency, from the server; GBP alone until they load. */
export const rates = ref<Record<string, number>>({ GBP: 1 })
export const ratesDate = ref<string | null>(null)

/** Time zones whose currency isn't what their region suggests. */
const ZONE_CURRENCY: Record<string, string> = {
  'Europe/London': 'GBP',
  'Europe/Belfast': 'GBP',
  'Europe/Guernsey': 'GBP',
  'Europe/Jersey': 'GBP',
  'Europe/Isle_of_Man': 'GBP',
  'Europe/Gibraltar': 'GBP',
  'Europe/Zurich': 'CHF',
  'Europe/Vaduz': 'CHF',
  'Europe/Oslo': 'NOK',
  'Europe/Stockholm': 'SEK',
  'Europe/Copenhagen': 'DKK',
  'Europe/Warsaw': 'PLN',
  'Europe/Prague': 'CZK',
  'Europe/Budapest': 'HUF',
  'Europe/Bucharest': 'RON',
  'Europe/Istanbul': 'TRY',
  'Asia/Istanbul': 'TRY',
  'Atlantic/Reykjavik': 'ISK',
  'Asia/Tokyo': 'JPY',
  'Asia/Seoul': 'KRW',
  'Asia/Shanghai': 'CNY',
  'Asia/Chongqing': 'CNY',
  'Asia/Urumqi': 'CNY',
  'Asia/Hong_Kong': 'HKD',
  'Asia/Singapore': 'SGD',
  'Asia/Bangkok': 'THB',
  'Asia/Kolkata': 'INR',
  'Asia/Calcutta': 'INR',
  'Asia/Jakarta': 'IDR',
  'Asia/Makassar': 'IDR',
  'Asia/Kuala_Lumpur': 'MYR',
  'Asia/Manila': 'PHP',
  'Asia/Jerusalem': 'ILS',
  'Asia/Tel_Aviv': 'ILS',
  'Pacific/Auckland': 'NZD',
  'Africa/Johannesburg': 'ZAR',
  'America/Sao_Paulo': 'BRL',
  'America/Bahia': 'BRL',
  'America/Fortaleza': 'BRL',
  'America/Recife': 'BRL',
  'America/Manaus': 'BRL',
  'America/Belem': 'BRL',
  'America/Mexico_City': 'MXN',
  'America/Cancun': 'MXN',
  'America/Monterrey': 'MXN',
  'America/Tijuana': 'MXN',
  'America/Merida': 'MXN',
  'America/Toronto': 'CAD',
  'America/Vancouver': 'CAD',
  'America/Edmonton': 'CAD',
  'America/Winnipeg': 'CAD',
  'America/Halifax': 'CAD',
  'America/St_Johns': 'CAD',
  'America/Regina': 'CAD',
}

/** A browser region ("en-US" → US) to its currency, when the time zone doesn't say. */
const REGION_CURRENCY: Record<string, string> = {
  US: 'USD',
  GB: 'GBP',
  IE: 'EUR',
  DE: 'EUR',
  FR: 'EUR',
  ES: 'EUR',
  IT: 'EUR',
  PT: 'EUR',
  NL: 'EUR',
  BE: 'EUR',
  AT: 'EUR',
  FI: 'EUR',
  GR: 'EUR',
  CY: 'EUR',
  MT: 'EUR',
  SK: 'EUR',
  SI: 'EUR',
  EE: 'EUR',
  LV: 'EUR',
  LT: 'EUR',
  LU: 'EUR',
  HR: 'EUR',
  BG: 'EUR',
  CH: 'CHF',
  JP: 'JPY',
  KR: 'KRW',
  CN: 'CNY',
  TH: 'THB',
  TR: 'TRY',
  AU: 'AUD',
  CA: 'CAD',
  NZ: 'NZD',
  IN: 'INR',
  BR: 'BRL',
  MX: 'MXN',
  SG: 'SGD',
  HK: 'HKD',
  SE: 'SEK',
  NO: 'NOK',
  DK: 'DKK',
  PL: 'PLN',
  CZ: 'CZK',
  HU: 'HUF',
  RO: 'RON',
  IL: 'ILS',
  ZA: 'ZAR',
}

/** Where the viewer seems to be, as a currency: Europe → EUR, the US → USD, and so on. */
export function detectCurrency(): string {
  let zone = ''
  try {
    zone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? ''
  } catch {
    zone = ''
  }
  const byZone = ZONE_CURRENCY[zone]
  if (byZone) return byZone
  if (zone.startsWith('Europe/')) return 'EUR'
  if (zone.startsWith('Australia/')) return 'AUD'
  if (zone.startsWith('America/') || zone.startsWith('US/')) return 'USD'

  const languages =
    typeof navigator !== 'undefined' ? (navigator.languages ?? [navigator.language]) : []
  for (const tag of languages) {
    const region = tag?.split('-')[1]?.toUpperCase()
    const byRegion = region ? REGION_CURRENCY[region] : undefined
    if (byRegion) return byRegion
  }
  return 'USD'
}

/** What the automatic choice comes to here. */
export const autoCurrency = computed(() => detectCurrency())

/** The currency amounts are shown in: the choice, else the automatic one — if the rates cover it. */
export const activeCurrency = computed(() => {
  const wanted = chosenCurrency.value || autoCurrency.value
  return rates.value[wanted] ? wanted : BASE_CURRENCY
})

/** Every currency the rates cover, the common ones first. */
export const availableCurrencies = computed(() => {
  const codes = Object.keys(rates.value)
  const preferred = PREFERRED.filter((c) => codes.includes(c))
  return [...preferred, ...codes.filter((c) => !preferred.includes(c)).sort()]
})

/** Saves the viewer's choice ('' goes back to automatic). */
export function setCurrency(code: string) {
  chosenCurrency.value = code
  try {
    if (code) localStorage.setItem(STORAGE_KEY, code)
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Private mode: the choice lasts for this page only.
  }
}

/** Takes the server's rates (`api.exchangeRates()`); anything unusable is ignored. */
export function applyRates(data: { rates?: Record<string, number>; date?: string | null } | null) {
  if (!data?.rates) return
  const clean: Record<string, number> = { GBP: 1 }
  for (const [code, rate] of Object.entries(data.rates)) {
    if (/^[A-Z]{3}$/.test(code) && typeof rate === 'number' && rate > 0) clean[code] = rate
  }
  rates.value = clean
  ratesDate.value = data.date ?? null
}

/** Whether amounts are shown in something other than what is charged. */
export const isConverted = computed(() => activeCurrency.value !== BASE_CURRENCY)

/**
 * A GBP amount in the viewer's currency and language: "£50.00", "59,69 €",
 * "$63.40". `trim` drops ".00" from whole amounts ("£5" on the price badge).
 */
export function formatMoney(gbp: number | null, options: { trim?: boolean } = {}): string {
  const code = activeCurrency.value
  const amount = (gbp ?? 0) * (rates.value[code] ?? 1)
  const whole = Math.abs(amount - Math.round(amount)) < 0.005
  try {
    return new Intl.NumberFormat(currentLanguage.value, {
      style: 'currency',
      currency: code,
      ...(options.trim && whole ? { minimumFractionDigits: 0, maximumFractionDigits: 0 } : {}),
    }).format(amount)
  } catch {
    return `${code} ${amount.toFixed(2)}`
  }
}

/** The same amount in pounds exactly as charged ("£5.00"), whatever the viewer's currency. */
export function formatCharge(gbp: number | null): string {
  try {
    return new Intl.NumberFormat(currentLanguage.value, {
      style: 'currency',
      currency: BASE_CURRENCY,
    }).format(gbp ?? 0)
  } catch {
    return `£${(gbp ?? 0).toFixed(2)}`
  }
}

/**
 * A guide's price for a card: the fee in the viewer's currency, then
 * whatever their label says it covers — the label's own leading amount is
 * dropped, since guides write it in pounds ("£50 / half day" →
 * "€59.69 / half day"). Free (no fee): the label as written, or `fallback`.
 */
export function guidePriceLabel(
  amount: number | null,
  label: string | null,
  fallback = '',
): string {
  const text = (label ?? '').trim()
  if (!amount || amount <= 0) return text || fallback
  const unit = text
    .replace(/^(?:[£€$]|GBP|EUR|USD)?\s*\d+(?:[.,]\d+)?\s*(?:[£€$]|GBP|EUR|USD)?\s*/i, '')
    .trim()
  const price = formatMoney(amount, { trim: true })
  if (!unit) return price
  return unit.startsWith('/') ? `${price} ${unit}` : `${price} · ${unit}`
}
