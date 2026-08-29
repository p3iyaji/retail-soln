import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { bootstrapPosApp } from './bootstrap'
import './style.css'

const pinia = createPinia()
const app = createApp(App)
app.use(pinia).use(router)

void bootstrapPosApp(app)
