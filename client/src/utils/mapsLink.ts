/**
 * Links for a shared location (a chat `location` message) that open the
 * maps app native to the device the *viewer* is holding — so the sender's
 * phone doesn't decide: an iPhone user gets Apple Maps from an Android
 * user's pin, and vice versa.
 *
 *   apple   — iPhone, iPad (including iPadOS, which reports itself as a Mac
 *             with a touchscreen) and macOS: https://maps.apple.com opens
 *             the Maps app.
 *   android — Google Maps' universal link opens the Google Maps app when it's
 *             installed (the browser otherwise); a `geo:` URI is offered too,
 *             which lets Android pick any installed maps app.
 *   other   — Google Maps in the browser (desktop Windows/Linux, unknown).
 *
 * Detection prefers User-Agent Client Hints (`navigator.userAgentData`,
 * Chromium) and falls back to the User-Agent string. It's a best guess for
 * choosing the *first* link: every platform also gets the other provider.
 */
export type MapsPlatform = 'apple' | 'android' | 'other'

/** The parts of `navigator` this reads — a plain object in tests. */
export interface NavigatorLike {
  userAgent?: string
  platform?: string
  userAgentData?: { platform?: string; mobile?: boolean } | null
}

export interface MapsLink {
  href: string
  provider: 'apple' | 'google' | 'geo'
}

export function detectMapsPlatform(nav: NavigatorLike | undefined = globalThis.navigator): MapsPlatform {
  if (!nav) return 'other'
  const hinted = nav.userAgentData?.platform?.toLowerCase() ?? ''
  if (hinted === 'android') return 'android'
  if (hinted === 'ios' || hinted === 'macos') return 'apple'

  const ua = nav.userAgent ?? ''
  if (/android/i.test(ua)) return 'android'
  if (/iphone|ipad|ipod/i.test(ua)) return 'apple'
  // iPadOS 13+ asks for desktop sites and says "Macintosh"; a Mac has no touchscreen.
  if (/macintosh|mac os x/i.test(ua) || /^mac/i.test(nav.platform ?? '')) return 'apple'
  return 'other'
}

function coords(latitude: number, longitude: number) {
  return `${latitude.toFixed(6)},${longitude.toFixed(6)}`
}

export function appleMapsLink(latitude: number, longitude: number, label?: string): MapsLink {
  const params = new URLSearchParams({ ll: coords(latitude, longitude), q: label || coords(latitude, longitude) })
  return { href: `https://maps.apple.com/?${params}`, provider: 'apple' }
}

export function googleMapsLink(latitude: number, longitude: number): MapsLink {
  const params = new URLSearchParams({ api: '1', query: coords(latitude, longitude) })
  return { href: `https://www.google.com/maps/search/?${params}`, provider: 'google' }
}

/**
 * `geo:0,0?q=lat,lng(label)` — the form Android's maps apps read most
 * widely. Parentheses in the label are encoded too: a raw `)` would end it.
 */
export function geoLink(latitude: number, longitude: number, label?: string): MapsLink {
  const at = coords(latitude, longitude)
  const name = label ? `(${encodeURIComponent(label).replace(/\(/g, '%28').replace(/\)/g, '%29')})` : ''
  return { href: `geo:0,0?q=${at}${name}`, provider: 'geo' }
}

/**
 * The links to show for a pin, the device's native app first. `label` names
 * the pin in apps that show one (e.g. the sender's name).
 */
export function mapsLinksFor(
  latitude: number,
  longitude: number,
  platform: MapsPlatform = detectMapsPlatform(),
  label?: string,
): MapsLink[] {
  const apple = appleMapsLink(latitude, longitude, label)
  const google = googleMapsLink(latitude, longitude)
  if (platform === 'apple') return [apple, google]
  if (platform === 'android') return [google, geoLink(latitude, longitude, label)]
  return [google, apple]
}
