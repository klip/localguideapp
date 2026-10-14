import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router, { urlLanguage } from './router'
import { api, apiPlugin } from './plugins/api'
import { setLanguage } from './i18n'

import './assets/styles/App.scss'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(apiPlugin)

/**
 * Every language — English included — comes from the `translations` table, so
 * text edited there (e.g. by a future CMS) shows from the first paint. The
 * fetch gets a moment before mounting; past that the app renders with the
 * bundled English fallback (`src/i18n/en.json`) and swaps the server's text
 * in when it arrives, so a slow server never means a blank page.
 */
const BOOT_LANGUAGE_WAIT_MS = 1500
// `urlLanguage` is the URL's language — or, for a URL without one, the saved
// choice — so the URL wins over localStorage (and is saved as the new choice).
const loading = setLanguage(urlLanguage, async (code) => (await api.translations(code)).strings)
const ready = Promise.race([loading, new Promise((resolve) => setTimeout(resolve, BOOT_LANGUAGE_WAIT_MS))])

void ready.then(() => app.mount('#app'))
