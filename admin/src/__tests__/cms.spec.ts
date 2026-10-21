import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import App from '../App.vue'
import LoginPage from '../pages/LoginPage.vue'
import StaffPage from '../pages/StaffPage.vue'
import { session, type Me, type StaffMember } from '@/api'

// The server is out of the picture: every `api.*` call is a stub.
vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  type Api = typeof actual.api
  return {
    ...actual,
    api: {
      login: vi.fn<Api['login']>(),
      verifyCode: vi.fn<Api['verifyCode']>(),
      logout: vi.fn<Api['logout']>(),
      staffList: vi.fn<Api['staffList']>(),
      staffInvite: vi.fn<Api['staffInvite']>(),
      staffUpdate: vi.fn<Api['staffUpdate']>(),
      staffResendInvite: vi.fn<Api['staffResendInvite']>(),
    },
  }
})
const { api } = await import('@/api')

function member(overrides: Partial<StaffMember> = {}): StaffMember {
  return {
    id: 1,
    email: 'admin@example.test',
    name: 'Ada',
    role: 'admin',
    disabled: false,
    hasPassword: true,
    lastSignInAt: null,
    createdAt: '2026-10-21T09:00:00Z',
    ...overrides,
  }
}

function signIn(me: Partial<Me>) {
  session.ready = true
  session.me = { ...member(), capabilities: [], ...me }
}

function testRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: LoginPage },
      { path: '/staff', component: StaffPage },
      { path: '/:rest(.*)*', component: { template: '<div />' } },
    ],
  })
}

afterEach(() => {
  session.me = null
  vi.clearAllMocks()
})

describe('the CMS shell', () => {
  it('shows each role only the sections it may use', async () => {
    signIn({ role: 'editor', capabilities: ['content.edit'] })
    const router = testRouter()
    const editor = mount(App, { global: { plugins: [router] } })
    await router.isReady()
    const sections = () => editor.findAll('[data-test="sections"] a').map((a) => a.text())
    expect(sections()).toEqual(['Content', 'Audit log'])
    expect(editor.find('[data-test="whoami"]').text()).toContain('editor')

    signIn({
      role: 'admin',
      capabilities: ['staff.manage', 'users.view', 'disputes.resolve', 'content.edit'],
    })
    await flushPromises()
    expect(sections()).toEqual(['Content', 'Users', 'Moderation', 'Staff', 'Audit log'])
  })
})

describe('LoginPage', () => {
  it('asks for the emailed code after the password, then signs in', async () => {
    vi.mocked(api.login).mockResolvedValue({
      codeRequired: true,
      challengeToken: 't'.repeat(64),
      expiresInSeconds: 600,
    })
    vi.mocked(api.verifyCode).mockResolvedValue()
    const router = testRouter()
    await router.push('/login?next=/staff')
    const wrapper = mount(LoginPage, { global: { plugins: [router] } })

    await wrapper.find('input[type="email"]').setValue('admin@example.test')
    await wrapper.find('input[type="password"]').setValue('correct horse battery')
    await wrapper.find('[data-test="password-step"]').trigger('submit')
    await flushPromises()

    expect(api.login).toHaveBeenCalledWith('admin@example.test', 'correct horse battery')
    expect(wrapper.find('[data-test="code-step"]').exists()).toBe(true)

    await wrapper.find('input[autocomplete="one-time-code"]').setValue('123456')
    await wrapper.find('[data-test="code-step"]').trigger('submit')
    await flushPromises()

    expect(api.verifyCode).toHaveBeenCalledWith('t'.repeat(64), '123456')
    expect(router.currentRoute.value.path).toBe('/staff')
  })
})

describe('StaffPage', () => {
  it('invites someone, and disables only after a confirm', async () => {
    signIn({ id: 1, capabilities: ['staff.manage'] })
    const editor = member({ id: 2, email: 'ed@example.test', name: 'Ed', role: 'editor' })
    vi.mocked(api.staffList).mockResolvedValue({ staff: [member(), editor] })
    vi.mocked(api.staffInvite).mockResolvedValue({
      staff: member({ id: 3, email: 'new@example.test', role: 'manager', hasPassword: false }),
    })
    vi.mocked(api.staffUpdate).mockResolvedValue({ staff: { ...editor, disabled: true } })
    const router = testRouter()
    await router.push('/staff')
    const wrapper = mount(StaffPage, { global: { plugins: [router] } })
    await flushPromises()

    // Nobody changes their own role or disables themselves.
    expect(wrapper.find('[data-staff="1"]').text()).toContain('You')

    const form = wrapper.find('[data-test="invite"]')
    await form.find('input[type="email"]').setValue('new@example.test')
    await form.find('select').setValue('manager')
    await form.trigger('submit')
    await flushPromises()
    expect(api.staffInvite).toHaveBeenCalledWith({
      email: 'new@example.test',
      name: '',
      role: 'manager',
    })
    expect(wrapper.find('[data-staff="3"]').text()).toContain('Invited')

    await wrapper.find('[data-staff="2"] [data-test="disable"]').trigger('click')
    expect(api.staffUpdate).not.toHaveBeenCalled()
    await wrapper.find('[data-staff="2"] [data-test="confirm-disable"]').trigger('click')
    await flushPromises()
    expect(api.staffUpdate).toHaveBeenCalledWith(2, { disabled: true })
    expect(wrapper.find('[data-staff="2"]').text()).toContain('Disabled')
  })
})
