import type { Guide } from '@/types'

/**
 * Placeholder guide deck. Swap for an API call once the backend exists — the
 * pages only depend on the `Guide` shape, not on this module.
 */
export const guides: Guide[] = [
  {
    id: 'sofia',
    name: 'Sofia',
    age: 31,
    headline: 'History · Food · Scenic walks',
    ratingAverage: 4.9,
    reviewCount: 47,
    priceLabel: '£95 / 6 hours',
    priceNote: 'Private day route',
    bio: 'I’m a local history nerd who prefers hidden lanes, good coffee and viewpoints without the coach-bus queue.',
    includes:
      'route planning, private guiding, attraction coordination and café stop. Tickets not included.',
    attributes: { languages: ['English', 'Spanish'], interests: ['history', 'food', 'photography'] },
    ranges: {},
    gender: 'female',
    photo: 'var(--rg-photo-guide)',
  },
  {
    id: 'daniel',
    name: 'Daniel',
    age: 38,
    headline: 'Nature · Viewpoints · Photography',
    ratingAverage: 4.8,
    reviewCount: 62,
    priceLabel: '£120 / 6 hours',
    priceNote: 'Upper Rock nature route',
    bio: 'Ex-park ranger. I take small groups along the quiet trails, time the macaque encounters properly, and know where the light is good at 5pm.',
    includes: 'trail planning, private guiding and transport between trailheads. Tickets not included.',
    attributes: { languages: ['English'], interests: ['nature', 'photography', 'history'] },
    ranges: {},
    gender: 'male',
    photo: 'linear-gradient(135deg, #cfe0d2, #7f9a86 48%, #dcd6c6)',
  },
  {
    id: 'amina',
    name: 'Amina',
    age: 27,
    headline: 'Food · Markets · Family-friendly',
    ratingAverage: 5,
    reviewCount: 23,
    priceLabel: '£80 / 4 hours',
    priceNote: 'Relaxed half day',
    bio: 'Short, easy days built around eating well. Good with kids, good with grandparents, no forced marches up the Rock.',
    includes: 'route planning, private guiding and two tasting stops. Food costs not included.',
    attributes: { languages: ['English', 'Spanish', 'Arabic'], interests: ['food', 'family'] },
    ranges: {},
    gender: 'female',
    photo: 'linear-gradient(135deg, #f4cfae, #b58f7a 48%, #e6d9cd)',
  },
  {
    id: 'tom',
    name: 'Tom',
    age: 45,
    headline: 'Military history · Tunnels · Sieges',
    ratingAverage: 4.7,
    reviewCount: 88,
    priceLabel: '£110 / 5 hours',
    priceNote: 'Siege tunnels deep dive',
    bio: 'Twenty years on the Rock and a genuine obsession with the Great Siege. Expect detail, dates, and a lot of tunnel.',
    includes: 'route planning, private guiding and tunnel access coordination. Tickets not included.',
    attributes: { languages: ['English', 'Spanish'], interests: ['history'] },
    ranges: {},
    gender: 'male',
    photo: 'linear-gradient(135deg, #d8c3b0, #8a7a6d 48%, #cfc6bd)',
  },
]

export function findGuide(id: string): Guide | undefined {
  return guides.find((guide) => guide.id === id)
}
