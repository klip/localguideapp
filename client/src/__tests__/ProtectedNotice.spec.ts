import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProtectedNotice from '../components/ProtectedNotice.vue'
import { useLegalStore } from '@/stores/legal'

describe('ProtectedNotice', () => {
  it('explains what paying a guide through RockGuide covers, and opens the terms at 5.1', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(ProtectedNotice, { props: { kind: 'fee' }, global: { plugins: [pinia] } })

    expect(wrapper.text()).toContain('Protected by RockGuide')
    expect(wrapper.text()).toMatch(/refunded by the rules shown/)
    expect(wrapper.text()).toMatch(/outside RockGuide aren’t covered/)

    await wrapper.find('button').trigger('click')
    expect(useLegalStore().termsOpen).toBe(true)
    expect(useLegalStore().section).toBe('5.1')
  })

  it('words it for a selection pack', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mount(ProtectedNotice, {
      props: { kind: 'pack' },
      global: { plugins: [pinia] },
    })
    expect(wrapper.text()).toMatch(/Picks are used only when you choose a guide/)
  })
})
