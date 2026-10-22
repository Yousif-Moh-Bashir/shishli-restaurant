<script setup>
import { ref, watch, computed, inject } from 'vue'
import { deliveryExceptionsApi } from '../../api/deliveryExceptions.api'
import { adminDriversApi } from '../../api/drivers.api'
import { exceptionActionLabels, exceptionPayload } from '../../constants/deliveryExceptions'
import { normalizeApiError } from '../../utils/apiError'
import { formatDateTime } from '../../utils/formatters'
const props = defineProps({ order: Object, reload: Function })
const emit = defineEmits(['active'])
const admin = inject('admin'), action = ref(''), reason = ref(''), target = ref(''), drivers = ref([]), busy = ref(false), error = ref(null), notice = ref(''), pending = ref(null), confirmed = ref(false), candidatesPage = ref(1), candidatesLast = ref(1)
const available = computed(() => props.order?.delivery_exception?.actions || [])
const custody = ref('')
watch(custody, () => { pending.value = null; confirmed.value = false })
watch(action, value => { emit('active', value !== '') })
watch(() => props.order?.id, () => { action.value = reason.value = target.value = ''; pending.value = null; error.value = null; confirmed.value = false })
watch([action, reason, target], () => { pending.value = null; confirmed.value = false })
async function candidates(page = 1) {
  busy.value = true; emit('active', true); error.value = null
  try { const result = await adminDriversApi.list({ branch_uuid: props.order.branch?.id, available: 1, per_page: 100, page }); drivers.value = result.data.filter(item => !item.current_order || item.current_order.id === props.order.id && props.order.delivery?.status === 'failed'); candidatesPage.value = page; candidatesLast.value = result.meta.last_page }
  catch (cause) { error.value = normalizeApiError(cause) }
  finally { busy.value = false; emit('active', action.value !== '') }
}
watch(action, value => { if (value === 'reassign') void candidates() })
async function submit() {
  if (busy.value || !available.value.includes(action.value) || reason.value.trim().length < 3 || !confirmed.value || action.value === 'reassign' && !target.value) return
  if (!await admin.confirmAction(exceptionActionLabels[action.value] + '؟ سيُحفظ السبب في سجل التدقيق.')) return
  pending.value ||= exceptionPayload(props.order, action.value, reason.value, target.value)
  if (action.value === 'resolve_custody') pending.value.custody_outcome = custody.value
  busy.value = true; emit('active', true); error.value = null; notice.value = ''
  try { await deliveryExceptionsApi.manage(props.order.id, pending.value); pending.value = null; action.value = reason.value = ''; notice.value = 'تمت معالجة الإجراء وتحديث العهدة.'; await props.reload?.() }
  catch (cause) { error.value = normalizeApiError(cause); await props.reload?.() }
  finally { busy.value = false; emit('active', action.value !== '') }
}
defineExpose({ busy })
</script>
<template><section v-if="order.type==='delivery'" class="delivery-exception-actions"><h2>معالجة استثناء التوصيل</h2><p v-for="(entry,index) in order.cash_collections || []" :key="index">التحصيل النقدي: {{ entry.amount }} {{ entry.currency }} · {{ entry.collector }} · {{ entry.settled_at ? 'سُلّم للكاشير' : 'في عهدة المندوب المحصّل — بانتظار التسوية' }}</p>
  <p v-if="order.delivery_exception?.block" role="status">{{ order.delivery_exception.block.message }}</p>
  <p v-if="order.delivery_exception?.requested_at">طلب تدخل الإدارة: {{ formatDateTime(order.delivery_exception.requested_at) }} · {{ order.delivery_exception.reason }}</p>
  <p v-if="order.delivery_exception?.task_closed_at">انتهت عهدة المهمة: {{ formatDateTime(order.delivery_exception.task_closed_at) }}. أي عهدة نقدية تبقى على المندوب الذي حصّلها حتى تسويتها.</p>
  <p v-if="order.delivery_exception?.financial_review_required" role="status">تحتاج الحالة مراجعة مالية من الموظف المخوّل. لم يُنفذ استرداد تلقائي.</p>
  <form v-if="available.length" @submit.prevent="submit"><label>الإجراء<select v-model="action" :disabled="busy" required><option value="">اختر الإجراء</option><option v-for="item in available" :key="item" :value="item">{{ exceptionActionLabels[item] }}</option></select></label>
    <template v-if="action"><template v-if="action==='reassign'"><label>المندوب<select v-model="target" required :disabled="busy"><option value="">اختر المندوب المستلم للعهدة</option><option v-for="driver in drivers" :key="driver.id" :value="driver.id">{{ driver.name }}</option></select></label><div v-if="candidatesLast>1"><button type="button" class="row-button" :disabled="busy||candidatesPage<=1" @click="candidates(candidatesPage-1)">السابق</button> {{ candidatesPage }} / {{ candidatesLast }} <button type="button" class="row-button" :disabled="busy||candidatesPage>=candidatesLast" @click="candidates(candidatesPage+1)">التالي</button></div></template>
      <label v-if="action==='resolve_custody'">نتيجة التحقق من العهدة<select v-model="custody" required :disabled="busy"><option value="">اختر النتيجة المثبتة</option><option value="lost">مفقود</option><option value="damaged">تالف</option><option value="disposed">أُتلف بإذن الإدارة</option></select></label>
      <p v-if="action==='resolve_custody'">هذا حسم إداري لمسؤولية التوصيل بعد التحقق من الفقد أو التلف، دون إثبات عودة للمطعم. تبقى المراجعة المالية والمخزون في مساراتهما المعتمدة.</p>
      <label>سبب الإجراء<textarea v-model="reason" required minlength="3" maxlength="500" rows="3" :disabled="busy"/></label>
      <label class="ack"><input v-model="confirmed" type="checkbox" required :disabled="busy"/> {{ action==='confirm_return'?'أؤكد وصول الطلب واستلامه فعليًا في المطعم.':action==='reassign'?'أؤكد نقل عهدة الطلب فعليًا إلى المندوب المختار.':action==='release'?'أؤكد أن المندوب لم يستلم الطلب من المطعم.':'راجعت الحالة وأؤكد تنفيذ هذا الإجراء مع حفظ السبب.' }}</label>
      <p>هذا الإجراء لا يؤكد دفعًا أو تسليمًا للعميل، ولا يعيد المخزون تلقائيًا.</p><button class="button" :disabled="busy||!confirmed">{{ busy?'جارٍ التنفيذ…':exceptionActionLabels[action] }}</button>
    </template></form><p v-else>لا توجد إجراءات استثنائية متاحة لحسابك في الحالة الحالية.</p>
  <p v-if="notice" role="status">{{ notice }}</p><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div>
</section></template>
<style scoped>.delivery-exception-actions{min-width:0}.delivery-exception-actions form{display:grid;gap:14px;max-width:560px}.delivery-exception-actions label{display:grid;gap:8px}.delivery-exception-actions select,.delivery-exception-actions textarea{width:100%;padding:10px;box-sizing:border-box;border:1px solid var(--line);border-radius:8px;font:inherit;min-height:44px}.delivery-exception-actions .ack{display:flex;align-items:start}.delivery-exception-actions p{overflow-wrap:anywhere}</style>
