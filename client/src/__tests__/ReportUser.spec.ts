import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ReportUser from '../components/ReportUser.vue'
import { API_KEY, ApiError, type Api } from '@/plugins/api'
import { useAuthStore } from '@/stores/auth'
import { useMessagesStore } from '@/stores/messages'
import { fakeApi } from './fakeApi'

// jsdom's <dialog> support is partial; stand in for the two methods
// ModalDialog uses, including the `close` event the browser fires.
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
  localStorage.clear()
})

function mountReport(api: Api, signedInAs: number | null = 7) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (signedInAs) {
    useAuthStore().setUser({ id: signedInAs, email: 'v@example.com', role: 'guest', sessionHash: 'h' })
  }
  return mount(ReportUser, {
    props: { subjectId: 101, subjectName: 'Gina' },
    global: { plugins: [pinia], provide: { [API_KEY]: api } },
  })
}

describe('ReportUser', () => {
  it('is offered only to a signed-in user looking at someone else', () => {
    expect(mountReport(fakeApi(), null).find('[data-test="report-user"]').exists()).toBe(false)
    expect(mountReport(fakeApi(), 101).find('[data-test="report-user"]').exists()).toBe(false)
    expect(mountReport(fakeApi(), 7).find('[data-test="report-user"]').text()).toContain('Report Gina')
  })

  it('needs a title and a description, then files the report and thanks the user', async () => {
    const reportUser = vi
      .fn<Api['reportUser']>()
      .mockResolvedValue({ id: 1, status: 'open', created_at: '2026-10-15T10:00:00Z' })
    const wrapper = mountReport(fakeApi({ reportUser }))

    await wrapper.find('[data-test="report-user"]').trigger('click')
    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled()
    const send = wrapper.find('[data-test="report-send"]')
    expect(send.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="report-title"]').setValue('  Asked to pay outside the app  ')
    expect(send.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="report-description"]').setValue('Wanted cash and no booking.')
    expect(send.attributes('disabled')).toBeUndefined()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(reportUser).toHaveBeenCalledWith({
      subjectId: 101,
      title: 'Asked to pay outside the app',
      description: 'Wanted cash and no booking.',
    })
    expect(wrapper.find('dialog').attributes('open')).toBeUndefined()
    expect(useMessagesStore().items.map((item) => item.text)).toContain(
      'Thanks — your report was sent. Our moderators will look into it.',
    )
  })

  it('keeps what was written and shows the server’s reason when a report is refused', async () => {
    const reportUser = vi
      .fn<Api['reportUser']>()
      .mockRejectedValue(new ApiError('You’ve already reported this person — we’re looking into it.', 400))
    const wrapper = mountReport(fakeApi({ reportUser }))

    await wrapper.find('[data-test="report-user"]').trigger('click')
    await wrapper.find('[data-test="report-title"]').setValue('Again')
    await wrapper.find('[data-test="report-description"]').setValue('Still happening.')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(useMessagesStore().items.map((item) => item.text)).toContain(
      'You’ve already reported this person — we’re looking into it.',
    )
    expect((wrapper.find('[data-test="report-title"]').element as HTMLInputElement).value).toBe('Again')
    expect(wrapper.find('dialog').attributes('open')).toBeDefined()
  })

  it('blocks a title over 120 characters', async () => {
    const wrapper = mountReport(fakeApi())
    await wrapper.find('[data-test="report-user"]').trigger('click')
    await wrapper.find('[data-test="report-title"]').setValue('x'.repeat(121))
    await wrapper.find('[data-test="report-description"]').setValue('Details')

    expect(wrapper.find('[data-test="report-send"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="report-title"]').attributes('aria-invalid')).toBe('true')
  })
})
