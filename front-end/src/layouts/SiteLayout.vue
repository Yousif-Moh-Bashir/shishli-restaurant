<script setup>
import { ref, provide, watch, onMounted, onBeforeUnmount } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import SiteHeader from '../components/site/SiteHeader.vue'
import RegistrationOffer from '../components/site/RegistrationOffer.vue'
import RegistrationOfferPopup from '../components/site/RegistrationOfferPopup.vue'
import { useRegistrationOfferStore } from '../stores/registrationOffer'
import { useAuthStore } from '../stores/auth'
import { useBranchStore } from '../stores/branch'
import SiteFooter from '../components/site/SiteFooter.vue'
import SiteIcon from '../components/site/SiteIcon.vue'
import CartItems from '../components/site/CartItems.vue'
import CartSummary from '../components/site/CartSummary.vue'
import { useCartStore } from '../stores/cart'
import { useRestaurantInfo } from '../composables/useRestaurantInfo'
import { useCartClickSound } from '../composables/useCartClickSound'
const cartSound = useCartClickSound()
const restaurant=useRestaurantInfo()

const route = useRoute()
const campaign = useRegistrationOfferStore(), auth = useAuthStore(), branch = useBranchStore()
watch([() => branch.selectedUuid, () => auth.token, () => route.fullPath], () => {
  if (!auth.token) void campaign.load(branch.selectedUuid)
}, { immediate: true })
let campaignTimer
function refreshCampaign() { if (!document.hidden && !auth.token) void campaign.load(branch.selectedUuid) }
onMounted(() => { campaignTimer = setInterval(refreshCampaign, 60000); document.addEventListener('visibilitychange', refreshCampaign) })
onBeforeUnmount(() => { clearInterval(campaignTimer); document.removeEventListener('visibilitychange', refreshCampaign); campaign.cancel() })
const cartStore = useCartStore()
if (!['order-success', 'customer-orders', 'account.orders', 'account.order', 'track-order', 'track-order.detail'].includes(route.name)) void cartStore.initializeCart()
const searchRequest = ref(0)
const cartDialog = ref(null), cartCloseButton = ref(null)
const searchOpen = ref(false), toastVisible = ref(false), toastMessage = ref(''), header = ref(null)
const toastKind = ref('info'), toastQuick = ref(false)
let toastDuration = 3200
let toastTimer

function updatePage() {
  document.body.classList.toggle('menu-page', route.name === 'menu')
  document.body.classList.toggle('product-page', route.name === 'product')
  document.body.classList.toggle('checkout-page', route.name === 'checkout')
  document.body.classList.toggle('login-page', ['login', 'register'].includes(route.name))
  searchOpen.value = false
  header.value?.closeMenu()
  cartDialog.value?.close()
}

function toast(message, { kind = 'info', quick = false } = {}) {
  toastMessage.value = message
  toastKind.value = kind
  toastQuick.value = quick
  toastDuration = quick ? 1400 : 3200
  toastVisible.value = true
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastVisible.value = false }, toastDuration)
}

function notifyCartResult(result) {
  if (!result.ok) toast(result.message, { kind: 'error' })
  else {
    cartSound.play()
    toast(result.warning || 'تمت الإضافة للسلة', { kind: 'success', quick: !result.warning })
  }
}
function pauseToast() { if(!toastQuick.value)clearTimeout(toastTimer) }
function resumeToast() { if(toastQuick.value)return;clearTimeout(toastTimer); if(toastVisible.value)toastTimer=setTimeout(()=>{toastVisible.value=false},toastDuration) }
function dismissToast() { clearTimeout(toastTimer); toastVisible.value=false }

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
provide('site', { searchOpen, searchRequest, closeSearch, openCart, toast, notifyCartResult, prepareCartSound: cartSound.prepare })
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
<RegistrationOffer v-show="route.name!=='register'" placement="bar"/>
<RouterView :key="route.meta.cmsKey" />
<SiteFooter/>
<RegistrationOfferPopup/>
  <dialog class="cart-dialog" id="cart-dialog" ref="cartDialog" @close="dialogClosed" @click="backdropClose" aria-labelledby="cart-title"><div class="dialog-heading"><div><span class="section-kicker">طعمك المفضل، في مكان واحد</span><h2 id="cart-title">سلة طلباتك</h2></div><button class="icon-button" type="button" ref="cartCloseButton" @click="closeDialog" aria-label="إغلاق السلة"><SiteIcon name="x" /></button></div><p class="demo-note">سلتك محفوظة لدى المطعم. تُراجع حالة الفرع والأسعار عند تأكيد الطلب.</p><CartItems @empty-focus="cartCloseButton?.focus()" /><CartSummary @continue="cartDialog.close()" /></dialog>
  <div class="toast cart-feedback-toast" id="toast" :class="[toastKind,{ visible: toastVisible, quick: toastQuick }]" :inert="!toastVisible" @mouseenter="pauseToast" @mouseleave="resumeToast" @focusin="pauseToast" @focusout="resumeToast"><SiteIcon :name="toastKind==='success'?'badge-check':toastKind==='error'?'x':'shopping-bag'"/><p role="status" :aria-live="toastKind==='error'?'assertive':'polite'" aria-atomic="true">{{ toastMessage }}</p><button v-if="!toastQuick" class="toast-close" type="button" aria-label="إغلاق الإشعار" @click="dismissToast"><SiteIcon name="x"/></button></div>
</div>
</template>

