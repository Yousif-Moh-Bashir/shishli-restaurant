<script setup>
import { ref, onMounted, onBeforeUnmount, inject } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import SiteIcon from './SiteIcon.vue'
import { useCartStore } from '../../stores/cart'
import { useRestaurantInfo } from '../../composables/useRestaurantInfo'
const restaurant=useRestaurantInfo()
import { useAuthStore } from '../../stores/auth'
import { useSessionLogout } from '../../composables/useSessionLogout'
const auth = useAuthStore()
const { toast,openCart } = inject('site')
const { logout } = useSessionLogout(toast)
const cartStore = useCartStore()
const emit = defineEmits(['search'])
const route = useRoute()
const menuOpen = ref(false), scrolled = ref(false), headerElement = ref(null), menuButton = ref(null), searchButton = ref(null)
defineExpose({ focusSearch: () => searchButton.value?.focus(), closeMenu: () => { menuOpen.value = false } })
const closeMenu = () => { menuOpen.value = false }
const updateHeader = () => { scrolled.value = window.scrollY > 12 }
const outsideClick = event => { if (!headerElement.value?.contains(event.target)) closeMenu() }
const escapeMenu = event => { if(event.key === 'Escape' && menuOpen.value) {closeMenu(); menuButton.value?.focus()} }
let desktop
onMounted(() => {desktop = matchMedia('(min-width:1100px)'); desktop.addEventListener('change', closeMenu); window.addEventListener('scroll',updateHeader,{passive:true}); document.addEventListener('click',outsideClick); document.addEventListener('keydown',escapeMenu); updateHeader()})
onBeforeUnmount(() => {desktop?.removeEventListener('change',closeMenu); window.removeEventListener('scroll',updateHeader); document.removeEventListener('click',outsideClick); document.removeEventListener('keydown',escapeMenu)})
</script>
<template>
  <header class="site-header" id="site-header" ref="headerElement" :class="{ scrolled }">
    <div class="container header-inner">
      <RouterLink class="brand" to="/#home" aria-label="مطعم شيشلي، الرئيسية"><span class="brand-image" role="img" aria-label="شعار مطعم شيشلي"></span></RouterLink>
      <nav class="main-nav" id="main-nav" :class="{ 'is-open': menuOpen }" @click="menuOpen = false" aria-label="القائمة الرئيسية">
        <RouterLink to="/#home" :class="{ active: route.name === 'home' }" :aria-current="route.name === 'home' ? 'page' : undefined">الرئيسية</RouterLink><RouterLink to="/#about">من نحن</RouterLink><RouterLink to="/menu" :class="{ active: route.name === 'menu' }" :aria-current="route.name === 'menu' ? 'page' : undefined">المنيو</RouterLink><RouterLink to="/orders" :class="{active: ['customer-orders','account.orders','account.order','track-order','track-order.detail'].includes(route.name)}">طلباتي</RouterLink><RouterLink to="/#gallery">معرض الصور</RouterLink><RouterLink to="/branches">فروعنا</RouterLink><RouterLink to="/#contact">تواصل معنا</RouterLink>
      <RouterLink v-if="auth.isAuthenticated" class="mobile-account-link" to="/account/profile">حسابي</RouterLink><RouterLink v-if="auth.isAuthenticated" class="mobile-account-link" to="/account/addresses">عناويني</RouterLink><RouterLink v-if="!auth.isAuthenticated" class="mobile-account-link" to="/login">تسجيل الدخول</RouterLink><button v-else class="mobile-account-link" type="button" :disabled="auth.isLoading" @click="logout">{{ auth.user.name }} · خروج</button></nav>
      <div class="header-actions">
        <RouterLink v-if="!['home', 'menu'].includes(route.name)" class="icon-button desktop-action" to="/menu" aria-label="البحث في الأطباق"><SiteIcon name="search" /></RouterLink>
        <button v-else class="icon-button desktop-action" type="button" data-search-open ref="searchButton" @click="emit('search')" aria-label="البحث في الأطباق"><SiteIcon name="search" /></button>
        <button class="icon-button cart-toggle" type="button" @click="openCart" :aria-label="`فتح السلة، ${cartStore.itemsCount} منتجات`"><SiteIcon name="shopping-bag" /><span class="cart-count" id="cart-count">{{ cartStore.itemsCount }}</span></button>
        <RouterLink class="icon-button desktop-action" :to="auth.isAuthenticated ? '/account/profile' : '/login'" :aria-label="auth.isAuthenticated ? 'حساب '+auth.user.name : 'حسابي'"><SiteIcon name="user-round" /></RouterLink>
        <RouterLink v-if="!auth.isAuthenticated" class="login-button" to="/login" :aria-current="route.name === 'login' ? 'page' : undefined">تسجيل الدخول</RouterLink><button v-else class="login-button account-logout" type="button" :disabled="auth.isLoading" :title="`${auth.user.name} · تسجيل الخروج`" @click="logout">{{ auth.user.name }} · خروج</button>
        <button class="icon-button menu-toggle" id="menu-toggle" ref="menuButton" @click="menuOpen = !menuOpen" type="button" :aria-label="menuOpen ? 'إغلاق القائمة' : 'فتح القائمة'" :aria-expanded="menuOpen" aria-controls="main-nav"><SiteIcon name="menu" /></button>
      </div>
    </div>
  </header>
</template>
<style scoped>.account-logout{max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}</style>


