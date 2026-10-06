<script setup>
import { ref,watch,onBeforeUnmount } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { ordersApi } from '../../api/orders.api'
import { normalizeApiError } from '../../utils/apiError'
const props=defineProps({id:String,status:String,token:String,reload:Function}),emit=defineEmits(['active'])
const auth=useAuthStore(),dialog=ref(null),reason=ref(''),error=ref(null),busy=ref(false)
let revision=0,disposed=false
function close(){if(busy.value)return;dialog.value?.close();reason.value='';error.value=null;emit('active',false)}
function open(){if(props.status!=='pending'||busy.value)return;reason.value='';error.value=null;emit('active',true);dialog.value.showModal()}
async function submit(){if(busy.value)return;const current=revision;busy.value=true;error.value=null;try{await ordersApi.cancel(props.id,{reason:reason.value.trim()},props.token);if(current!==revision||disposed)return;busy.value=false;close();await props.reload?.()}catch(cause){if(current!==revision||disposed)return;error.value=normalizeApiError(cause);if(error.value.status!==422||error.value.errors.status)await props.reload?.()}finally{if(current===revision)busy.value=false}}
watch(()=>auth.token,()=>{revision++;busy.value=false;close()})
watch(()=>props.id,()=>{revision++;busy.value=false;close()})
onBeforeUnmount(()=>{disposed=true;revision++;dialog.value?.close()})
</script>
<template><button v-if="status==='pending'" class="button" type="button" :disabled="busy" @click="open">إلغاء الطلب</button><dialog ref="dialog" class="info-dialog" @cancel.prevent="close"><form @submit.prevent="submit"><h2>إلغاء الطلب؟</h2><p>يمكن للعميل الإلغاء قبل تأكيد المطعم فقط؛ الخادم يعيد التحقق من الحالة.</p><label class="field">سبب الإلغاء<textarea v-model="reason" name="cancel_reason" required minlength="3" maxlength="500" :disabled="busy"></textarea></label><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div><button class="button button-primary" :disabled="busy">{{ busy?'جارٍ الإلغاء…':'تأكيد الإلغاء' }}</button><button class="button" type="button" :disabled="busy" @click="close">رجوع</button></form></dialog></template>
