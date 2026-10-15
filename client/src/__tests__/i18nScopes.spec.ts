import { describe, it, expect } from 'vitest'
// @ts-expect-error — a plain Node script, no type declarations.
import { buildScopes, currentFile, render } from '../../scripts/i18n-scopes.mjs'
import en from '@/i18n/en.json'

describe('i18n scopes', () => {
  it('scopes.json matches the code — run `yarn i18n:scopes` after adding text to a page', () => {
    expect(currentFile()).toBe(render(buildScopes()))
  })

  it('every key belongs to the shell or to at least one route, so no page waits on text it never asked for', () => {
    const { shell, routes } = buildScopes() as { shell: string[]; routes: Record<string, string[]> }
    const covered = new Set([...shell, ...Object.values(routes).flat()])
    const uncovered = Object.keys(en).filter(
      (key) => !covered.has(key.split('.').slice(0, 2).join('.')),
    )
    expect(uncovered).toEqual([])
  })
})
