<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useRegistrationOfferStore } from '../../stores/registrationOffer'
import SiteIcon from './SiteIcon.vue'

const auth = useAuthStore(), campaign = useRegistrationOfferStore(), route = useRoute()
const popup = ref(null), dismissed = new Map()
const delay = 8000, dismissalDuration = 7 * 24 * 60 * 60 * 1000
const showAfter = Date.now() + delay
const offer = computed(() => campaign.activeOffer)
const discount = computed(() => Number(offer.value?.discount.value).toLocaleString('ar-SA') + '٪')
const eligible = computed(() => auth.isInitialized && !auth.token && !!offer.value && !route.meta.guestOnly && !route.meta.requiresAuth && !['checkout', 'order-success', 'track-order', 'track-order.detail', 'customer-orders'].includes(route.name))
const destination = computed(() => ({ name: 'register', query: { redirect: route.name === 'cart' ? '/checkout' : route.fullPath } }))
let timer, previousFocus, previousOverflow, scrollLocked = false
const storageKey = id => 'shishli.registrationPopup.' + id
function seen(id) {
  let until = dismissed.get(id) || 0
  try {
    const stored = localStorage.getItem(storageKey(id))
    if (stored === 'seen') { remember(id); return true }
    until = Math.max(until, Number(stored) || 0)
  } catch { /* Use the in-memory dismissal if storage is unavailable. */ }
  return until > Date.now()
}
function remember(id) {
  const until = Date.now() + dismissalDuration
  dismissed.set(id, until)
  try { localStorage.setItem(storageKey(id), String(until)) } catch { /* Keep the dismissal while the site is open. */ }
}
function restore() {
  if (!scrollLocked) return
  document.body.style.overflow = previousOverflow
  scrollLocked = false
  if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true })
}
function close() { popup.value?.close(); restore() }
function dismiss() { if (offer.value) remember(offer.value.id); close() }
function handleClose() { if (scrollLocked && offer.value) remember(offer.value.id); restore() }
function schedule() {
  clearTimeout(timer)
  close()
  if (!eligible.value || seen(offer.value.id)) { close(); return }
  timer = setTimeout(async () => {
    if (!eligible.value || seen(offer.value.id)) return
    if (document.hidden || document.querySelector('dialog[open]')) { timer = setTimeout(schedule, 1000); return }
    await nextTick()
    if (!eligible.value || !popup.value || seen(offer.value.id)) return
    previousFocus = document.activeElement
    previousOverflow = document.body.style.overflow
    popup.value.showModal()
    document.body.style.overflow = 'hidden'
    scrollLocked = true
  }, Math.max(0, showAfter - Date.now()))
}
watch([eligible, () => offer.value?.id, () => route.fullPath], schedule, { immediate: true })
onBeforeUnmount(() => { clearTimeout(timer); close() })
</script>

<template>
  <dialog ref="popup" class="welcome-popup" aria-labelledby="welcome-offer-title" aria-describedby="welcome-offer-description" @cancel.prevent="dismiss" @close="handleClose" @click="event=>{if(event.target===popup)dismiss()}">
    <div v-if="offer" class="welcome-popup-card">
      <button type="button" class="welcome-popup-close" aria-label="إغلاق عرض التسجيل" @click="dismiss"><SiteIcon name="x"/></button>
      <div class="welcome-popup-gift" aria-hidden="true">🎁</div>
      <span class="welcome-popup-kicker">هدية ترحيبية من شيشلي فرش</span>
      <h2 id="welcome-offer-title">🎉 سجّل الآن واستمتع<br>بخصم <span>{{ discount }}</span>!</h2>
      <p id="welcome-offer-description">انضم إلى عائلة <strong>شيشلي فرش</strong> واستمتع بخصم <strong>{{ discount }} على طلبك الأول</strong> عند إنشاء حساب جديد.</p>
      <p class="welcome-popup-invitation">🍽️ سجّل الآن، واطلب أشهى وجباتك بسعر أقل!</p>
      <RouterLink class="welcome-popup-action" :to="destination" @click="dismiss">سجّل الآن واحصل على خصمك 🎁</RouterLink>
      <button type="button" class="welcome-popup-later" @click="dismiss">لاحقًا، أكمل التصفح</button>
    </div>
  </dialog>
</template>

<style scoped>
dialog.welcome-popup{width:min(420px,calc(100% - 28px));max-height:90dvh;padding:0;border:1px solid #d8b77655;border-radius:22px;background:#fffcf7;color:var(--primary,#5a1725);box-shadow:0 24px 100px #24101345;overflow:auto}dialog.welcome-popup::backdrop{background:#2410139c;backdrop-filter:blur(4px)}.welcome-popup-card{position:relative;padding:32px 28px 22px;text-align:center;background:radial-gradient(ellipse at 50% 0,#edd19b44,transparent 65%)}.welcome-popup-close{position:absolute;inset-block-start:12px;inset-inline-end:12px;display:grid;place-items:center;width:34px;height:34px;border:0;border-radius:50%;background:#5a172508;color:inherit;cursor:pointer}.welcome-popup-close .icon{width:17px;height:17px}.welcome-popup-gift{display:grid;place-items:center;width:68px;height:68px;margin:0 auto 14px;border:1px solid #d2a95633;border-radius:20px;background:#fff8e8;font-size:34px}.welcome-popup-kicker{font-size:11px;font-weight:700;color:#95651c}.welcome-popup-card h2{margin:10px 0 14px;font-size:26px;line-height:1.5;font-weight:800}.welcome-popup-card h2 span{color:#a36c16}.welcome-popup-card p{font-size:14px;line-height:1.9;color:#79625c;margin:0}.welcome-popup-card p strong{color:var(--primary,#5a1725)}.welcome-popup-card .welcome-popup-invitation{font-size:12px;margin-top:10px}.welcome-popup-action{display:flex;align-items:center;justify-content:center;min-height:46px;margin-top:22px;padding:10px 12px;border-radius:11px;background:var(--primary,#5a1725);color:#fff;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 6px 16px #5a17251a}.welcome-popup-action:hover{background:#43111c}.welcome-popup-later{min-height:38px;margin-top:6px;border:0;background:transparent;color:#927971;font:inherit;font-size:11px;cursor:pointer}.welcome-popup-action:focus-visible,.welcome-popup-close:focus-visible,.welcome-popup-later:focus-visible{outline:2px solid #c9963b;outline-offset:3px}
@media(max-width:380px){.welcome-popup-card{padding:28px 20px 18px}.welcome-popup-card h2{font-size:23px}.welcome-popup-card p{font-size:13px}.welcome-popup-action{font-size:12px}}
</style>
