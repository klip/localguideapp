import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import ForgotPasswordPage from '../pages/ForgotPasswordPage.vue'
import LoginPage from '../pages/LoginPage.vue'
import ResetPasswordPage from '../pages/ResetPasswordPage.vue'
import {
  API_KEY,
  ApiError,
  type Api,
  type AuthResult,
  type TwoFactorChallenge,
} from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
    verifyTwoFactor: vi.fn<Api['verifyTwoFactor']>(),
    resendTwoFactor: vi.fn<Api['resendTwoFactor']>(),
    requestPasswordReset: vi.fn<Api['requestPasswordReset']>().mockResolvedValue({ ok: true }),
    resetPassword: vi.fn<Api['resetPassword']>().mockResolvedValue({ ok: true }),
    forgetTrustedDevices: vi.fn<Api['forgetTrustedDevices']>(),
    guides: vi.fn<Api['guides']>(),
    visitors: vi.fn<Api['visitors']>(),
    updateProfile: vi.fn<Api['updateProfile']>(),
    attributeCategories: vi.fn<Api['attributeCategories']>(),
    myProfile: vi.fn<Api['myProfile']>(),
    updateAccount: vi.fn<Api['updateAccount']>(),
    changePassword: vi.fn<Api['changePassword']>(),
    logout: vi.fn<Api['logout']>(),
    session: vi.fn<Api['session']>(),
    unlockPack: vi.fn<Api['unlockPack']>(),
    paymentState: vi.fn<Api['paymentState']>().mockRejectedValue(new Error('not needed')),
    payGuideFee: vi.fn<Api['payGuideFee']>(),
    bookingAction: vi.fn<Api['bookingAction']>(),
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    trips: vi.fn<Api['trips']>(),
    notifications: vi.fn<Api['notifications']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

const TOKEN = 'a'.repeat(64)

const challenge: TwoFactorChallenge = {
  twoFactorRequired: true,
  challengeToken: TOKEN,
  method: 'email',
  destination: 'so•••@example.com',
  expiresInSeconds: 600,
  resendAfterSeconds: 30,
}

const guideUser: AuthResult = {
  id: 7,
  email: 'sofia@example.com',
  role: 'guide',
  sessionHash: 'hash',
}

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/login', component: LoginPage },
      { path: '/forgot-password', component: ForgotPasswordPage },
      { path: '/reset-password', component: ResetPasswordPage },
      { path: '/:pathMatch(.*)*', component: { template: '<div />' } },
    ],
  })
}

async function mountAt(path: string, component: unknown, api: Api) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = testRouter()
  router.push(path)
  await router.isReady()

  const wrapper = mount(component as never, {
    global: { plugins: [pinia, router], provide: { [API_KEY]: api } },
  })
  await flushPromises()
  return { wrapper, router }
}

function toasts() {
  return useMessagesStore()
    .items.map((item) => item.text)
    .join(' | ')
}

async function logInWithPassword(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper']) {
  await wrapper.find('input[type="email"]').setValue('sofia@example.com')
  await wrapper.find('input[type="password"]').setValue('password123')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

afterEach(() => {
  vi.useRealTimers()
  localStorage.clear()
})

describe('LoginPage — two-factor', () => {
  it('logs straight in when the account has no two-factor', async () => {
    const api = fakeApi({ login: vi.fn<Api['login']>().mockResolvedValue(guideUser) })
    const { wrapper, router } = await mountAt('/login', LoginPage, api)

    await logInWithPassword(wrapper)

    expect(useAuthStore().user?.id).toBe(7)
    expect(router.currentRoute.value.path).toBe('/guide/discover')
    expect(api.verifyTwoFactor).not.toHaveBeenCalled()
  })

  it('asks for the emailed code, then signs in with it', async () => {
    const verifyTwoFactor = vi.fn<Api['verifyTwoFactor']>().mockResolvedValue(guideUser)
    const api = fakeApi({
      login: vi.fn<Api['login']>().mockResolvedValue(challenge),
      verifyTwoFactor,
    })
    const { wrapper, router } = await mountAt('/login', LoginPage, api)

    await logInWithPassword(wrapper)

    // No session yet — only the challenge.
    expect(useAuthStore().user).toBeNull()
    expect(wrapper.text()).toContain('We sent a 6-digit code to so•••@example.com.')
    const submit = wrapper.find('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await wrapper.find('input[autocomplete="one-time-code"]').setValue('123456')
    expect(submit.attributes('disabled')).toBeUndefined()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(verifyTwoFactor).toHaveBeenCalledWith({ challengeToken: TOKEN, code: '123456' })
    expect(useAuthStore().user?.id).toBe(7)
    expect(router.currentRoute.value.path).toBe('/guide/discover')
  })

  it('keeps the code step open after a wrong code, and goes back to the password once the challenge is spent', async () => {
    const verifyTwoFactor = vi
      .fn<Api['verifyTwoFactor']>()
      .mockRejectedValueOnce(new ApiError('That code isn’t right. 4 tries left.', 400))
      .mockRejectedValueOnce(
        new ApiError('Too many wrong codes. Log in again to get a new one.', 400),
      )
    const api = fakeApi({
      login: vi.fn<Api['login']>().mockResolvedValue(challenge),
      verifyTwoFactor,
    })
    const { wrapper } = await mountAt('/login', LoginPage, api)
    await logInWithPassword(wrapper)

    const codeInput = () => wrapper.find('input[autocomplete="one-time-code"]')
    await codeInput().setValue('000000')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(toasts()).toContain('4 tries left')
    expect(codeInput().exists()).toBe(true)

    await codeInput().setValue('111111')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(codeInput().exists()).toBe(false)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })

  it('only lets a new code be requested after the countdown', async () => {
    vi.useFakeTimers()
    const resendTwoFactor = vi.fn<Api['resendTwoFactor']>().mockResolvedValue(challenge)
    const api = fakeApi({
      login: vi.fn<Api['login']>().mockResolvedValue(challenge),
      resendTwoFactor,
    })
    const { wrapper } = await mountAt('/login', LoginPage, api)
    await logInWithPassword(wrapper)

    const resend = () => wrapper.findAll('button').find((b) => b.text().includes('new code'))!
    expect(resend().text()).toBe('Send a new code in 30s')
    expect(resend().attributes('disabled')).toBeDefined()

    vi.advanceTimersByTime(30_000)
    await flushPromises()
    expect(resend().text()).toBe('Send a new code')
    await resend().trigger('click')
    await flushPromises()

    expect(resendTwoFactor).toHaveBeenCalledWith({ challengeToken: TOKEN })
    expect(toasts()).toContain('New code sent to so•••@example.com.')
  })

  it('links to the forgot-password page', async () => {
    const { wrapper } = await mountAt('/login', LoginPage, fakeApi())

    expect(wrapper.find('a[href="/forgot-password"]').text()).toBe('Forgot password?')
  })
})

describe('LoginPage — remembered device', () => {
  const DEVICE = 'd'.repeat(64)
  const until = () => new Date(Date.now() + 30 * 86_400_000).toISOString()

  async function enterCode(wrapper: Awaited<ReturnType<typeof mountAt>>['wrapper'], remember: boolean) {
    if (remember) await wrapper.find('input[type="checkbox"]').setValue(true)
    await wrapper.find('input[autocomplete="one-time-code"]').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
  }

  it('remembers the device when ticked, and sends its token with the next login', async () => {
    const verifyTwoFactor = vi
      .fn<Api['verifyTwoFactor']>()
      .mockResolvedValue({ ...guideUser, deviceToken: DEVICE, trustedUntil: until() })
    const login = vi.fn<Api['login']>().mockResolvedValue(challenge)
    const first = await mountAt('/login', LoginPage, fakeApi({ login, verifyTwoFactor }))
    await logInWithPassword(first.wrapper)
    await enterCode(first.wrapper, true)

    expect(verifyTwoFactor).toHaveBeenCalledWith({ challengeToken: TOKEN, code: '123456', rememberDevice: true })
    // The device token isn't part of the persisted session.
    expect(localStorage.getItem('rockguide.session')).not.toContain(DEVICE)

    login.mockResolvedValue(guideUser)
    const second = await mountAt('/login', LoginPage, fakeApi({ login }))
    await logInWithPassword(second.wrapper)

    expect(login).toHaveBeenLastCalledWith({
      email: 'sofia@example.com',
      password: 'password123',
      deviceToken: DEVICE,
    })
    expect(second.router.currentRoute.value.path).toBe('/guide/discover')
  })

  it('does not ask to remember the device unless ticked', async () => {
    const verifyTwoFactor = vi.fn<Api['verifyTwoFactor']>().mockResolvedValue(guideUser)
    const login = vi.fn<Api['login']>().mockResolvedValue(challenge)
    const { wrapper } = await mountAt('/login', LoginPage, fakeApi({ login, verifyTwoFactor }))
    await logInWithPassword(wrapper)
    await enterCode(wrapper, false)

    expect(verifyTwoFactor).toHaveBeenCalledWith({ challengeToken: TOKEN, code: '123456' })
    expect(localStorage.getItem('rockguide.trustedDevices')).toBeNull()
  })

  it('drops a remembered token the server no longer accepts', async () => {
    localStorage.setItem(
      'rockguide.trustedDevices',
      JSON.stringify({ 'sofia@example.com': { token: DEVICE, until: until() } }),
    )
    const login = vi.fn<Api['login']>().mockResolvedValue(challenge)
    const { wrapper } = await mountAt('/login', LoginPage, fakeApi({ login }))
    await logInWithPassword(wrapper)

    expect(login).toHaveBeenCalledWith(expect.objectContaining({ deviceToken: DEVICE }))
    expect(wrapper.text()).toContain('Check your email')
    expect(localStorage.getItem('rockguide.trustedDevices')).toBeNull()
  })

  it('does not send a token that has expired locally', async () => {
    localStorage.setItem(
      'rockguide.trustedDevices',
      JSON.stringify({ 'sofia@example.com': { token: DEVICE, until: '2020-01-01T00:00:00Z' } }),
    )
    const login = vi.fn<Api['login']>().mockResolvedValue(guideUser)
    const { wrapper } = await mountAt('/login', LoginPage, fakeApi({ login }))
    await logInWithPassword(wrapper)

    expect(login).toHaveBeenCalledWith({ email: 'sofia@example.com', password: 'password123' })
  })
})

describe('ForgotPasswordPage', () => {
  it('sends the reset request and says the same thing whether or not the account exists', async () => {
    const api = fakeApi()
    const { wrapper } = await mountAt('/forgot-password', ForgotPasswordPage, api)

    await wrapper.find('input[type="email"]').setValue(' nobody@example.com ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.requestPasswordReset).toHaveBeenCalledWith({ email: 'nobody@example.com' })
    expect(wrapper.text()).toContain('If nobody@example.com belongs to a RockGuide account')
  })
})

describe('ResetPasswordPage', () => {
  it('sets the new password, clears any cached session and sends the user to log in', async () => {
    const api = fakeApi()
    const { wrapper, router } = await mountAt(
      `/reset-password?token=${TOKEN}`,
      ResetPasswordPage,
      api,
    )
    useAuthStore().setUser(guideUser)

    const [first, second] = wrapper.findAll('input[type="password"]')
    await first!.setValue('new-password-1')
    await second!.setValue('new-password-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.resetPassword).toHaveBeenCalledWith({ token: TOKEN, newPassword: 'new-password-1' })
    expect(useAuthStore().user).toBeNull()
    expect(router.currentRoute.value.path).toBe('/login')
    expect(toasts()).toContain('Password changed')
  })

  it('does not send mismatched passwords', async () => {
    const api = fakeApi()
    const { wrapper } = await mountAt(`/reset-password?token=${TOKEN}`, ResetPasswordPage, api)

    const [first, second] = wrapper.findAll('input[type="password"]')
    await first!.setValue('new-password-1')
    await second!.setValue('new-password-2')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.resetPassword).not.toHaveBeenCalled()
    expect(toasts()).toContain('don’t match')
  })

  it('offers a new link when the token is missing or the server says it has expired', async () => {
    const { wrapper: missing } = await mountAt('/reset-password', ResetPasswordPage, fakeApi())
    expect(missing.text()).toContain('This link doesn’t work')
    expect(missing.find('a[href="/forgot-password"]').exists()).toBe(true)

    const api = fakeApi({
      resetPassword: vi
        .fn<Api['resetPassword']>()
        .mockRejectedValue(
          new ApiError('This reset link is invalid or has expired. Ask for a new one.', 400),
        ),
    })
    const { wrapper } = await mountAt(`/reset-password?token=${TOKEN}`, ResetPasswordPage, api)
    const [first, second] = wrapper.findAll('input[type="password"]')
    await first!.setValue('new-password-1')
    await second!.setValue('new-password-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('This link doesn’t work')
  })
})
