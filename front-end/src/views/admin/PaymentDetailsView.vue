<script setup>
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import AdminIcon from '../../components/admin/AdminIcon.vue'
import AdminSectionHeading from '../../components/admin/AdminSectionHeading.vue'
import {ref,watch} from 'vue'
import {RouterLink,useRoute,onBeforeRouteLeave} from 'vue-router'
import {paymentsApi} from '../../api/payments.api'
import {useAuthStore} from '../../stores/auth'
import {useOrderPolling} from '../../composables/useOrderPolling'
import {isPaymentPending,transactionTypeLabels,transactionStatusLabels,paymentAmount} from '../../utils/paymentStatus'
import {formatDateTime} from '../../utils/formatters'
import {normalizeApiError} from '../../utils/apiError'
import PaymentSummary from '../../components/payments/PaymentSummary.vue'
import CashCollection from '../../components/payments/CashCollection.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import RelatedNotifications from '../../components/notifications/RelatedNotifications.vue'
const auth=useAuthStore(),route=useRoute(),page=ref(1),active=ref(false),collection=ref(null)
const state=useOrderPolling(async signal=>{const id=route.params.paymentUuid,payment=await paymentsApi.show(id,signal);let transactions=null,transactionError=null;try{transactions=await paymentsApi.transactions(id,{page:page.value,per_page:20},signal)}catch(cause){if(signal.aborted||[401,403,404].includes(cause.response?.status))throw cause;transactionError=normalizeApiError(cause)}return {payment,transactions,transactionError}},{interval:20000,enabled:()=>auth.can('payments.view')&&(!state.data.value||isPaymentPending(state.data.value.payment.status)),paused:()=>active.value})
const {data,loading,refreshing,error}=state
const reload=()=>state.load(true,true)
function changePage(value){page.value=value;void reload()}
watch([()=>route.params.paymentUuid,()=>auth.token],()=>{page.value=1;active.value=false;state.reset();if(auth.can('payments.view'))void state.load()},{immediate:true})
onBeforeRouteLeave(()=>!collection.value?.busy)
</script>
<template><main id="main" class="admin-detail-page"><RouterLink class="row-button" to="/admin/payments"><AdminIcon name="arrow-left"/> المدفوعات</RouterLink><AdminPageHeader class="page-heading"><h1>تفاصيل الدفع</h1><button class="button" :disabled="refreshing||active" @click="reload"><AdminIcon name="refresh"/> تحديث حالة الدفع</button></AdminPageHeader><AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="detail" label="جارٍ تحميل محاولة الدفع…"/><div v-if="error" class="panel" role="alert"><p>{{ error.message }}</p><p v-if="data">تعذر التأكد من الحالة الحالية؛ البيانات من آخر مزامنة ناجحة.</p><button class="button" @click="reload">إعادة المحاولة</button></div><template v-if="data"><div class="detail-summary-grid"><section class="panel payment-detail detail-record-hero"><h2 dir="ltr">{{ data.payment.order?.order_number }}</h2><PaymentSummary :payment="data.payment" staff/><div class="payment-detail-actions"><RouterLink v-if="auth.can('orders.view')&&data.payment.order" class="row-button" :to="'/admin/orders/'+data.payment.order.id">الطلب المرتبط</RouterLink><CashCollection ref="collection" :payment="data.payment" :reload="reload" @active="active=$event"/></div><p>حالة الدفع تُقرأ من المطعم. مدة الانتظار أو معاملات الرابط لا تُثبت نجاح الدفع.</p></section><section class="panel payment-detail"><AdminSectionHeading icon="shopping-bag">معاملات هذه المحاولة</AdminSectionHeading><p>سجل العمليات المالية داخل محاولة الدفع؛ ليس قائمة طلبات أو محاولات دفع جديدة.</p><p v-if="data.transactionError" role="alert">{{ data.transactionError.message }}</p><template v-if="data.transactions"><article v-for="transaction in data.transactions.data" :key="transaction.id" class="transaction-card"><strong>{{ transactionTypeLabels[transaction.type]||'نوع غير معروف' }} · {{ transactionStatusLabels[transaction.status]||'حالة غير معروفة' }}</strong><p>{{ paymentAmount(transaction.amount,data.payment.currency) }}</p><p v-if="transaction.processed_at">معالجة: {{ formatDateTime(transaction.processed_at) }}</p><p v-else>لم يُسجل وقت معالجة.</p><p v-if="transaction.provider_reference">مرجع المزود: <span dir="ltr">{{ transaction.provider_reference }}</span></p></article><p v-if="!data.transactions.data.length">لا توجد معاملات مسجلة لهذه المحاولة بعد.</p><ApiPagination :meta="data.transactions.meta" :links="data.transactions.links" :busy="refreshing||active" @page="changePage"/></template></section></div><RelatedNotifications v-if="auth.can('notifications.view')" :reference-id="data.payment.id"/></template></main></template>


<style scoped>
.payment-detail-actions{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-block:20px;padding-top:18px;border-top:1px solid var(--line);min-width:0}
.payment-detail-actions>.row-button,.payment-detail-actions :deep(.cash-collect){display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;min-width:0;max-width:100%;white-space:normal;text-align:center;line-height:1.7;box-sizing:border-box}
.payment-detail-actions>p{flex-basis:100%;margin:0}
@media(max-width:600px){.payment-detail-actions{align-items:stretch;gap:10px}.payment-detail-actions>.row-button,.payment-detail-actions :deep(.cash-collect){width:100%}}
</style>