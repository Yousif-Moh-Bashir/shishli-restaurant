<script setup>
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AdminDriverAssignment from '../../components/orders/AdminDriverAssignment.vue'
import DeliveryExceptionActions from '../../components/orders/DeliveryExceptionActions.vue'
import OrderLifecycleSummary from '../../components/orders/OrderLifecycleSummary.vue'
import OrderNumber from '../../components/orders/OrderNumber.vue'
import StockReturn from '../../components/admin/StockReturn.vue'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import AdminSectionHeading from '../../components/admin/AdminSectionHeading.vue'
import AdminIcon from '../../components/admin/AdminIcon.vue'
import { ref,watch,computed } from 'vue'
import { RouterLink,useRoute,onBeforeRouteLeave } from 'vue-router'
import { adminOrdersApi } from '../../api/orders.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { useAuthStore } from '../../stores/auth'
import { isTerminalOrder, getOrderTypeLabel } from '../../constants/orderStatus'
import { formatDateTime } from '../../utils/formatters'
import OrderSnapshot from '../../components/site/OrderSnapshot.vue'
import OrderStatusBadge from '../../components/orders/OrderStatusBadge.vue'
import OrderTimeline from '../../components/orders/OrderTimeline.vue'
import AdminOrderOperations from '../../components/orders/AdminOrderOperations.vue'
import KitchenSnapshot from '../../components/orders/KitchenSnapshot.vue'
import KitchenOrderDetails from '../../components/orders/KitchenOrderDetails.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import { normalizeApiError } from '../../utils/apiError'
import PaymentSummary from '../../components/payments/PaymentSummary.vue'
import CashCollection from '../../components/payments/CashCollection.vue'
import {isPaymentPending} from '../../utils/paymentStatus'
import RelatedNotifications from '../../components/notifications/RelatedNotifications.vue'
const auth=useAuthStore(),route=useRoute(),active=ref(false),operations=ref(null),collection=ref(null),stockReturn=ref(null),historyPage=ref(1)
const kitchen=computed(()=>route.meta.kitchen===true)
const state=useOrderPolling(async signal=>{
 const order=await adminOrdersApi.show(route.params.orderUuid,signal)
 let history=null,historyError=null
 if(!kitchen.value&&Object.hasOwn(order,'status_history'))try{history=await adminOrdersApi.history(order.id,{page:historyPage.value,per_page:50},signal)}catch(cause){if(signal.aborted||[401,403,404].includes(cause.response?.status))throw cause;historyError=normalizeApiError(cause)}
 return {order,history,historyError}
},{enabled:()=>auth.can('orders.view')&&(!isTerminalOrder(state.data.value?.order?.status)||!kitchen.value&&isPaymentPending(state.data.value?.order?.latest_payment?.status||state.data.value?.order?.payment?.status)),paused:()=>active.value})
const {data,loading,error,refreshing,syncedAt}=state
const reload=()=>state.load(true,true)
watch([()=>route.params.orderUuid,()=>auth.token],()=>{active.value=false;historyPage.value=1;state.reset();if(auth.can('orders.view'))void state.load()},{immediate:true})
function page(value){historyPage.value=value;void state.load(true)}
onBeforeRouteLeave(()=>!operations.value?.busy&&!collection.value?.busy&&!stockReturn.value?.busy)
</script>
<template><main id="main" class="admin-detail-page"><RouterLink class="row-button" :to="kitchen?'/admin/kitchen':'/admin/orders'"><AdminIcon name="arrow-left"/> {{ kitchen?'قائمة المطبخ':'الطلبات' }}</RouterLink><AdminPageHeader class="page-heading"><div><span class="eyebrow">إدارة شيشلي</span><h1>{{ kitchen?'تفاصيل طلب المطبخ':'تفاصيل الطلب' }}</h1></div><button class="button" :disabled="refreshing||active" @click="reload"><AdminIcon name="refresh"/> تحديث</button></AdminPageHeader><AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="detail" label="جارٍ تحميل الطلب…"/><div v-if="error" class="panel" role="alert"><p>{{ error.message }}</p><p v-if="data">البيانات المعروضة قديمة؛ حدّث قبل أي إجراء.</p><button class="row-button" :disabled="active" @click="state.load()">إعادة المحاولة</button></div><template v-if="data"><KitchenOrderDetails v-if="kitchen" :order="data.order" :synced-at="syncedAt"><AdminOrderOperations kitchen-only ref="operations" :order="data.order" :reload="reload" @active="active=$event"/></KitchenOrderDetails><template v-else><section class="panel detail-record-hero"><OrderNumber :number="data.order.order_number"/><OrderStatusBadge :status="data.order.status"/><p>{{ data.order.branch?.name }} · {{ getOrderTypeLabel(data.order.type) }} · {{ formatDateTime(data.order.placed_at) }}</p><OrderLifecycleSummary :status="data.order.status" :type="data.order.type" :delivery-status="data.order.delivery?.status"/><AdminOrderOperations ref="operations" :order="data.order" :reload="reload" @active="active=$event"/><p v-if="syncedAt">آخر مزامنة: {{ formatDateTime(syncedAt) }}</p></section><KitchenSnapshot v-if="!data.order.pricing" :order="data.order"/><OrderSnapshot v-else :order="data.order"/><section v-if="data.order.type=== 'delivery'" class="panel"><AdminDriverAssignment :order="data.order" :reload="reload" @active="active=$event"/><DeliveryExceptionActions :order="data.order" :reload="reload" @active="active=$event"/></section></template><p v-if="data.historyError" role="alert">تعذر تحميل سجل الحالات: {{ data.historyError.message }}</p><section v-if="!kitchen&amp;&amp;data.order.payment" class="panel"><AdminSectionHeading icon="wallet">دفع الطلب</AdminSectionHeading><PaymentSummary :payment="data.order.payment"/><PaymentSummary v-if="data.order.latest_payment&amp;&amp;auth.can('payments.view')" :payment="data.order.latest_payment" staff/><div class="order-payment-actions"><RouterLink v-if="data.order.latest_payment&amp;&amp;auth.can('payments.view')" class="row-button" :to="'/admin/payments/'+data.order.latest_payment.id">تفاصيل محاولة الدفع والمعاملات</RouterLink><CashCollection ref="collection" :order="data.order" :reload="reload" @active="active=$event"/></div></section><section v-if="data.history" class="panel"><AdminSectionHeading icon="clock">سجل الحالات</AdminSectionHeading><OrderTimeline :status-history="data.history.data"/><ApiPagination :meta="data.history.meta" :links="data.history.links" :busy="refreshing||active" @page="page"/></section><section v-if="!kitchen&&data.order.cancellation" class="panel"><AdminSectionHeading icon="message-circle">تفاصيل الإلغاء</AdminSectionHeading><p>{{ data.order.cancellation.reason }}</p><p>{{ formatDateTime(data.order.cancellation.cancelled_at) }}</p></section><StockReturn v-if="!kitchen" ref="stockReturn" :order="data.order" :reload="reload" @active="active=$event"/><RelatedNotifications v-if="!kitchen &amp;&amp; auth.can('notifications.view')" :reference-id="data.order.id"/></template></main></template>
<style scoped>.panel{margin-block:16px;min-width:0}.panel h2{overflow-wrap:anywhere}.checkout-layout{margin-block:16px}</style>

<style scoped>
.order-payment-actions{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-top:20px;padding-top:18px;border-top:1px solid var(--line);min-width:0}
.order-payment-actions>.row-button,.order-payment-actions :deep(.cash-collect){display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;max-width:100%;min-width:0;white-space:normal;text-align:center;line-height:1.7;box-sizing:border-box}
.order-payment-actions>p{flex-basis:100%;margin:0}
@media(max-width:600px){.order-payment-actions{align-items:stretch;gap:10px}.order-payment-actions>.row-button,.order-payment-actions :deep(.cash-collect){width:100%}}
</style>
