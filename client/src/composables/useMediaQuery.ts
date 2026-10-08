import { onBeforeUnmount, ref, type Ref } from 'vue'

/** Matches `breakpoints.$lg` — the three-column `.discovery-layout`. */
export const LG_QUERY = '(min-width: 1024px)'

/**
 * Whether `query` currently matches, kept up to date as the viewport changes.
 * Reads synchronously, so a component can use it for its first render; the
 * listener is removed when the calling component unmounts. Without
 * `matchMedia` (jsdom, SSR) it's simply `false`.
 */
export function useMediaQuery(query: string): Ref<boolean> {
  const list = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia(query) : null
  const matches = ref(list?.matches ?? false)

  if (list) {
    const onChange = (event: MediaQueryListEvent) => {
      matches.value = event.matches
    }
    list.addEventListener('change', onChange)
    onBeforeUnmount(() => list.removeEventListener('change', onChange))
  }

  return matches
}
