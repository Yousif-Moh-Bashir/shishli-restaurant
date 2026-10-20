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
import {setPageSeo,routeSeo,restaurantSchema,setRestaurantSchema} from './utils/seo'
import {usePublicSettingsStore} from './stores/publicSettings'
import {useBranchStore} from './stores/branch'
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
app.use(router)
const settings = usePublicSettingsStore(pinia), branches = useBranchStore(pinia)
watch([() => router.currentRoute.value.path, () => router.currentRoute.value.name], () => {
  const route = router.currentRoute.value
  setPageSeo(routeSeo(route))
  if (!routeSeo(route).index) setRestaurantSchema(null)
  else setRestaurantSchema(restaurantSchema(settings.data, branches.branches))
}, {flush:'post'})
watch([() => settings.data, () => branches.branches], () => {
  if (routeSeo(router.currentRoute.value).index) setRestaurantSchema(restaurantSchema(settings.data, branches.branches))
}, {deep:true})
app.mount('#app')
