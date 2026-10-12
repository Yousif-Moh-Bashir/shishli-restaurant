import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/css/site.css'
import './assets/css/skeleton.css'
import './assets/css/modals.css'
import './assets/css/settings.css'
import './assets/css/public-restoration.css'
import './assets/css/cart-feedback.css'
import {adminTable} from './directives/adminTable'
import { useAuthStore } from './stores/auth'
import { useCartStore } from './stores/cart'
import { configureHttpAuth } from './api/http'
import { installAuthGuards } from './router/authGuards'
const app = createApp(App), pinia = createPinia()
app.directive('admin-table',adminTable)
app.use(pinia)
const auth = useAuthStore(pinia)
const cart = useCartStore(pinia)
watch(() => auth.token, () => { void cart.sessionChanged() })
configureHttpAuth(() => auth.token, expiredToken => {
  if (auth.clearSession(expiredToken) && router.currentRoute.value.meta.requiresAuth) {
    void router.replace({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})
installAuthGuards(router, auth)
app.use(router).mount('#app')
