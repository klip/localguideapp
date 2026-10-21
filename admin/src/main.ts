import '@picocss/pico/css/pico.min.css'
import './styles.css'

import { createApp } from 'vue'
import App from './App.vue'
import { createCmsRouter } from './router'

createApp(App).use(createCmsRouter()).mount('#app')
