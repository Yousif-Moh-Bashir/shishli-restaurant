import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { configureHttpAuth } from '../api/http'
import { useFinanceSession } from './session'
import { router } from './router'
import App from './App.vue'
import AdminIcon from '../components/admin/AdminIcon.vue'
import './style.css'

const app = createApp(App)
app.component('FinanceIcon', AdminIcon)
app.use(createPinia())
const session = useFinanceSession()
configureHttpAuth(() => session.token, token => {
  if (token === session.token) { session.clear(); router.replace('/login') }
})
app.use(router).mount('#app')
