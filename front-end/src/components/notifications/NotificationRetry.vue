<script setup>
import {computed,ref,watch,onBeforeUnmount} from 'vue'
import {useAuthStore} from '../../stores/auth'
import {notificationsApi} from '../../api/notifications.api'
import {normalizeApiError} from '../../utils/apiError'
import AdminIcon from '../admin/AdminIcon.vue'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
const props=defineProps({notification:Object,reload:Function}),emit=defineEmits(['active','accepted'])
const auth=useAuthStore(),dialog=ref(null),busy=ref(false),error=ref(null),notice=ref(''),cooling=ref(false)
let revision=0,disposed=false,timer,uncertain=false
const eligible=computed(()=>auth.can('notifications.retry')&&props.notification?.status==='failed')
function close(){if(busy.value)return;dialog.value?.close();emit('active',false)}
function open(){if(!eligible.value||busy.value||cooling.value)return;error.value=null;notice.value='';emit('active',true);dialog.value.showModal()}
async function submit(){
 if(busy.value||cooling.value||!eligible.value)return
 const current=revision,id=props.notification.id;busy.value=true;error.value=null
 try{
  const latest=await notificationsApi.show(id)
  if(disposed||current!==revision)return
  if(latest.status!=='failed'||!auth.can('notifications.retry')){notice.value='تغيرت حالة الإشعار. حدّثنا الحالة الحالية دون طلب محاولة أخرى.';await props.reload?.();return}
  // After a timeout, a new confirmation is required even when a fast worker has
  // already failed again. Never automatically resend an uncertain request.
  if(uncertain){uncertain=false;notice.value='تم التحقق: الحالة الحالية ما زالت متعذرة. راجع المحاولات ثم أكد مجددًا إن أردت محاولة أخرى.';await props.reload?.();return}
  const result=await notificationsApi.retry(id)
  if(disposed||current!==revision)return
  busy.value=false;close();emit('accepted',result);await props.reload?.()
 }catch(cause){
  if(disposed||current!==revision)return
  error.value=normalizeApiError(cause)
  uncertain=!error.value.status||error.value.status>=500
  if(uncertain)notice.value='تعذر التأكد من نتيجة طلب إعادة المحاولة. نقرأ الحالة أولًا؛ لا يعني ذلك أن الرسالة لم تُرسل.'
  if(error.value.status===429){cooling.value=true;const seconds=Number(error.value.retryAfter);timer=setTimeout(()=>{cooling.value=false},Math.max(60000,Number.isFinite(seconds)?seconds*1000:0))}
  await props.reload?.()
 }finally{if(current===revision)busy.value=false}
}
watch([()=>props.notification?.id,()=>auth.token],()=>{revision++;busy.value=false;close();error.value=null;notice.value='';uncertain=false})
onBeforeUnmount(()=>{disposed=true;revision++;clearTimeout(timer);dialog.value?.close()})
defineExpose({busy})
</script>
<template><button v-if="eligible" class="button notification-retry" type="button" :disabled="busy||cooling" @click="open"><AdminIcon name="refresh"/> إعادة محاولة الإرسال</button><dialog ref="dialog" class="info-dialog notification-retry-dialog" @cancel.prevent="close"><form @submit.prevent="submit"><AdminSectionHeading icon="message-circle">إعادة محاولة الإرسال؟</AdminSectionHeading><p>قد يؤدي هذا إلى إرسال الرسالة إلى المستلم مرة أخرى. راجع سجل المحاولات قبل التأكيد.</p><p v-if="notification?.last_error?.code==='WORKER_INTERRUPTED'">قد يكون الإرسال السابق قد وصل دون تسجيل النتيجة؛ تحقق من التسليم السابق قبل طلب محاولة جديدة.</p><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div><p v-if="notice" role="status">{{ notice }}</p><div class="form-actions"><button class="button button-primary" type="submit" :disabled="busy||cooling||!eligible"><AdminIcon name="refresh"/> {{ busy?'جارٍ التحقق…':'تأكيد إعادة المحاولة' }}</button><button class="button secondary" type="button" :disabled="busy" @click="close">رجوع</button></div></form></dialog></template>
<style scoped>p{margin-block:12px;overflow-wrap:anywhere}</style>
