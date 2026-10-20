/**
 * What this browser says about itself, sent with every sign-in and refresh
 * (`device`) so the account's devices can be told apart and a sign-in from
 * an unfamiliar one noticed (docs/auth-cms-plan.md, phase 3). The server
 * keeps only keyed hashes of it, plus a "Chrome on macOS" label for the
 * user's own devices list (`auth/classes/Devices.php`).
 *
 * Deliberately the basic set: browser and system (Client Hints where the
 * browser has them, else the User-Agent), screen, time zone, languages, CPU
 * and memory class. No canvas or WebGL hashing — decided against (legal
 * review, and browsers randomise them).
 */
export interface DeviceInfo {
  browser?: string
  browserVersion?: number
  os?: string
  osVersion?: string
  model?: string
  mobile?: boolean
  screen?: { width: number; height: number }
  dpr?: number
  colorDepth?: number
  touchPoints?: number
  timeZone?: string
  languages?: string[]
  cores?: number
  memory?: number
}

interface UADataBrand {
  brand: string
  version: string
}

interface UAData {
  brands?: UADataBrand[]
  mobile?: boolean
  platform?: string
  getHighEntropyValues?: (hints: string[]) => Promise<{
    platformVersion?: string
    model?: string
    fullVersionList?: UADataBrand[]
  }>
}

/** The real browser among Client Hints' brands (not "Not A;Brand" or "Chromium" when there's better). */
function pickBrand(brands: UADataBrand[]): UADataBrand | undefined {
  const real = brands.filter((b) => !/not.?a.?brand/i.test(b.brand))
  return (
    real.find((b) => /Edge|Opera|Brave|Samsung/i.test(b.brand)) ??
    real.find((b) => /Chrome/i.test(b.brand) && !/Chromium/i.test(b.brand)) ??
    real[0]
  )
}

const BRAND_NAMES: Record<string, string> = {
  'Google Chrome': 'Chrome',
  'Microsoft Edge': 'Edge',
}

let cached: Promise<DeviceInfo> | null = null

/** Collected once per page load (it doesn't change), best-effort — never throws. */
export function deviceInfo(): Promise<DeviceInfo> {
  cached ??= collect().catch(() => ({}))
  return cached
}

async function collect(): Promise<DeviceInfo> {
  if (typeof navigator === 'undefined') return {}
  const info: DeviceInfo = {}

  const uaData = (navigator as Navigator & { userAgentData?: UAData }).userAgentData
  if (uaData?.brands?.length) {
    const brand = pickBrand(uaData.brands)
    if (brand) {
      info.browser = BRAND_NAMES[brand.brand] ?? brand.brand
      info.browserVersion = Number.parseInt(brand.version, 10) || undefined
    }
    info.mobile = uaData.mobile
    info.os = uaData.platform || undefined
    try {
      const high = await uaData.getHighEntropyValues?.(['platformVersion', 'model'])
      info.osVersion = high?.platformVersion?.split('.')[0] || undefined
      info.model = high?.model || undefined
    } catch {
      // Not offered — the low-entropy values will do.
    }
  }
  // Without Client Hints (Safari, Firefox) the server reads the User-Agent.

  if (typeof screen !== 'undefined') {
    info.screen = { width: screen.width, height: screen.height }
    info.colorDepth = screen.colorDepth
  }
  info.dpr = typeof devicePixelRatio === 'number' ? devicePixelRatio : undefined
  info.touchPoints = navigator.maxTouchPoints
  try {
    info.timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone
  } catch {
    // Left out.
  }
  info.languages = navigator.languages ? [...navigator.languages].slice(0, 5) : undefined
  info.cores = navigator.hardwareConcurrency || undefined
  info.memory = (navigator as Navigator & { deviceMemory?: number }).deviceMemory

  return Object.fromEntries(
    Object.entries(info).filter(([, value]) => value !== undefined),
  ) as DeviceInfo
}
