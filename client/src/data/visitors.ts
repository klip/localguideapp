import type { Visitor } from '@/types'

/**
 * Placeholder visitor deck shown on the guide side of the match. Same swap-out
 * story as `guides.ts`.
 */
export const visitors: Visitor[] = [
  {
    id: 'mark',
    name: 'Mark',
    age: 42,
    party: '2 adults · Aurora Vista cruise',
    bio: 'First time in Gibraltar. We’d like a relaxed private tour with history, scenic viewpoints and a proper local lunch.',
    attributes: { languages: ['English'], interests: ['history', 'food'] },
    ranges: {},
    durationHours: 6,
    photo: 'var(--rg-photo-visitor)',
  },
  {
    id: 'lena',
    name: 'Lena',
    age: 34,
    party: '2 adults + 1 child · Sea Meridian cruise',
    bio: 'We have four hours and a seven-year-old. Monkeys are non-negotiable, everything else is flexible.',
    attributes: { languages: ['English', 'German'], interests: ['family', 'nature'] },
    ranges: {},
    durationHours: 4,
    photo: 'linear-gradient(135deg, #c9d8de, #7f94a3 48%, #dcd9d2)',
  },
  {
    id: 'carlos',
    name: 'Carlos',
    age: 58,
    party: '4 adults · independent travellers',
    bio: 'Group of friends, all Spanish speakers, staying two nights. Interested in the siege tunnels and a long lunch afterwards.',
    attributes: { languages: ['Spanish', 'English'], interests: ['history', 'food'] },
    ranges: {},
    durationHours: 8,
    photo: 'linear-gradient(135deg, #ded0c0, #94867a 48%, #d3cfc8)',
  },
]

export function findVisitor(id: string): Visitor | undefined {
  return visitors.find((visitor) => visitor.id === id)
}
