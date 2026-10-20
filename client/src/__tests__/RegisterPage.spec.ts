import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHistory, type Router } from 'vue-router'
import RegisterPage from '../pages/RegisterPage.vue'
import { API_KEY, ApiError, type Api, type LegalDocumentView } from '@/plugins/api'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/register', name: 'register', component: RegisterPage },
      { path: '/discover', name: 'discover', component: { template: '<div />' } },
      { path: '/guide/discover', name: 'guide-discover', component: { template: '<div />' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
    ],
  })
}

const TERMS: LegalDocumentView = {
  slug: 'terms',
  version: '2026-10-15',
  language: 'en',
  title: 'Terms and Conditions and Privacy Notice',
  body: 'Version 2026-10-15\n\n## 1. About\n\nOperated by [Operator: legal name].\n\n- a warning;\n- a ban.',
}

/** The starting role's agreement, shown after the terms and accepted with them. */
function agreement(slug: 'guide_agreement' | 'traveller_agreement'): LegalDocumentView {
  return {
    slug,
    version: '2026-10-18',
    language: 'en',
    title: `Agreement ${slug}`,
    body: '## 1. Scope\n\nApplies.',
  }
}

const bySlug = async (slug: string) =>
  slug === 'terms' ? TERMS : agreement(slug as 'guide_agreement')
const legalDocument = () => vi.fn<Api['legalDocument']>().mockImplementation(bySlug)

// jsdom's <dialog> support is partial; stand in for the two methods used,
// including the `close` event the browser fires.
const originalShowModal = HTMLDialogElement.prototype.showModal
const originalClose = HTMLDialogElement.prototype.close

beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn<() => void>(function (this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn<() => void>(function (this: HTMLDialogElement) {
    if (!this.hasAttribute('open')) return
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  })
})

afterEach(() => {
  HTMLDialogElement.prototype.showModal = originalShowModal
  HTMLDialogElement.prototype.close = originalClose
  document.body.innerHTML = ''
})

async function mountRegisterPage(
  path: string,
  api: Api,
): Promise<{ wrapper: ReturnType<typeof mount>; router: Router }> {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = testRouter()
  router.push(path)
  await router.isReady()

  const wrapper = mount(RegisterPage, {
    attachTo: document.body,
    global: {
      plugins: [pinia, router],
      provide: { [API_KEY]: api },
    },
  })
  await flushPromises()

  return { wrapper, router }
}

/** Fills the form and presses Continue — which only opens the terms. */
async function fillAndContinue(wrapper: ReturnType<typeof mount>, email: string, password: string) {
  await wrapper.find('input[type="email"]').setValue(email)
  await wrapper.find('input[type="password"]').setValue(password)
  await wrapper.find('form').trigger('submit.prevent')
  await flushPromises()
}

/** Continue, then Accept in the terms dialog — the whole registration. */
async function fillAndSubmit(wrapper: ReturnType<typeof mount>, email: string, password: string) {
  await fillAndContinue(wrapper, email, password)
  await wrapper.get('dialog button.accept').trigger('click')
  await flushPromises()
}

describe('RegisterPage', () => {
  it('defaults to the visitor flow and links to the guide flow', async () => {
    const { wrapper } = await mountRegisterPage('/register', fakeApi())

    expect(wrapper.text()).toContain('Create your visitor account')

    const guideLink = wrapper.get('a[href="/register?role=guide"]')
    expect(guideLink.text()).toBe('I am a guide')
  })

  it('switches to the guide flow when the route carries ?role=guide', async () => {
    const { wrapper } = await mountRegisterPage('/register?role=guide', fakeApi())

    expect(wrapper.text()).toContain('Create your guide account')
    expect(wrapper.text()).not.toContain('Create your visitor account')
  })

  it('registers as a guest by default and redirects to /discover', async () => {
    const register = vi.fn<Api['register']>().mockResolvedValue({
      id: 1,
      email: 'visitor@example.com',
      role: 'guest',
    })
    const { wrapper, router } = await mountRegisterPage(
      '/register',
      fakeApi({ register, legalDocument: legalDocument() }),
    )

    await fillAndSubmit(wrapper, 'visitor@example.com', 'correcthorse')

    expect(register).toHaveBeenCalledWith({
      email: 'visitor@example.com',
      password: 'correcthorse',
      role: 'guest',
      termsVersion: '2026-10-15',
      agreementSlug: 'traveller_agreement',
      agreementVersion: '2026-10-18',
      language: 'en',
    })
    expect(router.currentRoute.value.path).toBe('/discover')
  })

  it('registers as a guide via ?role=guide and sends them to /profile to fill in their card', async () => {
    const register = vi.fn<Api['register']>().mockResolvedValue({
      id: 2,
      email: 'guide@example.com',
      role: 'guide',
    })
    const { wrapper, router } = await mountRegisterPage(
      '/register?role=guide',
      fakeApi({ register, legalDocument: legalDocument() }),
    )

    await fillAndSubmit(wrapper, 'guide@example.com', 'correcthorse')

    expect(register).toHaveBeenCalledWith({
      email: 'guide@example.com',
      password: 'correcthorse',
      role: 'guide',
      termsVersion: '2026-10-15',
      agreementSlug: 'guide_agreement',
      agreementVersion: '2026-10-18',
      language: 'en',
    })
    expect(router.currentRoute.value.path).toBe('/profile')
  })

  it('shows an error toast and stays put when registration fails', async () => {
    const register = vi
      .fn<Api['register']>()
      .mockRejectedValue(new ApiError('Password must be at least 8 characters.', 400))
    const legal = legalDocument()
    const { wrapper, router } = await mountRegisterPage(
      '/register',
      fakeApi({ register, legalDocument: legal }),
    )

    await fillAndSubmit(wrapper, 'visitor@example.com', 'short')

    expect(router.currentRoute.value.path).toBe('/register')
    expect(wrapper.get('dialog').attributes('open')).toBeUndefined()

    const messages = useMessagesStore()
    expect(messages.items.map((item) => item.text)).toContain(
      'Password must be at least 8 characters.',
    )

    // Both documents are fetched again next time, in case a newer version was the problem.
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(legal).toHaveBeenCalledTimes(4)
  })

  it('Continue opens the terms, fetched on demand, and registers nothing until Accept', async () => {
    const register = vi.fn<Api['register']>()
    const legal = legalDocument()
    const { wrapper } = await mountRegisterPage(
      '/register',
      fakeApi({ register, legalDocument: legal }),
    )

    expect(legal).not.toHaveBeenCalled()

    await fillAndContinue(wrapper, 'visitor@example.com', 'correcthorse')

    // The terms, then the starting role's agreement — a visitor's is the traveller one.
    expect(legal.mock.calls).toEqual([
      ['terms', 'en'],
      ['traveller_agreement', 'en'],
    ])
    const dialog = wrapper.get('dialog')
    expect(dialog.text()).toContain('Agreement traveller_agreement')
    expect(dialog.attributes('open')).toBe('')
    expect(dialog.get('h2').text()).toBe('Terms and Conditions and Privacy Notice')
    expect(dialog.get('h3').text()).toBe('1. About')
    expect(dialog.findAll('li').map((li) => li.text())).toEqual(['a warning;', 'a ban.'])
    // An unfilled legal-entity placeholder is highlighted, as text.
    expect(dialog.get('mark').text()).toBe('[Operator: legal name]')
    expect(register).not.toHaveBeenCalled()
  })

  it('Cancel closes the terms without registering, and they are not fetched twice', async () => {
    const register = vi.fn<Api['register']>()
    const legal = legalDocument()
    const { wrapper } = await mountRegisterPage(
      '/register',
      fakeApi({ register, legalDocument: legal }),
    )

    await fillAndContinue(wrapper, 'visitor@example.com', 'correcthorse')
    await wrapper.get('dialog footer button.secondary').trigger('click')
    await flushPromises()

    expect(wrapper.get('dialog').attributes('open')).toBeUndefined()
    expect(register).not.toHaveBeenCalled()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.get('dialog').attributes('open')).toBe('')
    expect(legal).toHaveBeenCalledTimes(2)
  })

  it('keeps Accept disabled and offers a retry when the terms fail to load', async () => {
    const legal = vi
      .fn<Api['legalDocument']>()
      .mockRejectedValueOnce(new ApiError('Network error', 0))
      .mockImplementation(bySlug)
    const { wrapper } = await mountRegisterPage('/register', fakeApi({ legalDocument: legal }))

    await fillAndContinue(wrapper, 'visitor@example.com', 'correcthorse')

    expect(wrapper.get('dialog [role="alert"]').text()).toContain("We couldn't load the terms.")
    expect(wrapper.get('dialog button.accept').attributes('disabled')).toBeDefined()

    await wrapper.get('dialog [role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('dialog [role="alert"]').exists()).toBe(false)
    expect(wrapper.get('dialog button.accept').attributes('disabled')).toBeUndefined()
  })
})
