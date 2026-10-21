import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ContentPage from '../pages/ContentPage.vue'
import { ApiError, type ContentBlock, type ContentLayout } from '@/api'

vi.mock('@/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api')>()
  type Api = typeof actual.api
  return {
    ...actual,
    api: {
      contentLayout: vi.fn<Api['contentLayout']>(),
      contentBlock: vi.fn<Api['contentBlock']>(),
      contentSave: vi.fn<Api['contentSave']>(),
      contentAddKey: vi.fn<Api['contentAddKey']>(),
      contentHistory: vi.fn<Api['contentHistory']>(),
      contentRevert: vi.fn<Api['contentRevert']>(),
    },
  }
})
const { api } = await import('@/api')

const LAYOUT: ContentLayout = {
  shell: { header: ['common.app_header'], footer: ['common.app_footer'], always: ['common.api'] },
  pages: [
    {
      route: 'discover',
      path: '/discover',
      title: 'Discover',
      enabled: true,
      main: ['discover.deck', 'discover.empty'],
      shared: ['common.filter_panel'],
    },
    {
      route: 'matches',
      path: '/matches',
      title: 'Matches',
      enabled: true,
      main: ['matches.tabs'],
      shared: [],
    },
  ],
  unplaced: [],
  languages: ['en', 'es', 'ru'],
}

function deck(
  language: string,
  picked: string | null = '{picked} / {capacity} elegidos',
): ContentBlock {
  return {
    prefix: 'discover.deck',
    language,
    entries: [
      {
        key: 'discover.deck.picked',
        name: 'picked',
        value: language === 'en' ? '{picked} / {capacity} picked' : picked,
        english: '{picked} / {capacity} picked',
        missing: language !== 'en' && picked === null,
      },
      {
        key: 'discover.deck.title',
        name: 'title',
        value: language === 'en' ? 'Guides for you' : null,
        english: 'Guides for you',
        missing: language !== 'en',
      },
    ],
    missingPluralForms: [],
  }
}

async function open(query: Record<string, string> = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/content', component: ContentPage }],
  })
  await router.push({ path: '/content', query })
  const wrapper = mount(ContentPage, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  vi.mocked(api.contentLayout).mockResolvedValue(LAYOUT)
  vi.mocked(api.contentBlock).mockImplementation(async (_prefix, language) => deck(language))
})

afterEach(() => {
  vi.clearAllMocks()
})

describe('the content editor', () => {
  it('draws the picked page as a wireframe of block links', async () => {
    const { wrapper, router } = await open()
    const blocks = () => wrapper.findAll('[data-test="wireframe"] a').map((a) => a.text())
    expect(blocks()).toEqual(['app header', 'deck', 'empty', 'filter panel', 'app footer'])
    expect(wrapper.find('[data-test="pick-hint"]').exists()).toBe(true)

    await wrapper.find('[data-test="page-picker"]').setValue('matches')
    await flushPromises()
    expect(router.currentRoute.value.query.page).toBe('matches')
    expect(blocks()).toEqual(['app header', 'tabs', 'app footer'])
  })

  it('opens a block titled "[page] › [block]", fields named by the last key part', async () => {
    const { wrapper, router } = await open()
    await wrapper.find('[data-block="discover.deck"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.block).toBe('discover.deck')
    expect(wrapper.find('[data-test="block-title"]').text()).toContain('Discover › deck')
    expect(api.contentBlock).toHaveBeenCalledWith('discover.deck', 'en')
    const names = wrapper.findAll('.field-name').map((n) => n.text())
    expect(names).toEqual(['picked', 'title'])

    // A header block reads as the header's, whatever the page.
    await wrapper.find('[data-block="common.app_header"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="block-title"]').text()).toContain('Header › app header')
  })

  it('switches language, marking untranslated texts with the English as placeholder', async () => {
    const { wrapper } = await open({ block: 'discover.deck' })
    await wrapper.find('[data-test="language"]').setValue('es')
    await flushPromises()
    expect(api.contentBlock).toHaveBeenLastCalledWith('discover.deck', 'es')
    const title = wrapper.find('[data-key="discover.deck.title"]')
    expect(title.find('[data-test="missing"]').exists()).toBe(true)
    expect(title.find('textarea').attributes('placeholder')).toBe('Guides for you')
    expect(wrapper.find('[data-key="discover.deck.picked"] [data-test="missing"]').exists()).toBe(
      false,
    )
  })

  it('saves only what changed', async () => {
    vi.mocked(api.contentSave).mockResolvedValue({ ...deck('es'), changed: 1 })
    const { wrapper } = await open({ block: 'discover.deck', lang: 'es' })
    await wrapper.find('[data-key="discover.deck.title"] textarea').setValue('Guías para ti')
    await wrapper.find('[data-test="texts"]').trigger('submit.prevent')
    await flushPromises()
    expect(api.contentSave).toHaveBeenCalledWith('discover.deck', 'es', {
      'discover.deck.title': 'Guías para ti',
    })
    expect(wrapper.find('[data-test="notice"]').text()).toBe('Saved 1 text.')
  })

  it('pins a validation error under its field', async () => {
    vi.mocked(api.contentSave).mockRejectedValue(
      new ApiError('Keep the placeholders {picked}, {capacity}.', 400, 'discover.deck.picked'),
    )
    const { wrapper } = await open({ block: 'discover.deck', lang: 'es' })
    const field = wrapper.find('[data-key="discover.deck.picked"]')
    await field.find('textarea').setValue('elegidos')
    await wrapper.find('[data-test="texts"]').trigger('submit.prevent')
    await flushPromises()
    expect(field.find('[data-test="field-error"]').text()).toContain('{picked}')
    expect(field.find('textarea').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('[data-test="error"]').exists()).toBe(false)
  })

  it('holds a language switch while there are unsaved edits', async () => {
    const { wrapper, router } = await open({ block: 'discover.deck', lang: 'es' })
    await wrapper.find('[data-key="discover.deck.title"] textarea').setValue('Guías')
    await wrapper.find('[data-test="language"]').setValue('ru')
    await flushPromises()
    expect(router.currentRoute.value.query.lang).toBe('es')
    expect(wrapper.find('[data-test="unsaved"]').exists()).toBe(true)

    await wrapper.find('[data-test="discard"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.lang).toBe('ru')
  })

  it('adds a key to the block', async () => {
    vi.mocked(api.contentAddKey).mockResolvedValue({ ...deck('en'), key: 'discover.deck.hint' })
    const { wrapper } = await open({ block: 'discover.deck' })
    const form = wrapper.find('[data-test="add-key"]')
    await form.find('input').setValue('hint')
    await form.find('textarea').setValue('Swipe right on a guide you like')
    await form.trigger('submit.prevent')
    await flushPromises()
    expect(api.contentAddKey).toHaveBeenCalledWith(
      'discover.deck',
      'hint',
      'Swipe right on a guide you like',
      'en',
    )
    expect(wrapper.find('[data-test="notice"]').text()).toBe('Added discover.deck.hint.')
  })

  it('lists the history on demand and reverts a change', async () => {
    vi.mocked(api.contentHistory).mockResolvedValue({
      history: [
        {
          id: 7,
          language: 'es',
          key: 'discover.deck.picked',
          oldValue: '{picked} / {capacity} elegidos',
          newValue: '{picked} de {capacity}',
          staffEmail: 'editor@example.test',
          createdAt: '2026-10-21T09:00:00Z',
        },
      ],
    })
    vi.mocked(api.contentRevert).mockResolvedValue({
      language: 'es',
      key: 'discover.deck.picked',
      value: '{picked} / {capacity} elegidos',
    })
    const { wrapper } = await open({ block: 'discover.deck', lang: 'es' })
    const history = wrapper.find('[data-test="history"]')
    ;(history.element as HTMLDetailsElement).open = true
    await history.trigger('toggle')
    await flushPromises()
    expect(api.contentHistory).toHaveBeenCalledWith('discover.deck')
    expect(history.find('[data-change="7"]').text()).toContain('editor@example.test')

    await history.find('[data-test="revert"]').trigger('click')
    await flushPromises()
    expect(api.contentRevert).toHaveBeenCalledWith(7)
    expect(api.contentHistory).toHaveBeenCalledTimes(2)
    expect(api.contentBlock).toHaveBeenLastCalledWith('discover.deck', 'es')
  })
})
