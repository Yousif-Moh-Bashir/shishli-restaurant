<script setup>
import {inject as injectAdminConfirmation} from 'vue'
const {confirmAction}=injectAdminConfirmation('admin')
import {ref} from 'vue'
import {inventoryApi} from '../../api/inventory.api'
import {useApiMutation} from '../../composables/useApiState'
import {useAuthStore} from '../../stores/auth'
import ApiFeedback from './catalog/ApiFeedback.vue'
const props=defineProps({order:Object,reload:Function}),emit=defineEmits(['active']),auth=useAuthStore(),reason=ref(''),notice=ref(''),mutation=useApiMutation()
async function submit(){if(!(await confirmAction('تسجيل إرجاع كامل للكميات المستهلكة من هذا الطلب الملغى؟')))return;emit('active',true);try{const result=await mutation.run(()=>inventoryApi.returnStock(props.order.id,reason.value));if(result.ok){notice.value='أكد الخادم تسجيل الإرجاع اليدوي للكميات المستهلكة القابلة للإرجاع.';reason.value='';await props.reload()}}finally{emit('active',false)}}
defineExpose({busy:mutation.busy})
</script>
<template><section v-if="order.status==='cancelled'&&auth.can('inventory.adjust')" class="panel"><h2>إرجاع مخزون الطلب</h2><p class="muted">عملية يدوية للكميات المستهلكة فقط. الخادم يمنع تكرار الإرجاع؛ إلغاء الطلب لا يعيد المخزون المستهلك تلقائيًا.</p><form @submit.prevent="submit"><label class="field">سبب الإرجاع<textarea v-model="reason" required minlength="3" maxlength="1000" :disabled="mutation.busy.value"/></label><ApiFeedback :error="mutation.error.value" @retry="mutation.error.value=null"/><p v-if="notice" role="status">{{ notice }}</p><button class="button" :disabled="mutation.busy.value">تسجيل الإرجاع</button></form></section></template>
