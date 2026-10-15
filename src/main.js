import '@fontsource-variable/inter'
import '@fontsource-variable/space-grotesk'
import './styles/tokens.css'
import './styles/base.css'

import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { initTheme } from './lib/theme'

initTheme()

const app = createApp(App)

app.use(router)

app.mount('#app')
