<script setup>
import { ref, watch } from 'vue'
import { deliveryExceptionsApi } from '../../api/deliveryExceptions.api'
import { exceptionPayload } from '../../constants/deliveryExceptions'
import { driverError } from '../../constants/driverDelivery'
const props = defineProps({ order: Object, reload: Function })
const emit = defineEmits(['active'])
const reason = ref(''), opened = ref(false), busy = ref(false), error = ref(null), pending = ref(null)
watch(reason, () => { pending.value = null })
watch(() => props.order?.id, () => { pending.value = null; reason.value = ''; opened.value = false; error.value = null })
async function submit() {
  if (busy.value || !props.order.can_request_intervention || reason.value.trim().length < 3) return
  pending.value ||= exceptionPayload(props.order, 'request_intervention', reason.value)
  busy.value = true; emit('active', true); error.value = null
  try { await deliveryExceptionsApi.intervention(props.order.id, pending.value); opened.value = false; pending.value = null; await props.reload?.() }
  catch (cause) { error.value = driverError(cause); await props.reload?.() }
  finally { busy.value = false; emit('active', false) }
}
</script>
<template><section class="driver-card" v-if="order.can_request_intervention || order.intervention_requested_at"><p v-if="order.task_closed_at" role="status">انتهت عهدة هذه المهمة من الموظف المخوّل. أي مبالغ محصلة تبقى لحين تسويتها لدى الكاشير.</p><p v-else-if="order.intervention_requested_at" role="status">تم تسجيل طلب تدخل الإدارة. المهمة تبقى في عهدتك حتى الإرجاع أو نقل العهدة أو إنهائها من الموظف المخوّل.</p><template v-else><button class="driver-button secondary" :disabled="busy" @click="opened=!opened">طلب تدخل الإدارة</button><form v-if="opened" @submit.prevent="submit"><label class="driver-field">سبب طلب التدخل<textarea v-model="reason" minlength="3" maxlength="500" rows="3" required :disabled="busy"/></label><button class="driver-button" :disabled="busy">{{ busy?'جارٍ الإرسال…':'إرسال طلب التدخل' }}</button></form></template><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ Array.isArray(messages)?messages.join(' '):messages }}</p></div></section></template>
