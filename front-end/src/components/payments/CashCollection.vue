<script setup>
import {computed,ref,watch,onBeforeUnmount} from 'vue'
import {useAuthStore} from '../../stores/auth'
import {paymentsApi} from '../../api/payments.api'
import {adminOrdersApi} from '../../api/orders.api'
import {normalizeApiError} from '../../utils/apiError'
import {paymentAmount,getPaymentMethodLabel} from '../../utils/paymentStatus'
import AdminIcon from '../admin/AdminIcon.vue'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
const props=defineProps({order:Object,payment:Object,reload:Function}),emit=defineEmits(['active','collected'])
const auth=useAuthStore(),dialog=ref(null),busy=ref(false),error=ref(null),notice=ref(''),uncertain=ref(false),cooling=ref(false),networkVerified=ref(false)
let revision=0,disposed=false,timer
const orderId=computed(()=>props.order?.id||props.payment?.order?.id)
const eligible=computed(()=>auth.can('payments.collect_cash')&&orderId.value&&(props.order?props.order.available_actions?.includes('collect_cash'):['cash','network'].includes(props.payment?.method)&&props.payment.status==='pending'))
const amount=computed(()=>props.payment?.amount??props.order?.latest_payment?.amount??props.order?.pricing?.total)
const currency=computed(()=>props.payment?.currency??props.order?.latest_payment?.currency??props.order?.pricing?.currency)
const method=computed(()=>props.payment?.method??props.order?.payment?.method)
function close(){if(busy.value)return;dialog.value?.close();emit('active',false)}
function open(){if(!eligible.value||busy.value||cooling.value)return;networkVerified.value=false;error.value=null;notice.value='';uncertain.value=false;emit('active',true);dialog.value.showModal()}
async function submit(){
 if(busy.value||cooling.value||!eligible.value||method.value==='network'&&!networkVerified.value)return
 const current=revision,id=orderId.value;busy.value=true;error.value=null;notice.value=''
 try{
  // Verify the current resource before repeating an uncertain or stale collection.
  const latest=props.order?await adminOrdersApi.show(id):await paymentsApi.show(props.payment.id)
  if(disposed||current!==revision)return
  const allowed=props.order?latest.available_actions?.includes('collect_cash'):['cash','network'].includes(latest.method)&&latest.status==='pending'
  if(!auth.can('payments.collect_cash')||!allowed){notice.value='تغيرت حالة التحصيل. أعدنا تحميل الحالة الحالية؛ لا يلزم إرسال العملية مرة أخرى.';await props.reload?.();return}
  const value=await paymentsApi.collect(id,method.value==='network'?{network_verified:networkVerified.value}:{})
  if(disposed||current!==revision)return
  uncertain.value=false;busy.value=false;close();emit('collected',value);await props.reload?.()
 }catch(cause){
  if(disposed||current!==revision)return
  error.value=normalizeApiError(cause);uncertain.value=!error.value.status||error.value.status>=500
  if(uncertain.value)notice.value='تعذر التأكد من نتيجة التحصيل. نتحقق من الحالة الحالية قبل أي محاولة أخرى.'
  if(error.value.status===429){cooling.value=true;timer=setTimeout(()=>{cooling.value=false},Math.max(60000,Number(error.value.retryAfter||0)*1000))}
  await props.reload?.()
 }finally{if(current===revision)busy.value=false}
}
watch([()=>auth.token,orderId],()=>{revision++;busy.value=false;close();error.value=null;notice.value='';uncertain.value=false})
onBeforeUnmount(()=>{disposed=true;revision++;clearTimeout(timer);dialog.value?.close()})
defineExpose({busy})
</script>
<template><button v-if="eligible" type="button" class="button button-primary cash-collect" :disabled="busy||cooling" @click="open"><AdminIcon name="badge-check"/> {{ method==='network'?'تأكيد دفع الشبكة':'تسجيل التحصيل' }}</button><p v-if="notice&&!dialog?.open" role="status">{{ notice }}</p><dialog ref="dialog" class="info-dialog cash-dialog" @cancel.prevent="close"><form @submit.prevent="submit"><AdminSectionHeading icon="badge-check">تأكيد تحصيل الدفع</AdminSectionHeading><p>طلب <span dir="ltr">{{ order?.order_number||payment?.order?.order_number }}</span></p><p>طريقة الدفع: <strong>{{ getPaymentMethodLabel(method) }}</strong></p><p>المبلغ المسجل: <strong>{{ paymentAmount(amount,currency) }}</strong></p><p>أكد استلام المبلغ نقدًا أو نجاح الدفع عبر جهاز الشبكة حسب طريقة دفع الطلب قبل تسجيل التحصيل.</p><label v-if="method==='network'"><input v-model="networkVerified" type="checkbox" required :disabled="busy"/> تحققت من نجاح العملية فعليًا عبر جهاز الشبكة، والمبلغ يطابق الطلب.</label><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div><p v-if="notice" role="status">{{ notice }}</p><div class="form-actions"><button type="submit" class="button button-primary" :disabled="busy||cooling||!eligible"><AdminIcon name="badge-check"/> {{ busy?'جارٍ التحقق والتسجيل…':uncertain?'التحقق ثم تسجيل التحصيل':'تسجيل التحصيل' }}</button><button type="button" class="button secondary" :disabled="busy" @click="close">رجوع</button></div></form></dialog></template>
<style scoped>.cash-dialog p{margin-block:12px;overflow-wrap:anywhere}.cash-dialog form{max-width:100%}</style>
