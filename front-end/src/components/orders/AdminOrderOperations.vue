<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../../stores/auth'
import { adminOrdersApi } from '../../api/orders.api'
import { orderOperations } from '../../constants/orderOperations'
import { normalizeApiError } from '../../utils/apiError'
import AdminIcon from '../admin/AdminIcon.vue'
import AdminSectionHeading from '../admin/AdminSectionHeading.vue'
const props=defineProps({order:Object,reload:Function,disabled:Boolean})
const emit=defineEmits(['changed','active'])
const auth=useAuthStore(),dialog=ref(null),selected=ref(''),text=ref(''),busy=ref(false),error=ref(null),notice=ref('')
let revision=0,disposed=false
const actions=computed(()=>Object.keys(orderOperations).filter(action=>props.order?.available_actions?.includes(action)&&auth.can(orderOperations[action].permission)))
const label=computed(()=>orderOperations[selected.value]?.label||'')
function open(action){if(props.disabled||busy.value||!actions.value.includes(action))return;selected.value=action;text.value='';error.value=null;notice.value='';emit('active',true);dialog.value.showModal()}
function close(){if(busy.value)return;dialog.value?.close();selected.value='';text.value='';emit('active',false)}
async function submit(){
 if(busy.value||!selected.value)return
 const version=revision,id=props.order.id,action=selected.value
 busy.value=true;error.value=null
 try{
  const latest=await adminOrdersApi.show(id)
  if(disposed||version!==revision)return
  if(!latest.available_actions?.includes(action)||!auth.can(orderOperations[action].permission)){notice.value='تغيرت صلاحية العملية أو حالة الطلب. تم تحديث البيانات؛ راجع الإجراءات المتاحة.';await props.reload?.();return}
  await adminOrdersApi.operate(id,action,action==='cancel'?{reason:text.value.trim()}:{note:text.value.trim()||null})
  if(disposed||version!==revision)return
  busy.value=false;close();emit('changed');await props.reload?.()
 }catch(cause){if(disposed||version!==revision)return;error.value=normalizeApiError(cause);if(error.value.status!==422||error.value.errors.status||error.value.errors.code)await props.reload?.()}
 finally{if(version===revision)busy.value=false}
}
watch(()=>auth.token,()=>{revision++;busy.value=false;close();error.value=null;notice.value=''})
watch(()=>props.order?.id,()=>{revision++;busy.value=false;close();error.value=null})
onBeforeUnmount(()=>{disposed=true;revision++;dialog.value?.close()})
defineExpose({busy})
</script>
<template><div class="form-actions"><button v-for="action in actions" :key="action" class="button" :class="action==='cancel'?'danger':action==='mark_ready'?'button-primary button-success':'button-primary'" type="button" :disabled="busy||disabled" @click="open(action)"><AdminIcon :name="action==='cancel'?'x':action==='start_preparing'?'utensils':action==='dispatch'?'truck':'badge-check'"/> {{ orderOperations[action].label }}</button><p v-if="!actions.length">لا توجد إجراءات تشغيلية متاحة لهذا المستخدم حاليًا.</p></div><dialog ref="dialog" class="info-dialog" @cancel.prevent="close"><form @submit.prevent="submit"><AdminSectionHeading :icon="selected==='cancel'?'x':'badge-check'">{{ label }}؟</AdminSectionHeading><p>طلب {{ order?.order_number }}. يُعاد التحقق من الحالة والصلاحيات قبل التنفيذ.</p><label class="field">{{ selected==='cancel'?'سبب الإلغاء':'ملاحظة للعملية (اختياري)' }}<textarea v-model="text" name="operation_note" :required="selected==='cancel'" :minlength="selected==='cancel'?3:undefined" :maxlength="selected==='cancel'?500:1000" :disabled="busy"></textarea></label><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div><p v-if="notice" role="status">{{ notice }}</p><div class="form-actions"><button type="submit" class="button button-primary" :disabled="busy"><AdminIcon name="badge-check"/> {{ busy?'جارٍ تنفيذ العملية…':'تنفيذ العملية' }}</button><button type="button" class="button secondary" :disabled="busy" @click="close">رجوع</button></div></form></dialog></template>

<style scoped>.button.button-success{background:var(--admin-success,#36784c);border-color:var(--admin-success,#36784c);color:#fff}</style>
