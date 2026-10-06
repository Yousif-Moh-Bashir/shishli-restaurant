<script setup>
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import { RouterLink } from 'vue-router'
import { useOrderDetails } from '../../composables/useOrderDetails'
import OrderSnapshot from '../../components/site/OrderSnapshot.vue'
import OrderTrackingPanel from '../../components/orders/OrderTrackingPanel.vue'
import CustomerPayments from '../../components/payments/CustomerPayments.vue'
const { data: order, loading, error, load } = useOrderDetails(true)
function updated(value){if(order.value?.id===value.order.uuid)order.value={...order.value,status:value.order.status.code,payment:value.payment}}
function paymentUpdated(value){if(order.value?.id===value.id)order.value={...order.value,payment:value.payment}}
</script>
<template><main id="main" class="container checkout-main customer-detail-page"><RouterLink class="auth-back" to="/account/orders">→ طلباتي</RouterLink><div class="section-heading"><div><span class="section-kicker">حسابي</span><h1>تفاصيل الطلب</h1></div></div><AppSkeleton v-if="loading" variant="detail" label="جارٍ تحميل الطلب…"/><section v-else-if="error" class="checkout-empty" role="alert"><h2>تعذر عرض الطلب</h2><p>{{ [401,403,404].includes(error.status) ? 'الطلب غير متاح لهذا الحساب.' : error.message }}</p><button class="button" @click="load">إعادة المحاولة</button></section><template v-else-if="order"><RouterLink class="button" :to="'/track-order/'+order.id">تتبع الطلب</RouterLink><OrderTrackingPanel :order-uuid="order.id" customer-only @updated="updated"/><OrderSnapshot :order="order"/><CustomerPayments :order-id="order.id" @order-updated="paymentUpdated"/></template></main></template>

