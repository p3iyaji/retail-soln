import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAppearanceStore } from './stores/appearance'
import './style.css'

const pinia = createPinia()
const app = createApp(App)

app.use(pinia)

useAppearanceStore().init()

app.use(router).mount('#app')
