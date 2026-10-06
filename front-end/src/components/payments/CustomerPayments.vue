<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import {watch,ref} from 'vue'
import {useAuthStore} from '../../stores/auth'
import {paymentsApi} from '../../api/payments.api'
import {ordersApi} from '../../api/orders.api'
import {useOrderPolling} from '../../composables/useOrderPolling'
import {isPaymentPending} from '../../utils/paymentStatus'
import PaymentSummary from './PaymentSummary.vue'
import ApiPagination from '../admin/catalog/ApiPagination.vue'
const props=defineProps({orderId:{type:String,required:true}}),auth=useAuthStore(),page=ref(1)
const emit=defineEmits(['order-updated'])
let signature=''
const state=useOrderPolling(async signal=>{const id=props.orderId,result=await paymentsApi.owned(id,{page:page.value,per_page:10},signal),next=JSON.stringify(result.data.map(payment=>[payment.id,payment.status]));if(signature&&next!==signature){const order=await ordersApi.show(id,signal);if(!signal.aborted)emit('order-updated',order)}if(!signal.aborted)signature=next;return result},{interval:20000,enabled:()=>auth.isAuthenticated&&(!state.data.value||state.data.value.data.some(payment=>isPaymentPending(payment.status)))})
const {data,loading,refreshing,error}=state
watch([()=>props.orderId,()=>auth.token],()=>{page.value=1;signature='';state.reset();if(auth.isAuthenticated)void state.load()},{immediate:true})
function changePage(value){page.value=value;signature='';state.reset();void state.load()}
</script>
<template><section class="checkout-panel customer-payments"><div class="dialog-heading"><h2>محاولات الدفع</h2><button class="button" :disabled="refreshing" @click="state.load(true)">تحديث الدفع</button></div><AppSkeleton v-if="refreshing&&!loading" variant="inline" label="جارٍ تحديث البيانات…"/><AppSkeleton v-if="loading" variant="list" label="جارٍ تحميل محاولات الدفع…"/><div v-if="error" role="alert"><p>{{ [401,403,404].includes(error.status)?'لا تتوفر محاولات الدفع لهذا الحساب.':error.message }}</p><p v-if="data">البيانات من آخر مزامنة ناجحة.</p><button class="button" @click="state.load()">إعادة المحاولة</button></div><template v-if="data"><article v-for="payment in data.data" :key="payment.id" class="customer-payment"><PaymentSummary :payment="payment"/></article><p v-if="!data.data.length">لا توجد محاولات دفع مسجلة بعد.</p><ApiPagination :meta="data.meta" :links="data.links" :busy="refreshing" @page="changePage"/></template></section></template>
<style scoped>.customer-payments{margin-block:20px;min-width:0}.dialog-heading{flex-wrap:wrap}.customer-payment{border-bottom:1px solid var(--line);padding-block:12px}</style>
