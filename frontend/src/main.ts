import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import './style.css'
import '@ioi-dev/vue-table/styles.css'
import '@ioi-dev/vue-table/themes/shadcn.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
