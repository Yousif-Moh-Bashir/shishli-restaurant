<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { deliveryExceptionsApi } from '../../api/deliveryExceptions.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { normalizeApiError } from '../../utils/apiError'
import { deliveryLabels } from '../../constants/driverDelivery'
import { formatDateTime, formatCurrency } from '../../utils/formatters'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import DeliveryExceptionActions from '../../components/orders/DeliveryExceptionActions.vue'
import CashCollection from '../../components/payments/CashCollection.vue'
const page = ref(1), busy = ref(false)
const state = useOrderPolling(signal => deliveryExceptionsApi.list({ page: page.value, per_page: 20 }, signal), { paused: () => busy.value, normalizeError: normalizeApiError })
const reload = () => state.load(true, true)
function changePage(value) { page.value = value; void reload() }
void state.load()
onBeforeUnmount(() => { busy.value = false })
</script>
<template><main id="main"><header class="page-heading"><h1>استثناءات التوصيل</h1><button class="button" :disabled="busy||state.refreshing.value" @click="reload">تحديث</button></header>
  <p>تشمل جميع الأيام: طلبات تدخل الإدارة والتعذر والإرجاع والمهام المسندة التي مضى على إنشائها ساعتان. لا تُغلق أي مهمة تلقائيًا بسبب عمرها.</p>
  <p v-if="state.loading.value" role="status">جارٍ التحميل…</p><p v-if="state.error.value" role="alert">{{ state.error.value.message }}</p>
  <template v-if="state.data.value"><article v-for="order in state.data.value.data" :key="order.id" class="panel"><h2><RouterLink :to="'/admin/orders/'+order.id">{{ order.order_number }}</RouterLink></h2>
    <p>{{ order.branch?.name }} · {{ order.delivery?.driver?.name || 'دون مندوب' }} · {{ deliveryLabels[order.delivery?.status] || 'بانتظار الإسناد' }}</p><p>{{ formatDateTime(order.placed_at) }} · {{ formatCurrency(order.pricing.total) }}</p>
    <p>الدفع: {{ {cash:'نقدي',network:'الشبكة',card:'إلكتروني'}[order.payment.method] }} · {{ {pending:'بانتظار التأكيد',paid:'مدفوع',cancelled:'ملغي',failed:'متعثر',refunded:'مسترد',partially_refunded:'مسترد جزئيًا'}[order.payment.status] || order.payment.status }}</p>
    <CashCollection :order="order" :reload="reload" @active="busy=$event"/><DeliveryExceptionActions :order="order" :reload="reload" @active="busy=$event"/>
    <details v-if="order.delivery?.history?.length"><summary>سجل التوصيل</summary><p v-for="(entry,index) in order.delivery.history" :key="index">{{ deliveryLabels[entry.to_status] }} · {{ entry.actor }} · {{ entry.reason }} · {{ formatDateTime(entry.at) }}</p></details>
  </article><p v-if="!state.data.value.data.length">لا توجد استثناءات تحتاج المعالجة.</p><ApiPagination :meta="state.data.value.meta" :links="state.data.value.links" :busy="busy||state.refreshing.value" @page="changePage"/></template>
</main></template>
<style scoped>.panel{padding:18px;margin-block:16px;min-width:0}.panel p{overflow-wrap:anywhere}.page-heading{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}</style>
