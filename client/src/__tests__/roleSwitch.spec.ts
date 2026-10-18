import { afterEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import { useBadgesStore } from '@/stores/badges'
import { notificationLink, withRole } from '@/utils/notificationLink'

/**
 * Phase 5 of one account holding both roles: counters per role, and links
 * that open the app in the role they're about.
 */
function dualRoleAccount() {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.setUser({
    id: 1,
    email: 'a@example.com',
    role: 'guest',
    roles: ['guest', 'guide'],
    sessionHash: 'h',
  })
  return auth
}

afterEach(() => {
  localStorage.clear()
})

describe('badges per role', () => {
  it('shows the acting role’s counts, and flags news in the other role', () => {
    const auth = dualRoleAccount()
    const badges = useBadgesStore()
    badges.set({
      matches: 3,
      trips: 1,
      messages: 2,
      byRole: {
        guest: { matches: 1, trips: 0, messages: 0 },
        guide: { matches: 2, trips: 1, messages: 2 },
      },
    })

    expect([badges.matches, badges.trips, badges.messages]).toEqual([1, 0, 0])
    expect(badges.otherRoleNews).toBe(true)

    auth.setActiveRole('guide')
    expect([badges.matches, badges.trips, badges.messages]).toEqual([2, 1, 2])
    // The traveller side has a match waiting.
    expect(badges.otherRoleNews).toBe(true)
  })

  it('falls back to the totals without a per-role breakdown, and never flags a one-role account', () => {
    setActivePinia(createPinia())
    useAuthStore().setUser({ id: 1, email: 'a@example.com', role: 'guide', sessionHash: 'h' })
    const badges = useBadgesStore()
    badges.set({ matches: 4, trips: 0, messages: 1 })

    expect(badges.matches).toBe(4)
    expect(badges.otherRoleNews).toBe(false)
  })
})

describe('links that switch role', () => {
  it('adds ?as= before any #hash', () => {
    expect(withRole('/trips', 'guide')).toBe('/trips?as=guide')
    expect(withRole('/profile#level', 'guest')).toBe('/profile?as=guest#level')
  })

  it('tags a notification’s link with its role, and picks the right profile from it', () => {
    expect(
      notificationLink(
        { id: 1, kind: 'trip_finished', message: '', created_at: '', role: 'guide' },
        'guest',
      ),
    ).toBe('/trips?as=guide')
    // The recipient was the guide in that pair, so the person who changed is a traveller.
    expect(
      notificationLink(
        {
          id: 2,
          kind: 'profile_updated',
          about_user_id: 9,
          message: '',
          created_at: '',
          role: 'guide',
        },
        'guest',
      ),
    ).toBe('/visitors/9?as=guide')
    // Older rows without a role link as before.
    expect(
      notificationLink({ id: 3, kind: 'booking_accepted', message: '', created_at: '' }, 'guest'),
    ).toBe('/trips')
  })

  it('switches to the role named by ?as= and drops it from the URL', async () => {
    const auth = dualRoleAccount()
    expect(auth.activeRole).toBe('guest')

    await router.push('/matches?as=guide')

    expect(auth.activeRole).toBe('guide')
    expect(router.currentRoute.value.path).toBe('/matches')
    expect(router.currentRoute.value.query).not.toHaveProperty('as')
  })
})
