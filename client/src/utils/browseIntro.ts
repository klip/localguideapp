/**
 * The Discover page's "Browse freely — create an account to pick your
 * guides" panel is for a first visit only: shown once to a signed-out
 * visitor, and never on a browser where anyone has signed in or registered
 * (App.vue marks it then). Kept in localStorage (`belikelocal.browseIntro`)
 * — a UI preference, like the cookie notice's.
 */
const STORAGE_KEY = 'belikelocal.browseIntro'

export function browseIntroSeen(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function markBrowseIntroSeen(): void {
  try {
    localStorage.setItem(STORAGE_KEY, '1')
  } catch {
    // Best-effort: without storage it shows again next time.
  }
}
