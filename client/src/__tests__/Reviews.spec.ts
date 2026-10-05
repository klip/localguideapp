import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ReviewsSection from '../components/ReviewsSection.vue'
import { API_KEY, type Api, type Review, type ReviewsPage } from '@/plugins/api'
import { characterCount, formatRating, timeAgo } from '@/utils/reviews'

/** A stub `Api` — every method is a no-op `vi.fn()` unless overridden. */
function fakeApi(overrides: Partial<Api> = {}): Api {
  return {
    register: vi.fn<Api['register']>(),
    login: vi.fn<Api['login']>(),
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
    paymentState: vi.fn<Api['paymentState']>(),
    payGuideFee: vi.fn<Api['payGuideFee']>(),
    bookingAction: vi.fn<Api['bookingAction']>(),
    cancelBooking: vi.fn<Api['cancelBooking']>(),
    notifications: vi.fn<Api['notifications']>(),
    decide: vi.fn<Api['decide']>(),
    revokeSelection: vi.fn<Api['revokeSelection']>(),
    matches: vi.fn<Api['matches']>(),
    matchOverview: vi.fn<Api['matchOverview']>(),
    trips: vi.fn<Api['trips']>(),
    publicProfile: vi.fn<Api['publicProfile']>(),
    reviews: vi.fn<Api['reviews']>(),
    submitReview: vi.fn<Api['submitReview']>(),
    deleteReview: vi.fn<Api['deleteReview']>(),
    ...overrides,
  }
}

function review(id: number, overrides: Partial<Review> = {}): Review {
  return {
    id,
    booking_id: 1,
    author_id: 100 + id,
    subject_id: 7,
    rating: 5,
    comment: `Comment ${id}`,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
    author_name: `Author ${id}`,
    author_image: null,
    ...overrides,
  }
}

function page(overrides: Partial<ReviewsPage> = {}): ReviewsPage {
  return {
    reviews: [review(1), review(2), review(3)],
    total: 25,
    average: 4.3,
    histogram: { '5': 15, '4': 5, '3': 3, '2': 1, '1': 1 },
    viewer: { canReview: false, bookingId: null, myReview: null },
    ...overrides,
  }
}

function range(from: number, count: number): Review[] {
  return Array.from({ length: count }, (_, index) => review(from + index))
}

async function mountSection(api: Api) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const wrapper = mount(ReviewsSection, {
    props: { userId: 7, subjectName: 'Igor' },
    global: { plugins: [pinia], provide: { [API_KEY]: api } },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

// jsdom's <dialog> support is partial; stand in for the two methods the
// component uses, including the `close` event the browser fires.
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

describe('review helpers', () => {
  it('formats relative dates the Google Maps way', () => {
    const now = new Date('2026-09-24T12:00:00Z')
    expect(timeAgo('2026-09-24T11:59:30Z', now)).toBe('just now')
    expect(timeAgo('2026-09-24T09:00:00Z', now)).toBe('3 hours ago')
    expect(timeAgo('2026-09-23T11:00:00Z', now)).toBe('a day ago')
    expect(timeAgo('2026-09-10T12:00:00Z', now)).toBe('2 weeks ago')
    expect(timeAgo('2026-06-01T12:00:00Z', now)).toBe('3 months ago')
    expect(timeAgo('2024-01-01T12:00:00Z', now)).toBe('2 years ago')
  })

  it('counts characters the way the server does (emoji are one)', () => {
    expect(characterCount('😀😀')).toBe(2)
    expect(formatRating(4.25, 1)).toBe('★ 4.3 · 1 review')
  })
})

describe('ReviewsSection', () => {
  it('shows the summary, the three newest reviews and a Show all button', async () => {
    const api = fakeApi({
      reviews: vi
        .fn<Api['reviews']>()
        .mockResolvedValue(
          page({ reviews: [review(1, { comment: '<b>bold?</b>' }), review(2), review(3)] }),
        ),
    })
    const wrapper = await mountSection(api)

    expect(api.reviews).toHaveBeenCalledWith({ userId: 7, limit: 3 })
    expect(wrapper.get('[data-test="review-average"]').text()).toBe('4.3')
    expect(wrapper.get('[data-test="review-total"]').text()).toBe('25 reviews')
    expect(wrapper.findAll('.bar-row')).toHaveLength(5)
    expect(wrapper.findAll('.review')).toHaveLength(3)
    expect(wrapper.text()).toContain('Author 1')
    // Comments are text, never markup.
    expect(wrapper.find('.review b').exists()).toBe(false)
    expect(wrapper.text()).toContain('<b>bold?</b>')
    expect(wrapper.get('.show-all').text()).toBe('Show all 25 reviews')
    // Not eligible → no form.
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('says so when there are no reviews, with no Show all', async () => {
    const api = fakeApi({
      reviews: vi
        .fn<Api['reviews']>()
        .mockResolvedValue(
          page({
            reviews: [],
            total: 0,
            average: null,
            histogram: { '5': 0, '4': 0, '3': 0, '2': 0, '1': 0 },
          }),
        ),
    })
    const wrapper = await mountSection(api)

    expect(wrapper.text()).toContain('No reviews yet.')
    expect(wrapper.find('.show-all').exists()).toBe(false)
  })

  it('opens a labelled modal that pages through every review 20 at a time', async () => {
    const reviews = vi
      .fn<Api['reviews']>()
      .mockResolvedValueOnce(page())
      .mockResolvedValueOnce(page({ reviews: range(1, 20) }))
      .mockResolvedValueOnce(page({ reviews: range(21, 5) }))
    const api = fakeApi({ reviews })
    const wrapper = await mountSection(api)

    const showAll = wrapper.get('.show-all')
    await showAll.trigger('click')
    await flushPromises()

    const dialog = wrapper.get('dialog')
    expect(HTMLDialogElement.prototype.showModal).toHaveBeenCalled()
    expect(dialog.attributes('open')).toBeDefined()
    const titleId = dialog.attributes('aria-labelledby')
    expect(titleId && document.getElementById(titleId)?.textContent).toBe('Reviews of Igor')

    expect(reviews).toHaveBeenLastCalledWith({ userId: 7, limit: 20, offset: 0 })
    expect(dialog.findAll('.review')).toHaveLength(20)
    expect(dialog.text()).toContain('Showing 20 of 25')

    await dialog.get('.more').trigger('click')
    await flushPromises()

    expect(reviews).toHaveBeenLastCalledWith({ userId: 7, limit: 20, offset: 20 })
    expect(dialog.findAll('.review')).toHaveLength(25)
    expect(dialog.find('.more').exists()).toBe(false)

    // Escape (or the ✕) ends in the native close event; focus goes back.
    ;(dialog.element as HTMLDialogElement).close()
    await flushPromises()
    expect(dialog.attributes('open')).toBeUndefined()
    expect(document.activeElement).toBe(showAll.element)
  })

  it('lets an eligible viewer post a review with a 1–5 star picker and a 256-character limit', async () => {
    const reviews = vi
      .fn<Api['reviews']>()
      .mockResolvedValue(page({ viewer: { canReview: true, bookingId: 3, myReview: null } }))
    const submitReview = vi
      .fn<Api['submitReview']>()
      .mockResolvedValue(review(99, { rating: 4, comment: 'Great 😀' }))
    const api = fakeApi({ reviews, submitReview })
    const wrapper = await mountSection(api)

    const form = wrapper.get('form')
    expect(form.text()).toContain('Write a review of Igor')

    const stars = form.findAll('input[type="radio"]')
    expect(stars).toHaveLength(5)
    expect(form.get('fieldset legend').text()).toBe('Your rating')
    expect(form.get(`label[for="${stars[3]!.attributes('id')}"]`).text()).toContain('4 stars')

    const submit = form.get('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeDefined()

    await stars[3]!.setValue()
    expect(submit.attributes('disabled')).toBeUndefined()

    const textarea = form.get('textarea')
    await textarea.setValue('a'.repeat(257))
    expect(form.get('.counter').text()).toContain('257 / 256')
    expect(submit.attributes('disabled')).toBeDefined()

    await textarea.setValue('Great 😀')
    expect(form.get('.counter').text()).toContain('7 / 256')
    expect(submit.attributes('disabled')).toBeUndefined()

    await form.trigger('submit')
    await flushPromises()

    expect(submitReview).toHaveBeenCalledWith({ subjectId: 7, rating: 4, comment: 'Great 😀' })
    // Refetched, so the summary and list include the new review.
    expect(reviews).toHaveBeenCalledTimes(2)
  })

  it('prefills an existing review for editing and lets its author delete it', async () => {
    const mine = review(42, { rating: 3, comment: 'Fine' })
    const reviews = vi
      .fn<Api['reviews']>()
      .mockResolvedValueOnce(page({ viewer: { canReview: true, bookingId: 3, myReview: mine } }))
      .mockResolvedValue(page({ viewer: { canReview: true, bookingId: 3, myReview: null } }))
    const deleteReview = vi.fn<Api['deleteReview']>().mockResolvedValue({ ok: true })
    const api = fakeApi({ reviews, deleteReview })
    const wrapper = await mountSection(api)

    const form = wrapper.get('form')
    expect(form.text()).toContain('Edit your review')
    const stars = form.findAll<HTMLInputElement>('input[type="radio"]')
    expect(stars[2]!.element.checked).toBe(true)
    expect(form.get('textarea').element.value).toBe('Fine')

    const deleteButton = form.findAll('button').find((button) => button.text() === 'Delete review')
    await deleteButton!.trigger('click')
    const confirm = form.findAll('button').find((button) => button.text() === 'Yes, delete')
    await confirm!.trigger('click')
    await flushPromises()

    expect(deleteReview).toHaveBeenCalledWith({ reviewId: 42 })
    expect(wrapper.get('form').text()).toContain('Write a review of Igor')
  })
})
