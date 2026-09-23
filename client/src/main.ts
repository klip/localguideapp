import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { apiPlugin } from './plugins/api'

import './assets/styles/App.scss'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(apiPlugin)

app.mount('#app')
