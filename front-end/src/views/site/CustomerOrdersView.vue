<script setup>
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import OrderStatusBadge from '../../components/orders/OrderStatusBadge.vue'
import { reactive, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useApiState } from '../../composables/useApiState'
import { ordersApi } from '../../api/orders.api'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import { orderStatusLabels, orderTypeLabels, paymentStatusLabels, getOrderStatusLabel, getOrderTypeLabel, getPaymentStatusLabel } from '../../constants/orderStatus'
import { formatCurrency, formatDateTime } from '../../utils/formatters'
const auth=useAuthStore(), state=useApiState(), { data,loading,error }=state
// Search and branch are accepted by ListOrdersRequest but ignored by CustomerOrderQuery; do not advertise ineffective filters.
const filters=reactive({status:'',type:'',payment_status:'',date_from:'',date_to:''})
async function load(page=1){await state.run(signal=>ordersApi.list({...filters,page,per_page:15},signal))}
watch(()=>auth.token,()=>{state.clear();if(auth.isAuthenticated)void load()},{immediate:true})
</script>
<template><main id="main" class="container checkout-main"><div class="section-heading"><div><span class="section-kicker">حسابي</span><h1>طلباتي</h1></div><RouterLink class="button" to="/account/addresses">عناويني</RouterLink></div><form class="checkout-panel form-grid" @submit.prevent="load()"><label class="field">الحالة<select v-model="filters.status" name="status"><option value="">كل الحالات</option><option v-for="(label,value) in orderStatusLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">نوع الطلب<select v-model="filters.type" name="type"><option value="">كل الأنواع</option><option v-for="(label,value) in orderTypeLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">حالة الدفع<select v-model="filters.payment_status" name="payment_status"><option value="">كل الحالات</option><option v-for="(label,value) in paymentStatusLabels" :key="value" :value="value">{{ label }}</option></select></label><label class="field">من تاريخ<input v-model="filters.date_from" name="date_from" type="date"></label><label class="field">إلى تاريخ<input v-model="filters.date_to" name="date_to" type="date"></label><button class="button button-primary" :disabled="loading">تطبيق الفلاتر</button></form><AppSkeleton v-if="loading" variant="cards" label="جارٍ تحميل الطلبات…"/><section v-else-if="error" class="checkout-empty" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p><button class="button" @click="load()">إعادة المحاولة</button></section><template v-else-if="data"><p v-if="!data.data.length" class="checkout-empty">لا توجد طلبات تطابق هذه الفلاتر.</p><div class="customer-orders"><article v-for="order in data.data" :key="order.id" class="checkout-panel"><h2 class="order-number" dir="ltr">{{ order.order_number }}</h2><p><OrderStatusBadge :status="order.status"/> · {{ getOrderTypeLabel(order.type) }}</p><p>{{ order.branch?.name }} · {{ formatDateTime(order.placed_at) }}</p><p>{{ getPaymentStatusLabel(order.payment.status) }}</p><strong>{{ formatCurrency(order.pricing.total) }}</strong><RouterLink class="button button-primary" :to="{name:'account.order',params:{orderUuid:order.id}}">تفاصيل الطلب</RouterLink></article></div><ApiPagination :meta="data.meta" :links="data.links" :busy="loading" @page="load"/></template></main></template>
<style scoped>.customer-orders{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-block:20px}.order-number{font-size:1rem;overflow-wrap:anywhere}.customer-orders .button{display:block;margin-top:12px}.form-grid{gap:16px}.form-grid .field{min-width:0}@media(max-width:700px){.customer-orders{grid-template-columns:1fr}}</style>

