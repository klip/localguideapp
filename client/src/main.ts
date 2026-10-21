import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router, { initialRouteName, urlLanguage } from './router'
import { api, apiPlugin } from './plugins/api'
import { ensureScopes, scopesForRoute, setLanguage, setScopeMap } from './i18n'
import { fetchAppMap, installAppMap } from './router/appMap'

import './assets/styles/App.scss'

const BOOT_WAIT_MS = 1500

const app = createApp(App)

app.use(createPinia())
app.use(apiPlugin)

/**
 * The routes are the CMS's page map (src/router/appMap.ts). The router starts
 * with the bundled copy; the live one is fetched in parallel with the first
 * page's text and installed before the router's first navigation — or, if it
 * hasn't come within the wait, the bundled copy stays.
 */
const liveMap = fetchAppMap(() => api.appMap(), BOOT_WAIT_MS)

/**
 * Every language — English included — comes from the `translations` table, so
 * text edited in the CMS shows from the first paint. Only the first page's
 * text is waited for (`scopesForRoute`, from the map's blocks); the rest of
 * the language loads in the background, and a later navigation waits for it
 * briefly if it hasn't landed yet. Past the wait the app renders with the
 * bundled English fallback (`src/i18n/en.json`) and swaps the server's text in
 * when it arrives, so a slow server never means a blank page.
 */
// `urlLanguage` is the URL's language — or, for a URL without one, the saved
// choice — so the URL wins over localStorage (and is saved as the new choice).
const loading = setLanguage(
  urlLanguage,
  async (code, scope) => (await api.translations(code, scope)).strings,
  scopesForRoute(initialRouteName()),
)
const ready = Promise.race([loading, new Promise((resolve) => setTimeout(resolve, BOOT_WAIT_MS))])

router.beforeResolve((to) => ensureScopes(scopesForRoute(to.name)))

void liveMap.then(async (map) => {
  if (map) {
    installAppMap(router, map)
    setScopeMap(map.blocks)
  }
  app.use(router)
  await ready
  app.mount('#app')
})
