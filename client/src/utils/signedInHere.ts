/**
 * Whether anyone has signed in or registered on this browser — kept in
 * localStorage (`belikelocal.signedInHere`), set by App.vue on every
 * sign-in. A signed-out visitor on such a browser most likely has an account
 * already, so the header offers only "Log in" there, and "Get started"
 * (sign up) only on a browser that has never seen one.
 */
const STORAGE_KEY = 'belikelocal.signedInHere'

// The one key: the old "Browse freely" intro's (`belikelocal.browseIntro`)
// has no use since that panel went, so it's dropped from browsers that
// still hold it.
try {
  localStorage.removeItem('belikelocal.browseIntro')
} catch {
  // Storage unavailable: nothing to tidy.
}

export function signedInHere(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function markSignedInHere(): void {
  try {
    localStorage.setItem(STORAGE_KEY, '1')
  } catch {
    // Best-effort: without storage "Get started" keeps showing.
  }
}
