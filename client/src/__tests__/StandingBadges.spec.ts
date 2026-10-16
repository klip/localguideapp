import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StandingBadges from '../components/StandingBadges.vue'
import ProfileCard from '../components/ProfileCard.vue'
import { standingOf } from '@/utils/standing'

describe('StandingBadges', () => {
  it('shows each earned badge, with a plural trips count', () => {
    const wrapper = mount(StandingBadges, {
      props: { standing: { verified: true, topRated: true, tripsCompleted: 12, level: 4 } },
    })
    expect(wrapper.find('[data-test="standing-verified"]').text()).toContain('Verified')
    expect(wrapper.find('[data-test="standing-verified"]').attributes('title')).toMatch(/ID document/)
    expect(wrapper.find('[data-test="standing-top"]').text()).toContain('Top rated')
    expect(wrapper.find('[data-test="standing-trips"]').text()).toContain('12 trips')
    expect(wrapper.find('[data-test="standing-level"]').text()).toContain('Level 4 · Trailblazer')
  })

  it('uses the singular for one trip and renders nothing with nothing earned', () => {
    expect(mount(StandingBadges, { props: { standing: { verified: false, topRated: false, tripsCompleted: 1, level: 1 } } }).text()).toContain(
      '1 trip',
    )
    const empty = mount(StandingBadges, { props: { standing: { verified: false, topRated: false, tripsCompleted: 0, level: 0 } } })
    expect(empty.find('ul').exists()).toBe(false)
  })

  it('maps the server row, and appears on the deck card', () => {
    const standing = standingOf({ verified: true, top_rated: false, trips_completed: 2 })
    expect(standing).toEqual({ verified: true, topRated: false, tripsCompleted: 2, level: 0 })

    const card = mount(ProfileCard, {
      props: { name: 'Gina', age: 34, photo: 'none', standing },
      global: { stubs: { RouterLink: true } },
    })
    expect(card.find('.photo-copy [data-test="standing-verified"]').exists()).toBe(true)
    expect(card.find('[data-test="standing-trips"]').text()).toContain('2 trips')
  })
})
