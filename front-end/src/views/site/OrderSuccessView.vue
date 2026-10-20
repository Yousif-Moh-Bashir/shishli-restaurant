<script setup>
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import { RouterLink } from 'vue-router'
import { useOrderDetails } from '../../composables/useOrderDetails'
import { useAuthStore } from '../../stores/auth'
import OrderSnapshot from '../../components/site/OrderSnapshot.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
import { readOrderAccess, orderAccessIsPersistent } from '../../utils/orderAccess'
import CustomerPayments from '../../components/payments/CustomerPayments.vue'
const auth = useAuthStore()
const { data: order, loading: isLoading, error, load } = useOrderDetails()
function paymentUpdated(value){if(order.value?.id===value.id)order.value={...order.value,payment:value.payment}}
</script>
<template>
  <main id="main" class="container checkout-main order-success-page">
    <RouterLink class="auth-back" to="/menu">→ العودة للمنيو</RouterLink>
    <div class="section-heading"><div><span class="section-kicker">طلبك من شيشلي فرش</span><h1>تأكيد استلام الطلب</h1></div></div>
    <AppSkeleton v-if="isLoading" variant="detail" label="جارٍ تحميل الطلب…"/>
    <section v-else-if="error" class="checkout-empty" role="alert"><h2>تعذر عرض الطلب</h2><p>{{ error.message }}</p><p v-if="[401,403,404].includes(error.status)">تعذر الوصول إلى هذا الطلب. يمكنك العودة إليه من صفحة «طلباتي».</p><button class="button button-primary" @click="load">إعادة المحاولة</button><RouterLink v-if="auth.isAuthenticated" to="/account/orders">طلباتي</RouterLink></section>
    <template v-else-if="order"><section class="checkout-confirmation"><span class="success-check"><SiteIcon name="badge-check"/></span><h2>تم استلام طلبك</h2><p class="success-description">شكرًا لطلبك من شيشلي فرش. تابع حالة الطلب ومراحل تجهيزه من صفحة التتبع.</p><p>رقم طلبك لدى المطعم</p><strong class="success-order-number" dir="ltr">{{ order.order_number }}</strong><div class="success-actions"><RouterLink class="button button-primary" :to="'/track-order/'+order.id"><SiteIcon name="map-pin"/> تتبع الطلب</RouterLink><RouterLink class="button success-menu-link" to="/menu">العودة إلى المنيو</RouterLink></div></section><p v-if="readOrderAccess(order.id) && !orderAccessIsPersistent(order.id)" role="alert">تعذر حفظ رمز الوصول. احتفظ بهذه الصفحة؛ تحديثها قد يمنع استعادة الطلب.</p><OrderSnapshot :order="order"/><CustomerPayments v-if="auth.isAuthenticated &amp;&amp; !readOrderAccess(order.id)" :order-id="order.id" @order-updated="paymentUpdated"/><RouterLink class="button button-primary" to="/orders">طلباتي</RouterLink></template>
  </main>
</template>
<style scoped>
.order-success-page{padding-bottom:40px}
.order-success-page .checkout-confirmation{display:flex;flex-direction:column;align-items:center;padding:32px 24px;border:1px solid var(--border);background:#fffdfa;border-radius:14px;margin-bottom:26px}
.success-check{display:grid;place-items:center;width:64px;height:64px;border-radius:50%;background:#edf5ed;color:#36784c;margin-bottom:16px}.success-check .icon{width:34px;height:34px}
.order-success-page .checkout-confirmation h2{font-size:26px;margin:0;color:var(--primary-dark)}
.order-success-page .checkout-confirmation p{font-size:13px;line-height:1.8;margin:12px 0 6px;max-width:480px}
.success-order-number{font-size:27px;letter-spacing:2px;color:var(--primary-dark);padding:12px 24px;border:1px dashed var(--border);border-radius:8px;background:#f8f2ed;margin-block:8px 20px;overflow-wrap:anywhere;max-width:100%;box-sizing:border-box}
.success-actions{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;width:100%}.success-actions .button{min-height:44px;gap:8px}.success-actions .button-primary{background:#d71920;border-color:#d71920;color:#fff}.success-actions .success-menu-link{background:transparent;color:var(--primary);border:1px solid var(--border)}
.order-success-page :deep(.order-details-layout){display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.85fr);gap:24px;align-items:start;margin:0 0 24px}
.order-success-page :deep(.checkout-panel){padding:24px;border:1px solid var(--border);border-radius:12px;background:#fffdfa;min-width:0}
.order-success-page :deep(.checkout-panel h2){font-size:20px;margin:0 0 20px;color:var(--primary-dark)}
.order-success-page :deep(.order-number){font-size:14px;color:var(--muted);margin-bottom:14px}
.order-success-page :deep(.order-facts){display:grid;grid-template-columns:120px minmax(0,1fr);gap:14px 18px;font-size:13px;line-height:1.8;margin:0}.order-success-page :deep(.order-facts dt){color:var(--muted)}.order-success-page :deep(.order-facts dd){margin:0;min-width:0}.order-success-page :deep(.order-facts dd[dir=ltr]){text-align:right}
.order-success-page :deep(.order-snapshot-item){padding:0 0 16px;margin-bottom:16px;border-bottom:1px solid var(--border)}.order-success-page :deep(.order-snapshot-item p){font-size:12px;color:var(--muted);line-height:1.8;margin:6px 0;overflow-wrap:anywhere}
.order-success-page :deep(.checkout-item){display:flex;justify-content:space-between;gap:12px;padding-block:12px;font-size:13px;border-bottom:1px solid var(--border)}.order-success-page :deep(.checkout-item span){min-width:0;overflow-wrap:anywhere}.order-success-page :deep(.checkout-item strong){white-space:nowrap;flex-shrink:0}
.order-success-page :deep(.checkout-total){display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:18px;padding:16px;background:#f8f2ed;border:1px solid var(--border);border-radius:9px;font-size:19px;font-weight:700;color:var(--primary-dark)}
.order-success-page :deep(.order-details-layout>.checkout-panel:first-child h2){padding-bottom:16px;margin-bottom:16px;border-bottom:1px solid var(--border)}
.order-success-page :deep(.order-number){display:inline-block;padding:8px 12px;margin-bottom:20px;background:#f8f2ed;border:1px solid var(--border);border-radius:8px;color:var(--primary-dark);font-weight:700;letter-spacing:1px}
.order-success-page :deep(.order-facts){gap:0;grid-template-columns:130px minmax(0,1fr)}
.order-success-page :deep(.order-facts dt),.order-success-page :deep(.order-facts dd){padding:12px 0;border-bottom:1px solid var(--border);align-content:center}
.order-success-page :deep(.order-facts dd){padding-inline-start:16px;color:var(--primary-dark);font-weight:500}
.order-success-page :deep(.order-facts dd[dir=ltr]){unicode-bidi:plaintext}
.order-success-page :deep(.order-details-layout>.checkout-panel:first-child>p){font-size:12px;line-height:1.9;color:var(--muted);margin:16px 0 0;padding:12px 14px;background:#f8f2ed;border-radius:8px}
.order-success-page :deep(.delivery-quote){margin-top:22px;padding:18px;background:#f8f2ed;border:1px solid var(--border);border-radius:10px}
.order-success-page :deep(.delivery-quote h3),.order-success-page :deep(.order-details-layout>.checkout-panel:first-child>section h3){font-size:15px;color:var(--primary-dark);margin-bottom:10px}
.order-success-page :deep(.delivery-quote p){font-size:13px;line-height:1.9;margin:5px 0;overflow-wrap:anywhere}
.order-success-page :deep(.order-details-layout>.checkout-panel:first-child>section:not(.delivery-quote)){margin-top:22px;padding:16px;border:1px solid var(--border);border-radius:10px}
.order-success-page :deep(.order-text){font-size:13px;line-height:1.9;margin:0}
@media(max-width:950px){.order-success-page :deep(.order-details-layout){grid-template-columns:minmax(0,1fr)}.order-success-page :deep(.checkout-summary){order:0}}
@media(max-width:600px){.order-success-page .checkout-confirmation{padding:24px 18px}.order-success-page .checkout-confirmation h2{font-size:23px}.success-actions .button{width:100%;justify-content:center}.order-success-page :deep(.checkout-panel){padding:18px}.order-success-page :deep(.order-facts){grid-template-columns:100px minmax(0,1fr);gap:0}.order-success-page :deep(.order-facts dd){padding-inline-start:10px}.order-success-page :deep(.order-details-layout){gap:16px}}
</style>

