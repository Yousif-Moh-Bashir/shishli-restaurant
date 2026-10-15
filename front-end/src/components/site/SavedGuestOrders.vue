<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { RouterLink } from 'vue-router'
import { readSavedOrders, readOrderAccess } from '../../utils/orderAccess'
import { ordersApi } from '../../api/orders.api'
import OrderStatusBadge from '../orders/OrderStatusBadge.vue'
import { formatDateTime } from '../../utils/formatters'
import SiteIcon from './SiteIcon.vue'
defineProps({ signedIn: Boolean, enhanced: Boolean })
const orders = ref(readSavedOrders())
let controller
async function refresh() {
  controller?.abort()
  const current = new AbortController()
  controller = current
  orders.value = readSavedOrders()
  await Promise.allSettled(orders.value.map(async saved => {
    try {
      const order = await ordersApi.guest(saved.id, readOrderAccess(saved.id), current.signal)
      if (!current.signal.aborted) saved.status = order.status
    } catch {
      if (!current.signal.aborted) saved.statusError = true
    }
  }))
}
onMounted(() => { void refresh(); window.addEventListener('storage', refresh); window.addEventListener('shishli:orders-saved', refresh) })
onBeforeUnmount(() => { controller?.abort(); window.removeEventListener('storage', refresh); window.removeEventListener('shishli:orders-saved', refresh) })
</script>
<template>
  <section v-if="orders.length || !signedIn" class="saved-orders" :class="{'saved-orders--enhanced':enhanced}" :aria-labelledby="enhanced?undefined:'saved-orders-title'" :aria-label="enhanced?'الطلبات':undefined">
    <div v-if="!enhanced" class="section-heading"><div><h2 id="saved-orders-title">{{ signedIn ? 'طلباتك السابقة كضيف' : 'طلباتك' }}</h2><p>ارجع إلى حالة طلبك وتفاصيله من هنا.</p></div></div>
    <div v-if="orders.length" class="saved-orders-grid"><article v-for="order in orders" :key="order.id" class="checkout-panel"><div class="saved-order-heading"><div><span v-if="enhanced" class="saved-order-caption">رقم الطلب</span><h3 dir="ltr">{{ order.number || 'طلب محفوظ' }}</h3></div><span v-if="enhanced" class="saved-order-icon"><SiteIcon name="shopping-bag"/></span></div><p v-if="order.placedAt" class="saved-order-date"><SiteIcon v-if="enhanced" name="clock"/> {{ formatDateTime(order.placedAt) }}</p><div class="saved-order-status"><span>حالة الطلب</span><OrderStatusBadge v-if="order.status" :status="order.status"/><span v-else>{{ order.statusError ? 'تعذر تحديث الحالة' : 'جارٍ تحديث الحالة…' }}</span></div><div class="saved-order-actions"><RouterLink class="button button-primary" :to="{name:'track-order.detail',params:{orderUuid:order.id}}">تتبع الطلب <SiteIcon v-if="enhanced" name="arrow-left"/></RouterLink><RouterLink class="text-button" :to="{name:'order-success',params:{orderUuid:order.id}}">تفاصيل الطلب</RouterLink></div></article></div>
    <div v-else class="checkout-panel saved-orders-empty"><span v-if="enhanced" class="saved-order-empty-icon" aria-hidden="true"><SiteIcon name="shopping-bag"/></span><h3 v-if="enhanced">لا توجد طلبات بعد</h3><p v-else>لا توجد طلبات بعد.</p><div class="saved-order-empty-actions"><RouterLink v-if="enhanced" class="button button-primary" to="/menu">تصفح المنيو <SiteIcon name="arrow-left"/></RouterLink><RouterLink :class="enhanced?'saved-order-login':'button button-primary'" to="/login?redirect=/orders">تسجيل الدخول</RouterLink></div></div>
  </section>
</template>
<style scoped>
.saved-order-status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;background:var(--cream);border:1px solid var(--border);border-radius:9px;margin-top:14px;font-size:13px}.saved-order-status :deep(.order-status-badge){font-size:13px;font-weight:700;padding:7px 12px;border-radius:8px}
.saved-orders{margin-block:24px}.saved-orders-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.saved-orders .checkout-panel{padding:20px;border:1px solid var(--border);border-radius:12px;min-width:0}.saved-orders h3{font-size:17px;overflow-wrap:anywhere}.saved-orders .checkout-panel p{margin-block:10px;color:var(--muted);font-size:13px}.saved-order-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px}@media(max-width:700px){.saved-orders-grid{grid-template-columns:1fr}}
.saved-orders--enhanced{margin-block:28px}.saved-orders--enhanced .section-heading{margin-bottom:18px}.saved-orders--enhanced .section-heading h2{display:flex;align-items:center;gap:8px;font-size:18px}.saved-orders--enhanced .section-heading p{font-size:12px;color:var(--muted);margin-top:6px}.saved-order-count{display:grid;place-items:center;min-width:26px;height:26px;padding-inline:6px;background:#5a172508;border-radius:7px;font-size:12px;color:var(--primary)}.saved-orders--enhanced .saved-orders-grid{gap:18px}.saved-orders--enhanced .checkout-panel{margin:0;padding:22px;background:#fff;border-radius:16px}.saved-orders--enhanced .saved-order-heading{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.saved-orders--enhanced .saved-order-heading>div{min-width:0}.saved-orders--enhanced h3{font-size:20px;line-height:1.5}.saved-order-caption{font-size:11px;color:var(--muted)}.saved-order-icon{display:grid;place-items:center;width:42px;height:42px;flex-shrink:0;background:#5a172508;color:var(--primary);border-radius:12px}.saved-orders--enhanced .saved-order-date{display:flex;align-items:center;gap:7px;font-size:11px;flex-wrap:wrap}.saved-order-date .icon{width:16px;height:16px}.saved-orders--enhanced .saved-order-actions{padding-top:16px;border-top:1px solid var(--border);gap:14px}.saved-orders--enhanced .saved-order-actions .button{display:flex;align-items:center;justify-content:center;gap:8px;flex:1;min-height:44px;border-radius:9px;font-size:13px}.saved-orders--enhanced .saved-order-actions .button .icon{width:17px;height:17px}.saved-orders--enhanced .saved-order-actions .text-button{display:inline-flex;align-items:center;min-height:44px;font-size:12px}.saved-orders--enhanced .saved-orders-empty{text-align:center;padding:36px 24px;border-style:dashed}.saved-order-empty-icon{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 16px;border-radius:18px;background:#c9963b12;color:var(--gold)}.saved-order-empty-icon .icon{width:30px;height:30px}.saved-orders--enhanced .saved-orders-empty h3{font-size:19px}.saved-orders--enhanced .saved-orders-empty p{max-width:460px;margin:10px auto;font-size:13px;line-height:1.9}.saved-orders--enhanced .saved-order-empty-actions{display:flex;justify-content:center;align-items:center;gap:16px;flex-wrap:wrap;margin-top:20px}.saved-orders--enhanced .saved-order-empty-actions .button{min-height:44px}.saved-orders--enhanced .catalog-note{font-size:11px;line-height:1.9;color:var(--muted);margin-top:16px}
@media(max-width:600px){.saved-orders--enhanced .checkout-panel{padding:20px}.saved-orders--enhanced .saved-order-actions{gap:12px}.saved-orders--enhanced .saved-orders-empty h3{font-size:17px}}
.saved-orders--enhanced .saved-orders-empty{padding:40px 24px;border:1px solid #5a172512;border-radius:20px;background:radial-gradient(ellipse at 50% 0,#c9963b0d,transparent 65%),#fffdf9;box-shadow:0 8px 28px #35101704}.saved-orders--enhanced .saved-order-empty-icon{width:72px;height:72px;margin-bottom:18px;border:1px solid #c9963b25;border-radius:50%;background:#fff6e7;color:var(--primary);box-shadow:0 0 0 8px #c9963b06}.saved-orders--enhanced .saved-orders-empty h3{color:var(--primary);font-weight:700;line-height:1.6}.saved-orders--enhanced .saved-order-empty-actions{gap:12px;margin-top:24px}.saved-order-empty-actions .button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:10px;padding:10px 22px;font-size:13px}.saved-order-empty-actions .button .icon{width:16px;height:16px}.saved-order-login{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 20px;border:1px solid var(--border);border-radius:10px;background:#fff;color:var(--primary);font-size:13px;font-weight:700;text-decoration:none}.saved-order-login:hover{background:var(--cream)}.saved-order-login:focus-visible{outline:2px solid var(--gold);outline-offset:3px}
@media(max-width:600px){.saved-orders--enhanced .saved-orders-empty{padding:32px 18px}.saved-orders--enhanced .saved-order-empty-actions{gap:10px}.saved-order-empty-actions .button,.saved-order-login{padding-inline:18px}}
</style>
