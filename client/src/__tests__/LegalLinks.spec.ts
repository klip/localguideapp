import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AppFooter from '../components/AppFooter.vue'
import CookieNotice from '../components/CookieNotice.vue'
import TermsDialog from '../components/TermsDialog.vue'
import { API_KEY, type Api, type LegalDocumentView } from '@/plugins/api'
import { useLegalStore } from '@/stores/legal'

const TERMS: LegalDocumentView = {
  slug: 'terms',
  version: '2026-10-15',
  language: 'en',
  title: 'Terms and Conditions and Privacy Notice',
  body: '## 1. About\n\nText.\n\n## 9. Privacy notice (GDPR)\n\n### 9.8 Storage on your device\n\nLocal storage only.',
}

// jsdom's <dialog> support is partial; stand in for the two methods used.
const originalShowModal = HTMLDialogElement.prototype.showModal
const originalClose = HTMLDialogElement.prototype.close

beforeEach(() => {
  setActivePinia(createPinia())
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
  localStorage.clear()
})

describe('footer legal links', () => {
  it('replace the old tagline and open the terms — the privacy link at section 9', async () => {
    const wrapper = mount(AppFooter)
    const legal = useLegalStore()

    expect(wrapper.text()).not.toContain('Concept UI')
    const [terms, privacy] = wrapper.findAll('nav button')
    expect(terms!.text()).toBe('Terms & conditions')
    expect(privacy!.text()).toBe('Privacy & cookies')

    await terms!.trigger('click')
    expect(legal.termsOpen).toBe(true)
    expect(legal.section).toBeNull()

    await privacy!.trigger('click')
    expect(legal.section).toBe('9')
  })
})

describe('CookieNotice', () => {
  it('shows until OK, and stays dismissed on the next visit', async () => {
    const first = mount(CookieNotice)
    expect(first.find('section').attributes('aria-label')).toBe('Cookies and storage')
    expect(first.text()).toContain('No advertising or tracking cookies.')

    await first.findAll('button')[1]!.trigger('click')
    expect(first.find('section').exists()).toBe(false)

    expect(mount(CookieNotice).find('section').exists()).toBe(false)
  })

  it('shows again when the notice changes (an older acknowledgement)', () => {
    localStorage.setItem('rockguide.cookieNotice', '0')
    expect(mount(CookieNotice).find('section').exists()).toBe(true)
  })

  it('links to the storage section of the privacy notice', async () => {
    const wrapper = mount(CookieNotice)
    await wrapper.findAll('button')[0]!.trigger('click')

    const legal = useLegalStore()
    expect(legal.termsOpen).toBe(true)
    expect(legal.section).toBe('9.8')
  })
})

describe('TermsDialog', () => {
  it('fetches the terms only when opened, then scrolls to the section asked for', async () => {
    const legalDocument = vi.fn<Api['legalDocument']>().mockResolvedValue(TERMS)
    const scrolled: string[] = []
    const original = Element.prototype.scrollIntoView
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolled.push(this.getAttribute('data-section') ?? '')
    }

    const wrapper = mount(TermsDialog, {
      attachTo: document.body,
      props: { open: false, section: '9.8' },
      global: { provide: { [API_KEY]: { legalDocument } as unknown as Api } },
    })
    await flushPromises()
    expect(legalDocument).not.toHaveBeenCalled()

    await wrapper.setProps({ open: true })
    await flushPromises()

    expect(legalDocument).toHaveBeenCalledWith('terms', 'en')
    expect(wrapper.get('dialog h2').text()).toBe('Terms and Conditions and Privacy Notice')
    expect(scrolled).toEqual(['9.8'])
    // No footer slot given: a plain Close.
    expect(wrapper.get('dialog footer button').text()).toBe('Close')

    Element.prototype.scrollIntoView = original
  })
})
