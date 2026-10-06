<script setup>
import { ref, provide, watch, onMounted, onBeforeUnmount } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import SiteHeader from '../components/site/SiteHeader.vue'
import SiteFooter from '../components/site/SiteFooter.vue'
import SiteIcon from '../components/site/SiteIcon.vue'
import CartItems from '../components/site/CartItems.vue'
import CartSummary from '../components/site/CartSummary.vue'
import { useCartStore } from '../stores/cart'
import { useRestaurantInfo } from '../composables/useRestaurantInfo'
const restaurant=useRestaurantInfo()

const route = useRoute()
const cartStore = useCartStore()
if (!['order-success', 'account.orders', 'account.order', 'track-order', 'track-order.detail'].includes(route.name)) void cartStore.initializeCart()
const searchRequest = ref(0)
const originalTitle = document.title
const descriptionMeta = document.getElementsByName('description')[0]
const originalDescription = descriptionMeta?.content
const cartDialog = ref(null), cartCloseButton = ref(null)
const searchOpen = ref(false), toastVisible = ref(false), toastMessage = ref(''), header = ref(null)
let toastTimer

function updatePage() {
  document.body.classList.toggle('menu-page', route.name === 'menu')
  document.body.classList.toggle('product-page', route.name === 'product')
  document.body.classList.toggle('checkout-page', route.name === 'checkout')
  document.body.classList.toggle('login-page', ['login', 'register'].includes(route.name))
  const product = null
  const titles = { menu: 'المنيو | اطلب من مطعم شيشلي', login: 'تسجيل الدخول | مطعم شيشلي', register: 'إنشاء حساب | مطعم شيشلي', cart: 'سلة طلباتك | مطعم شيشلي', checkout: 'إكمال الطلب | مطعم شيشلي', 'order-success': 'تفاصيل الطلب | مطعم شيشلي', 'track-order': 'تتبع الطلب | مطعم شيشلي' }
  document.title = route.name === 'product' ? (product ? `${product.name} | مطعم شيشلي` : 'تفاصيل المنتج | مطعم شيشلي') : route.meta.title || titles[route.name] || originalTitle
  if (descriptionMeta) descriptionMeta.content = product ? (product.description || product.name) : originalDescription
  searchOpen.value = false
  header.value?.closeMenu()
  cartDialog.value?.close()
}

function toast(message) {
  toastMessage.value = message
  toastVisible.value = true
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastVisible.value = false }, 3200)
}

function notifyCartResult(result, productName) {
  if (!result.ok) toast(result.message)
  else toast(result.warning || `أُضيف ${productName} إلى سلتك`)
}

function openDialog(dialog) {
  header.value?.closeMenu()
  dialog.showModal()
  document.body.classList.add('dialog-open')
}
const openCart = () => openDialog(cartDialog.value)
function requestSearch() { searchOpen.value = true; searchRequest.value++ }
function closeDialog(event) { event.currentTarget.closest('dialog').close() }
function dialogClosed() {
  if (!cartDialog.value?.open) document.body.classList.remove('dialog-open')
}
function backdropClose(event) {
  const dialog = event.currentTarget
  if (event.target !== dialog) return
  const rect = dialog.getBoundingClientRect()
  if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close()
}
function closeSearch() { searchOpen.value = false; header.value?.focusSearch() }

watch(() => route.fullPath, updatePage, { immediate: true, flush: 'post' })
provide('site', { searchOpen, searchRequest, closeSearch, openCart, toast, notifyCartResult })
onMounted(() => window.addEventListener('storage', cartStore.handleStorageEvent))
onBeforeUnmount(() => {
  clearTimeout(toastTimer)
  window.removeEventListener('storage', cartStore.handleStorageEvent)
  document.body.classList.remove('dialog-open', 'menu-page', 'product-page', 'login-page', 'checkout-page')
})
</script>
<template>
<div class="site-layout" :class="{'menu-page':route.name==='menu','product-page':route.name==='product','checkout-page':route.name==='checkout','login-page':['login','register'].includes(route.name)}">
<a class="skip-link" href="#main">انتقل إلى المحتوى</a>
<SiteHeader ref="header" @search="requestSearch" />
<RouterView :key="route.meta.cmsKey" />
<SiteFooter/>
  <dialog class="cart-dialog" id="cart-dialog" ref="cartDialog" @close="dialogClosed" @click="backdropClose" aria-labelledby="cart-title"><div class="dialog-heading"><div><span class="section-kicker">طعمك المفضل، في مكان واحد</span><h2 id="cart-title">سلة طلباتك</h2></div><button class="icon-button" type="button" ref="cartCloseButton" @click="closeDialog" aria-label="إغلاق السلة"><SiteIcon name="x" /></button></div><p class="demo-note">سلتك محفوظة لدى المطعم. تُراجع حالة الفرع والأسعار عند تأكيد الطلب.</p><CartItems @empty-focus="cartCloseButton?.focus()" /><CartSummary @continue="cartDialog.close()" /></dialog>
  <div class="toast" id="toast" :class="{ visible: toastVisible }" role="status" aria-live="polite">{{ toastMessage }}</div>
</div>
</template>

