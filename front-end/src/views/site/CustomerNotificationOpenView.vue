<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { customerNotificationsApi } from '../../api/customerNotifications.api'
const route=useRoute(),router=useRouter(),auth=useAuthStore(),error=ref('')
let generation=0,controller
watch([()=>route.params.notificationUuid,()=>auth.token],async()=>{
  const current=++generation;controller?.abort();controller=new AbortController();error.value=''
  if(!auth.isAuthenticated)return
  try {
    const row=await customerNotificationsApi.show(route.params.notificationUuid,controller.signal)
    if(current===generation)await router.replace({name:'account.order',params:{orderUuid:row.order.id}})
  }catch(cause){if(current===generation&&cause.code!=='ERR_CANCELED')error.value='تعذر فتح هذا التحديث. تحقق من الحساب والاتصال؛ قد لا يكون الطلب تابعًا لحسابك الحالي.'}
},{immediate:true})
onBeforeUnmount(()=>{generation++;controller?.abort()})
</script>
<template><main id="main" class="container" style="padding-block:30px"><p v-if="error" role="alert">{{ error }}</p><p v-else role="status">جارٍ التحقق من حسابك وملكية الطلب…</p><RouterLink class="button" to="/account/notifications">مركز إشعاراتي</RouterLink></main></template>
