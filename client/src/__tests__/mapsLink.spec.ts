import { describe, expect, it } from 'vitest'
import { detectMapsPlatform, mapsLinksFor } from '@/utils/mapsLink'

const UA = {
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
  ipadDesktop: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
  android: 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36',
  windows: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
}

describe('detectMapsPlatform', () => {
  it('reads the User-Agent string', () => {
    expect(detectMapsPlatform({ userAgent: UA.iphone })).toBe('apple')
    expect(detectMapsPlatform({ userAgent: UA.android })).toBe('android')
    expect(detectMapsPlatform({ userAgent: UA.windows, platform: 'Win32' })).toBe('other')
  })

  it('treats iPadOS asking for the desktop site (it says "Macintosh") as Apple, like a Mac', () => {
    expect(detectMapsPlatform({ userAgent: UA.ipadDesktop, platform: 'MacIntel' })).toBe('apple')
  })

  it('prefers Client Hints when the browser offers them', () => {
    expect(detectMapsPlatform({ userAgent: UA.windows, userAgentData: { platform: 'Android', mobile: true } })).toBe('android')
    expect(detectMapsPlatform({ userAgent: UA.windows, userAgentData: { platform: 'macOS' } })).toBe('apple')
  })

  it('falls back to "other" with nothing to go on', () => {
    expect(detectMapsPlatform({})).toBe('other')
  })
})

describe('mapsLinksFor', () => {
  it('puts Apple Maps first on Apple devices, with Google Maps as the alternative', () => {
    const [first, second] = mapsLinksFor(36.1408, -5.3536, 'apple', 'Gina')
    expect(first).toEqual({ provider: 'apple', href: 'https://maps.apple.com/?ll=36.140800%2C-5.353600&q=Gina' })
    expect(second!.provider).toBe('google')
  })

  it('puts the Google Maps app link first on Android, then a geo: URI for any maps app', () => {
    const [first, second] = mapsLinksFor(36.1408, -5.3536, 'android', 'Gina Guide')
    expect(first).toEqual({
      provider: 'google',
      href: 'https://www.google.com/maps/search/?api=1&query=36.140800%2C-5.353600',
    })
    expect(second).toEqual({ provider: 'geo', href: 'geo:0,0?q=36.140800,-5.353600(Gina%20Guide)' })
  })

  it('encodes parentheses in a geo: label so they can’t end it early', () => {
    const [, geo] = mapsLinksFor(1, 2, 'android', 'Gina (Rock tours)')
    expect(geo!.href).toBe('geo:0,0?q=1.000000,2.000000(Gina%20%28Rock%20tours%29)')
  })

  it('uses Google Maps in the browser elsewhere', () => {
    expect(mapsLinksFor(1, 2, 'other').map((l) => l.provider)).toEqual(['google', 'apple'])
  })
})
