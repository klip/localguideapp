/**
 * This browser's "remembered device" tokens for two-factor login, one per
 * email (several accounts can share a browser). The server decides whether a
 * token still counts (see `AccountSecurity::isTrustedDevice()`); the local
 * expiry only saves sending one that's certainly dead. Storage can be
 * unavailable (private mode, blocked site data) — then nothing is
 * remembered and the user just gets a code each time.
 */
const STORAGE_KEY = 'rockguide.trustedDevices'

interface Entry {
  token: string
  /** ISO 8601, from the server's `trustedUntil`. */
  until: string
}

function read(): Record<string, Entry> {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, Entry>) : {}
  } catch {
    return {}
  }
}

function write(entries: Record<string, Entry>) {
  try {
    if (Object.keys(entries).length) localStorage.setItem(STORAGE_KEY, JSON.stringify(entries))
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Not remembered — the next login asks for a code, which is safe.
  }
}

function key(email: string) {
  return email.trim().toLowerCase()
}

/** The token to send with a login for `email`, if this browser has an unexpired one. */
export function trustedDeviceToken(email: string): string | undefined {
  const entry = read()[key(email)]
  if (!entry) return undefined
  if (!(new Date(entry.until).getTime() > Date.now())) {
    forgetTrustedDevice(email)
    return undefined
  }
  return entry.token
}

export function rememberTrustedDevice(email: string, token: string, until: string) {
  write({ ...read(), [key(email)]: { token, until } })
}

export function forgetTrustedDevice(email: string) {
  const entries = read()
  delete entries[key(email)]
  write(entries)
}
