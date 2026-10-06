<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import { ref,watch,computed } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { ordersApi } from '../../api/orders.api'
import { readOrderAccess,orderAccessIsPersistent } from '../../utils/orderAccess'
import { isTerminalOrder,getOrderTypeLabel,getPaymentMethodLabel,getPaymentStatusLabel,getTrackingErrorMessage } from '../../constants/orderStatus'
import { formatDateTime,formatCurrency } from '../../utils/formatters'
import OrderTimeline from './OrderTimeline.vue'
import SiteIcon from '../site/SiteIcon.vue'
import OrderStatusBadge from './OrderStatusBadge.vue'
import CustomerOrderCancel from './CustomerOrderCancel.vue'
const props=defineProps({orderUuid:{type:String,required:true},customerOnly:Boolean}),auth=useAuthStore(),active=ref(false)
const emit=defineEmits(['updated'])
let guestNumber='',credential=''
const state=useOrderPolling(async signal=>{
 const id=props.orderUuid
 if(!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id))throw {response:{status:404}}
 const token=props.customerOnly?null:readOrderAccess(id)
 credential=token||''
 let value
 if(token){if(!guestNumber){const original=await ordersApi.guest(id,token,signal);if(signal.aborted)throw {code:'ERR_CANCELED'};guestNumber=original.order_number}value=await ordersApi.trackGuest({order_number:guestNumber,token},signal)}
 else {
 if(!auth.isAuthenticated)throw {response:{status:404}}
 value=await ordersApi.tracking(id,signal)
 }
 if(value?.order?.uuid!==id||!Array.isArray(value.timeline)||!Array.isArray(value.items)||!value.branch||!value.summary)throw Error('INVALID_TRACKING_RESPONSE')
 return value
},{interval:20000,enabled:()=>!isTerminalOrder(state.data.value?.order?.status?.code),paused:()=>active.value})
const {data,loading,refreshing,error,syncedAt}=state
const code=computed(()=>data.value?.order?.status?.code)
const accessWarning=computed(()=>data.value&&credential&&!orderAccessIsPersistent(props.orderUuid))
watch(data,value=>{if(value)emit('updated',value)})
const reload=()=>state.load(true,true)
watch([()=>props.orderUuid,()=>auth.token],()=>{active.value=false;guestNumber='';credential='';state.reset();void state.load()},{immediate:true})
</script>
<template>
 <section class="checkout-panel tracking-panel">
  <div class="dialog-heading tracking-heading"><div><span class="tracking-eyebrow">متابعة طلبك</span><h2>حالة الطلب الحالية</h2></div><button class="button tracking-refresh" type="button" :disabled="refreshing||active" @click="reload"><SiteIcon name="refresh"/> تحديث الحالة</button></div>
  <AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="detail" label="جارٍ تحميل التتبع…"/>
  <div v-if="error" class="tracking-alert" role="alert"><p>{{ [401,403,404,422].includes(error.status)?getTrackingErrorMessage(error.status):error.message }}</p><p v-if="data">البيانات من آخر مزامنة ناجحة؛ تعذر التأكد من الحالة الحالية.</p><p v-if="error.status===429">تم إيقاف التحديثات السريعة مؤقتًا. أعد المحاولة لاحقًا.</p></div>
  <p v-if="accessWarning" class="tracking-alert" role="status">تعذر حفظ رمز الوصول في هذه الجلسة؛ احتفظ به قبل إعادة تحميل الصفحة.</p>
  <template v-if="data">
   <div class="tracking-status-card" aria-live="polite"><span class="tracking-status-icon"><SiteIcon :name="code==='out_for_delivery'?'truck':code==='cancelled'?'x':'shopping-bag'"/></span><div class="tracking-status-content"><div class="tracking-identity-row"><div class="tracking-number-group"><small>رقم طلبك</small><strong class="tracking-number" dir="ltr">{{ data.order.number }}</strong></div><div class="tracking-current-status"><small>الحالة الحالية</small><OrderStatusBadge :status="code"/></div></div><p v-if="code==='ready'">{{ data.order.type==='pickup'?'طلبك جاهز للاستلام من الفرع.':'الطلب جاهز لدى المطعم.' }}</p><p v-if="code==='out_for_delivery'">خرج طلبك للتوصيل.</p><p v-if="code==='cancelled'">الطلب ملغي؛ لم تكتمل مراحل الاستلام.</p></div></div>
   <div class="tracking-columns"><div class="tracking-main-column">
    <section class="tracking-section"><h3><SiteIcon name="badge-check"/> مراحل الطلب</h3><OrderTimeline :timeline="data.timeline"/><div v-if="data.progress?.percentage!=null" class="tracking-progress"><div><span>تقدم الطلب</span><strong>{{ data.progress.percentage }}%</strong></div><progress :value="data.progress.percentage" max="100" aria-label="تقدم الطلب حسب المطعم"/></div></section>
    <section v-if="data.estimates?.ready_at||data.estimates?.delivery_from||data.estimates?.delivery_to" class="tracking-section tracking-estimates"><h3><SiteIcon name="clock"/> الأوقات التقديرية</h3><p v-if="data.estimates.ready_at">جاهز تقريبًا: {{ formatDateTime(data.estimates.ready_at) }}</p><p v-if="data.order.type==='delivery'&&(data.estimates.delivery_from||data.estimates.delivery_to)">التوصيل المتوقع: {{ data.estimates.delivery_from?formatDateTime(data.estimates.delivery_from):'غير محدد' }} — {{ data.estimates.delivery_to?formatDateTime(data.estimates.delivery_to):'غير محدد' }}</p><small>تقديرات المطعم، وقد تتغير أثناء التجهيز.</small></section>
    <section class="tracking-section"><h3><SiteIcon name="map-pin"/> تفاصيل الاستلام</h3><dl class="order-facts"><dt>الاستلام</dt><dd>{{ getOrderTypeLabel(data.order.type) }}</dd><dt>وقت الطلب</dt><dd>{{ formatDateTime(data.order.placed_at) }}</dd><template v-if="data.order.scheduled_for"><dt>الموعد المجدول</dt><dd>{{ formatDateTime(data.order.scheduled_for) }}</dd></template><dt>الفرع</dt><dd>{{ data.branch.name }}</dd><template v-if="data.branch.address"><dt>عنوان الفرع</dt><dd>{{ data.branch.address }}</dd></template><template v-if="data.branch.phone"><dt>هاتف الفرع</dt><dd dir="ltr">{{ data.branch.phone }}</dd></template><dt>الدفع</dt><dd>{{ getPaymentMethodLabel(data.payment.method) }} · {{ getPaymentStatusLabel(data.payment.status) }}</dd></dl><div v-if="data.destination" class="tracking-destination"><h4>وجهة التوصيل وقت الطلب</h4><p>{{ [data.destination.district,data.destination.street].filter(Boolean).join('، ') }}</p></div></section>
   </div><aside class="tracking-section tracking-summary"><h3><SiteIcon name="shopping-bag"/> ملخص الطلب</h3><article v-for="item in data.items" :key="item.uuid" class="tracking-item"><span class="tracking-quantity">{{ item.quantity }}×</span><div><strong>{{ item.name }}</strong><p v-for="(option,index) in item.options" :key="index">{{ option.group }}: {{ option.name }}</p></div><strong class="tracking-item-price">{{ formatCurrency(item.line_total_after_discount) }}</strong></article><div class="tracking-total"><span>الإجمالي</span><strong>{{ formatCurrency(data.summary.total) }}</strong></div><CustomerOrderCancel :id="data.order.uuid" :status="code" :token="credential" :reload="reload" @active="active=$event"/></aside></div>
   <footer class="tracking-sync"><p v-if="syncedAt">آخر مزامنة: {{ formatDateTime(syncedAt) }}. {{ isTerminalOrder(code)?'توقف التحديث الدوري لانتهاء الطلب.':'تتحدث الحالة تلقائيًا كل 20 ثانية.' }}</p><p v-if="data.last_updated_at">آخر تحديث لدى المطعم: {{ formatDateTime(data.last_updated_at) }}</p></footer>
  </template>
 </section>
</template>
<style scoped>
.tracking-panel{margin-block:20px;min-width:0}.tracking-panel strong,.tracking-panel dd{overflow-wrap:anywhere}.tracking-panel .dialog-heading{flex-wrap:wrap;gap:16px;align-items:center;margin-bottom:22px}.tracking-heading h2{margin:4px 0;font-size:21px}.tracking-eyebrow{font-size:11px;color:var(--muted)}
.tracking-panel .button{background:#d71920;border-color:#d71920;color:white;gap:8px;min-height:42px}.tracking-refresh{font-size:12px}.tracking-refresh .icon{width:17px;height:17px}
.tracking-status-card{display:flex;align-items:center;gap:16px;padding:22px;border:1px solid var(--border);border-radius:12px;background:#f8f2ed;margin-bottom:24px}.tracking-status-icon{display:grid;place-items:center;width:52px;height:52px;background:#fffdfa;border-radius:12px;flex-shrink:0;color:var(--primary)}.tracking-status-icon .icon{width:28px;height:28px}.tracking-status-card small{display:block;color:var(--muted);font-size:11px}.tracking-number{display:block;font-size:24px;letter-spacing:1px;margin-block:4px 8px}.tracking-status-card p{font-size:13px;line-height:1.8;margin-top:10px}
.tracking-columns{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(280px,.8fr);gap:22px;align-items:start}.tracking-main-column{display:grid;gap:20px;min-width:0}.tracking-section{padding:20px;border:1px solid var(--border);border-radius:12px;background:#fffdfa;min-width:0}.tracking-section h3{display:flex;align-items:center;gap:8px;font-size:17px;margin:0 0 20px;color:var(--primary-dark)}.tracking-section h3 .icon{width:20px;height:20px;flex-shrink:0;color:var(--primary)}
.tracking-section .order-facts{display:grid;grid-template-columns:95px minmax(0,1fr);gap:14px;font-size:13px;line-height:1.8;margin:0}.tracking-section dt{color:var(--muted)}.tracking-section dd{margin:0;min-width:0}.tracking-section dd[dir=ltr]{text-align:right}.tracking-estimates p,.tracking-destination p{font-size:13px;line-height:1.8;margin-block:8px}.tracking-estimates small{font-size:11px;color:var(--muted)}.tracking-destination{border-top:1px solid var(--border);margin-top:18px;padding-top:16px}.tracking-destination h4{font-size:13px}
.tracking-progress{border-top:1px solid var(--border);padding-top:18px;margin-top:18px}.tracking-progress>div{display:flex;justify-content:space-between;font-size:12px;gap:12px;margin-bottom:10px}.tracking-progress progress{display:block;width:100%;height:8px;border:0;border-radius:9px;overflow:hidden;accent-color:#d71920}.tracking-progress progress::-webkit-progress-bar{background:#eee6df}.tracking-progress progress::-webkit-progress-value{background:#d71920}.tracking-progress progress::-moz-progress-bar{background:#d71920}
.tracking-item{display:grid;grid-template-columns:28px minmax(0,1fr);gap:8px 10px;padding:14px 0;border-bottom:1px solid var(--border);font-size:13px;line-height:1.7}.tracking-quantity{background:#f3ece9;border-radius:6px;text-align:center;align-self:start;font-size:11px;padding:3px}.tracking-item p{font-size:11px;color:var(--muted);margin-top:5px}.tracking-item-price{grid-column:2;color:var(--primary)}.tracking-total{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#f8f2ed;padding:15px;border-radius:8px;margin-block:18px;font-size:17px;font-weight:700;color:var(--primary-dark)}
.tracking-sync{border-top:1px solid var(--border);padding-top:16px;margin-top:24px;font-size:11px;color:var(--muted);line-height:1.9}.tracking-alert{padding:14px;border:1px solid var(--border);border-radius:8px;line-height:1.8;margin-bottom:16px;font-size:13px}
.tracking-panel :deep(.order-timeline){list-style:none;margin:0;padding:0;display:grid;gap:0}.tracking-panel :deep(.order-timeline li){display:grid;grid-template-columns:26px minmax(0,1fr);gap:12px;padding:0 0 22px;position:relative;font-size:13px}.tracking-panel :deep(.order-timeline li:last-child){padding-bottom:0}.tracking-panel :deep(.order-step-icon){display:grid;place-items:center;width:24px;height:24px;border:1px solid var(--border);border-radius:50%;background:#f8f2ed;color:var(--primary);font-size:12px;z-index:1}.tracking-panel :deep(.order-timeline li:not(:last-child)::before){content:'';position:absolute;inset-inline-start:11px;top:24px;bottom:0;width:2px;background:var(--border)}.tracking-panel :deep(.order-step-current .order-step-icon){background:#d71920;color:white;border-color:#d71920}.tracking-panel :deep(.order-step-upcoming){color:var(--muted)}.tracking-panel :deep(.order-timeline time),.tracking-panel :deep(.order-timeline small){display:block;font-size:11px;color:var(--muted);margin-top:6px}.tracking-panel :deep(.order-timeline p){font-size:12px;margin-top:6px;line-height:1.8}
@media(max-width:950px){.tracking-columns{grid-template-columns:minmax(0,1fr)}}
@media(max-width:600px){.tracking-section{padding:16px}.tracking-status-card{padding:18px;align-items:flex-start}.tracking-panel .dialog-heading .button{width:100%;justify-content:center}.tracking-number{font-size:22px}.tracking-columns{gap:16px}}
.tracking-status-content{flex:1;min-width:0}
.tracking-identity-row{display:flex;align-items:center;flex-wrap:wrap;gap:20px 28px}
.tracking-number-group{display:grid;gap:8px;min-width:0}
.tracking-status-card .tracking-number{display:block;width:fit-content;max-width:100%;margin:0;padding:10px 16px;border:1px dashed var(--border);border-radius:8px;background:#fffdfa;color:var(--primary-dark);font-size:26px;font-weight:800;line-height:1.3;letter-spacing:1.5px;overflow-wrap:anywhere;box-sizing:border-box}
.tracking-current-status{display:grid;gap:10px;align-content:center;min-width:0;padding-inline-start:24px;border-inline-start:1px solid var(--border)}
.tracking-current-status :deep(.badge){justify-self:start;font-size:12px;padding:6px 12px;line-height:1.6;white-space:normal}
@media(max-width:600px){.tracking-identity-row{align-items:flex-start;flex-direction:column;gap:16px}.tracking-current-status{padding:0;border:0}.tracking-status-card .tracking-number{font-size:23px;padding:9px 13px}}
</style>
