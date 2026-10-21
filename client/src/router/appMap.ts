import type { RouteRecordRaw, Router } from 'vue-router'
import bundled from './appMap.json'
import { PAGES } from './pages'

/**
 * The app's routes are managed in the CMS (`app_routes`, `lib/AppMap.php`):
 * which path shows which page and who may open it. The app fetches them at
 * start-up (`appMap`) and builds its router from them (`installAppMap()`).
 * What a page contains stays in the code. The map also carries each page's
 * text blocks (`app_route_blocks`, developer-owned — migrations, checked by
 * `yarn i18n:check`), which only tell i18n what text to load first.
 *
 * `appMap.json` is a copy pulled from the database (`api/bin/pull-app-map.php`)
 * — never edited by hand — used until the live map arrives, when it can't be
 * fetched, and by tests.
 */
export type RouteAccess = 'public' | 'signed_in' | 'signed_out'

export interface AppRoute {
  name: string
  path: string
  /** A key of `PAGES`; null for a redirect. */
  component: string | null
  redirect: string | null
  props: boolean
  access: RouteAccess
  role: 'guest' | 'guide' | null
}

export interface AppMap {
  version: string
  routes: AppRoute[]
  /** Route name → its text blocks (`page.widget`); `_shell` is the frame around every page. */
  blocks: Record<string, string[]>
}

declare module 'vue-router' {
  interface RouteMeta {
    access?: RouteAccess
    role?: 'guest' | 'guide' | null
  }
}

export const BUNDLED_MAP = bundled as AppMap

/** Router records for `map`'s routes; one naming a page this build doesn't have is left out. */
export function toRouteRecords(map: AppMap): RouteRecordRaw[] {
  const records: RouteRecordRaw[] = []
  for (const route of map.routes) {
    const meta = { access: route.access, role: route.role }
    if (route.redirect) {
      records.push({ name: route.name, path: route.path, redirect: route.redirect, meta })
    } else if (route.component && PAGES[route.component]) {
      records.push({
        name: route.name,
        path: route.path,
        component: PAGES[route.component]!,
        props: route.props,
        meta,
      })
    } else {
      console.warn(`[router] ${route.name}: no page component "${route.component}" in this build`)
    }
  }
  return records
}

/** Replaces every route the router has with `map`'s. Call before `app.use(router)`. */
export function installAppMap(router: Router, map: AppMap) {
  for (const route of router.getRoutes()) {
    if (route.name !== undefined) router.removeRoute(route.name)
  }
  for (const record of toRouteRecords(map)) router.addRoute(record)
}

/** The live map, or null if it doesn't come within `waitMs` (the bundled copy is used then). */
export async function fetchAppMap(
  fetcher: () => Promise<AppMap>,
  waitMs: number,
): Promise<AppMap | null> {
  let timer: ReturnType<typeof setTimeout> | undefined
  try {
    return await Promise.race([
      fetcher().then((map) => (Array.isArray(map?.routes) && map.routes.length ? map : null)),
      new Promise<null>((resolve) => {
        timer = setTimeout(() => resolve(null), waitMs)
      }),
    ])
  } catch {
    return null
  } finally {
    clearTimeout(timer)
  }
}
