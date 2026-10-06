<script setup>
import AdminFilterSummary from '../admin/AdminFilterSummary.vue'
import OrderNumber from './OrderNumber.vue'
import AppSkeleton from "../common/AppSkeleton.vue"
import AdminIcon from '../admin/AdminIcon.vue'
import AdminFilterExtras from '../admin/AdminFilterExtras.vue'
import { reactive, ref, watch } from 'vue'
import { RouterLink, onBeforeRouteLeave } from 'vue-router'
import { adminOrdersApi } from '../../api/orders.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { useOrderBranches } from '../../composables/useOrderBranches'
import { useAuthStore } from '../../stores/auth'
import { normalizeApiError } from '../../utils/apiError'
import { orderStatusLabels, orderTypeLabels, paymentStatusLabels, getOrderTypeLabel,getPaymentStatusLabel } from '../../constants/orderStatus'
import { formatCurrency,formatDateTime } from '../../utils/formatters'
import OrderStatusBadge from './OrderStatusBadge.vue'
import ApiPagination from '../admin/catalog/ApiPagination.vue'
import AdminOrderOperations from './AdminOrderOperations.vue'
import { orderOperations } from '../../constants/orderOperations'
const props=defineProps({compact:Boolean}),auth=useAuthStore(),{branches,error:branchError,loading:branchesLoading}=useOrderBranches()
const filters=reactive({search:'',status:'',branch:'',type:'',payment_status:'',payment_method:'',order_number:'',date_from:'',date_to:'',sort:'placed_at',direction:'desc'})
const activeOrder=ref(null),operations=new Map(),operationRefs=new Map()
function operationRef(id){
 // Stable callbacks keep an open card dialog registered across parent renders.
 if(!operationRefs.has(id))operationRefs.set(id,component=>{
  if(component)operations.set(id,component)
  else{
   operations.delete(id);operationRefs.delete(id)
   if(activeOrder.value===id)activeOrder.value=null
  }
 })
 return operationRefs.get(id)
}
function canOperate(order){return order.available_actions?.some(action=>orderOperations[action]&&auth.can(orderOperations[action].permission))}
onBeforeRouteLeave(()=>![...operations.values()].some(component=>component.busy))
const filterDefaults={...filters}
let applied={...filters},page=1
const state=useOrderPolling(async signal=>{
 const params={...applied,page,per_page:props.compact?5:15}
 // Status selects the visible orders; tab counts retain the other filters.
 const {status: selectedStatus,...countFilters}=applied
 const results=await Promise.allSettled([adminOrdersApi.list(params,signal),adminOrdersApi.counts(countFilters,signal)])
 if(results[0].status==='rejected')throw results[0].reason
 return {page:results[0].value,counts:results[1].status==='fulfilled'?results[1].value:null,countError:results[1].status==='rejected'?normalizeApiError(results[1].reason):null}
},{enabled:()=>auth.can('orders.view'),paused:()=>activeOrder.value!==null})
const {data,loading,refreshing,error,syncedAt}=state
function load(next=1){if(next===1)applied={...filters};page=next;state.reset();void state.load()}
watch(()=>auth.token,()=>{state.reset();if(auth.can('orders.view'))void state.load()},{immediate:true})
</script>
<template><section class="panel orders-panel"><div class="panel-head"><h2>{{ compact?'آخر الطلبات':'طلبات المطعم' }}</h2><button type="button" class="row-button" :disabled="refreshing" @click="state.load(true)"><AdminIcon name="refresh"/> تحديث</button><RouterLink v-if="compact" class="row-button" to="/admin/orders">كل الطلبات</RouterLink></div><p v-if="syncedAt" class="catalog-note">آخر مزامنة: {{ formatDateTime(syncedAt) }} · تحديث كل 15 ثانية عندما الصفحة ظاهرة.</p><form v-if="!compact" class="admin-list-filters" @submit.prevent="load()"><div class="admin-filter-primary"><label class="field">بحث عن رقم الطلب أو اسم العميل أو الهاتف<input v-model="filters.search" name="order_search" maxlength="150" type="search"></label><label class="field">الحالة<select v-model="filters.status" name="status"><option value="">كل الحالات</option><option v-for="(label,value) in orderStatusLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">الفرع<select v-model="filters.branch" name="branch"><option value="">كل الفروع</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label><button class="button" :disabled="refreshing"><AdminIcon name="search"/> تطبيق الفلاتر</button></div><AdminFilterExtras :errors="error?.errors"><label class="field">نوع الطلب<select v-model="filters.type" name="type"><option value="">كل الأنواع</option><option v-for="(label,value) in orderTypeLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">حالة الدفع<select v-model="filters.payment_status" name="payment_status"><option value="">كل الحالات</option><option v-for="(label,value) in paymentStatusLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">طريقة الدفع<select v-model="filters.payment_method" name="payment_method"><option value="">كل الطرق</option><option value="cash">نقدًا</option><option value="network">شبكة</option><option value="card">بطاقة</option></select></label><label class="field">رقم الطلب المطابق<input v-model="filters.order_number" name="order_number" maxlength="150"></label><label class="field">من تاريخ<input v-model="filters.date_from" name="date_from" type="date"></label><label class="field">إلى تاريخ<input v-model="filters.date_to" name="date_to" type="date"></label><label class="field">ترتيب حسب<select v-model="filters.sort" name="sort"><option value="placed_at">وقت الطلب</option><option value="order_number">رقم الطلب</option><option value="status">الحالة</option><option value="total">الإجمالي</option></select></label><label class="field">اتجاه الترتيب<select v-model="filters.direction" name="direction"><option value="desc">تنازلي</option><option value="asc">تصاعدي</option></select></label></AdminFilterExtras></form><AdminFilterSummary :filters="filters" :defaults="filterDefaults" :busy="refreshing" @clear="Object.assign(filters,filterDefaults);load()"/><AppSkeleton v-if="branchesLoading" variant="inline" label="جارٍ تحميل الفروع…"/><p v-if="branchError" role="alert">تعذر تحميل قائمة الفروع: {{ branchError.message }}</p><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p><p v-if="data">البيانات المعروضة من آخر مزامنة ناجحة وقد تكون تغيرت.</p><button class="row-button" :disabled="refreshing" @click="state.load()">إعادة المحاولة</button></div><AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="cards" label="جارٍ تحميل الطلبات…"/><template v-if="data"><div v-if="data.counts&&!compact" class="order-counts order-tabs"><button type="button" class="row-button" v-for="(label,status) in orderStatusLabels" :key="status" :aria-pressed="filters.status===status" :disabled="refreshing" @click="filters.status=filters.status===status?'':status;load()">{{ label }} <span>{{ data.counts[status]??'غير متاح' }}</span></button><span>مكتملة اليوم: {{ data.counts.completed_today }}</span><span>ملغاة اليوم: {{ data.counts.cancelled_today }}</span></div><p v-if="data.countError" role="alert">تعذر تحميل أعداد الطلبات: {{ data.countError.message }}</p><div class="orders-board"><article v-for="order in data.page.data" :key="order.id" class="order-card"><div class="order-card-heading"><OrderNumber :number="order.order_number"/><OrderStatusBadge :status="order.status"/></div><p>{{ order.branch?.name }} · {{ getOrderTypeLabel(order.type) }} · {{ formatDateTime(order.placed_at) }}</p><p v-if="order.customer" class="order-customer"><AdminIcon name="user-round"/> {{ order.customer.name }} · <span dir="ltr">{{ order.customer.phone }}</span></p><p v-if="order.payment">{{ getPaymentStatusLabel(order.payment.status) }}</p><div class="order-card-total"><strong v-if="order.pricing">{{ formatCurrency(order.pricing.total) }}</strong><RouterLink class="row-button" :to="'/admin/orders/'+order.id">التفاصيل والإجراءات <AdminIcon name="arrow-left"/></RouterLink></div><div v-if="!compact&&canOperate(order)" class="order-actions"><AdminOrderOperations :ref="operationRef(order.id)" :order="order" :disabled="refreshing||(activeOrder!==null&&activeOrder!==order.id)" :reload="()=>state.load(true,true)" @active="activeOrder=$event?order.id:null"/></div></article></div><p v-if="!data.page.data.length" class="empty">لا توجد طلبات تطابق الفلاتر.</p><ApiPagination v-if="!compact" :meta="data.page.meta" :links="data.page.links" :busy="refreshing" @page="load"/></template></section></template>
<style scoped>.order-filter-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding-block:16px}.field{display:grid;gap:5px;min-width:0}.field input,.field select{width:100%;min-width:0}.order-counts{display:flex;gap:12px;flex-wrap:wrap;margin-block:12px}.order-card h2{overflow-wrap:anywhere;min-width:0}.order-card-total{flex-wrap:wrap}.order-card{min-width:0}@media(max-width:1000px){.order-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.order-filter-grid{grid-template-columns:1fr}}</style>
