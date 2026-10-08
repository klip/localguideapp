import { afterEach, describe, it, expect, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { LG_QUERY, useMediaQuery } from '@/composables/useMediaQuery'

/** A controllable `matchMedia` — jsdom doesn't have one. */
function stubMatchMedia(initial: boolean) {
  type Listener = (event: MediaQueryListEvent) => void
  const listeners = new Set<Listener>()
  const list = {
    matches: initial,
    addEventListener: vi.fn<(type: string, fn: Listener) => void>((_type, fn) => {
      listeners.add(fn)
    }),
    removeEventListener: vi.fn<(type: string, fn: Listener) => void>((_type, fn) => {
      listeners.delete(fn)
    }),
  }
  vi.stubGlobal(
    'matchMedia',
    vi.fn<(query: string) => typeof list>(() => list),
  )
  return {
    list,
    resize(matches: boolean) {
      list.matches = matches
      for (const fn of listeners) fn({ matches } as MediaQueryListEvent)
    },
  }
}

const Probe = defineComponent({
  setup() {
    const desktop = useMediaQuery(LG_QUERY)
    return () => h('span', desktop.value ? 'desktop' : 'mobile')
  },
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useMediaQuery', () => {
  it('reads the query on first render and follows viewport changes', async () => {
    const media = stubMatchMedia(true)
    const wrapper = mount(Probe)

    expect(window.matchMedia).toHaveBeenCalledWith('(min-width: 1024px)')
    expect(wrapper.text()).toBe('desktop')

    media.resize(false)
    await nextTick()
    expect(wrapper.text()).toBe('mobile')
  })

  it('stops listening when the component unmounts', () => {
    const media = stubMatchMedia(false)
    const wrapper = mount(Probe)

    wrapper.unmount()
    expect(media.list.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function))
  })

  it('is false without matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined)
    expect(mount(Probe).text()).toBe('mobile')
  })
})
