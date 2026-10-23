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
<template>
  <section v-if="order.type==='delivery'" class="delivery-exception-actions">
    <div class="exception-actions-heading"><span class="actions-icon" aria-hidden="true">!</span><div><h2>معالجة استثناء التوصيل</h2><p>راجع تفاصيل العهدة واختر الإجراء المناسب للحالة.</p></div></div>
    <div v-if="(order.cash_collections || []).length" class="exception-notices custody-notice">
      <strong>متابعة التحصيل والعهدة</strong>
      <div v-for="(entry,index) in order.cash_collections || []" :key="index" class="collection-row"><span>تحصيل {{ entry.amount }} {{ entry.currency }}</span><span>{{ entry.collector }}</span><span class="collection-state" :class="entry.settled_at?'is-settled':'is-pending'">{{ entry.settled_at ? 'سُلّم للكاشير' : 'في عهدة المندوب — بانتظار التسوية' }}</span></div>
    </div>
    <div v-if="order.delivery_exception?.block" class="exception-notices block-notice" role="status"><span aria-hidden="true">!</span><p>{{ order.delivery_exception.block.message }}</p></div>
    <div v-if="order.delivery_exception?.requested_at" class="exception-notices intervention-notice"><strong>طلب تدخل الإدارة</strong><p>{{ formatDateTime(order.delivery_exception.requested_at) }}<template v-if="order.delivery_exception.reason"> · {{ order.delivery_exception.reason }}</template></p></div>
    <div v-if="order.delivery_exception?.task_closed_at" class="exception-notices custody-notice"><strong>انتهت عهدة المهمة</strong><p>{{ formatDateTime(order.delivery_exception.task_closed_at) }}. أي عهدة نقدية تبقى على المندوب الذي حصّلها حتى تسويتها.</p></div>
    <div v-if="order.delivery_exception?.financial_review_required" class="exception-notices financial-notice" role="status"><span aria-hidden="true">↗</span><p>تحتاج الحالة مراجعة مالية من الموظف المخوّل. لم يُنفذ استرداد تلقائي.</p></div>

    <form v-if="available.length" class="exception-action-form" @submit.prevent="submit">
      <div class="form-title"><strong>الإجراء الإداري</strong><span>{{ available.length }} إجراءات متاحة</span></div>
      <label>الإجراء<select v-model="action" :disabled="busy" required><option value="">اختر الإجراء</option><option v-for="item in available" :key="item" :value="item">{{ exceptionActionLabels[item] }}</option></select></label>
      <template v-if="action">
        <template v-if="action==='reassign'">
          <label>المندوب المستلم للعهدة<select v-model="target" required :disabled="busy"><option value="">اختر المندوب</option><option v-for="driver in drivers" :key="driver.id" :value="driver.id">{{ driver.name }}</option></select></label>
          <div v-if="candidatesLast>1" class="candidate-pages"><button type="button" class="row-button" :disabled="busy||candidatesPage<=1" @click="candidates(candidatesPage-1)">السابق</button><span>{{ candidatesPage }} / {{ candidatesLast }}</span><button type="button" class="row-button" :disabled="busy||candidatesPage>=candidatesLast" @click="candidates(candidatesPage+1)">التالي</button></div>
        </template>
        <label v-if="action==='resolve_custody'">نتيجة التحقق من العهدة<select v-model="custody" required :disabled="busy"><option value="">اختر النتيجة المثبتة</option><option value="lost">مفقود</option><option value="damaged">تالف</option><option value="disposed">أُتلف بإذن الإدارة</option></select></label>
        <p v-if="action==='resolve_custody'" class="action-explanation">هذا حسم إداري لمسؤولية التوصيل بعد التحقق من الفقد أو التلف، دون إثبات عودة للمطعم. تبقى المراجعة المالية والمخزون في مساراتهما المعتمدة.</p>
        <label>سبب الإجراء<textarea v-model="reason" required minlength="3" maxlength="500" rows="3" :disabled="busy" placeholder="اكتب سبب الإجراء بالتفصيل"/></label>
        <label class="ack"><input v-model="confirmed" type="checkbox" required :disabled="busy"/> <span>{{ action==='confirm_return'?'أؤكد وصول الطلب واستلامه فعليًا في المطعم.':action==='reassign'?'أؤكد نقل عهدة الطلب فعليًا إلى المندوب المختار.':action==='release'?'أؤكد أن المندوب لم يستلم الطلب من المطعم.':'راجعت الحالة وأؤكد تنفيذ هذا الإجراء مع حفظ السبب.' }}</span></label>
        <div class="action-submit"><p>لا يؤكد هذا الإجراء دفعًا أو تسليمًا للعميل، ولا يعيد المخزون تلقائيًا.</p><button class="button" :disabled="busy||!confirmed">{{ busy?'جارٍ التنفيذ…':exceptionActionLabels[action] }}</button></div>
      </template>
    </form>
    <p v-else class="no-actions">لا توجد إجراءات استثنائية متاحة لحسابك في الحالة الحالية.</p>
    <p v-if="notice" class="action-result action-result--success" role="status">{{ notice }}</p>
    <div v-if="error" class="action-result action-result--error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ messages.join(' ') }}</p></div>
  </section>
</template>
<style scoped>
.delivery-exception-actions{min-width:0;padding-block:18px;color:#42151f}.exception-actions-heading{display:flex;align-items:center;gap:11px;margin-bottom:15px}.actions-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:#fff4e2;color:#96681f;font-size:18px;font-weight:800}.exception-actions-heading h2{margin:0;font-size:15px}.exception-actions-heading p{margin:2px 0 0;color:#806c70;font-size:11px;line-height:1.7}.exception-notices{display:flex;align-items:flex-start;gap:10px;padding:11px 13px;margin-bottom:9px;border:1px solid #eadfd8;border-radius:9px;background:#fffdfa;font-size:11px;line-height:1.8}.exception-notices p{margin:0;overflow-wrap:anywhere}.exception-notices>span{flex:0 0 auto;font-weight:800}.block-notice{border-color:#edc9c5;background:#fff7f5;color:#8d3033}.intervention-notice{display:block;border-color:#ead7b9;background:#fffaf0}.intervention-notice strong,.custody-notice>strong{display:block;margin-bottom:2px}.custody-notice{display:block;background:#f8f4ed}.financial-notice{border-color:#e6d8bb;background:#fbf7ec;color:#70521e}.collection-row{display:flex;align-items:center;flex-wrap:wrap;gap:4px 12px;padding-top:6px;margin-top:6px;border-top:1px solid #eadfd8}.collection-state{font-weight:700}.is-pending{color:#91651f}.is-settled{color:#4e7957}.exception-action-form{display:grid;gap:12px;max-width:680px;padding:16px;margin-top:16px;border:1px solid #eadfd8;border-radius:11px;background:#fcfaf7}.form-title{display:flex;align-items:center;justify-content:space-between;gap:10px;padding-bottom:10px;border-bottom:1px solid #eadfd8}.form-title strong{font-size:12px}.form-title span{color:#806c70;font-size:10px}.exception-action-form label{display:grid;gap:6px;font-size:11px;font-weight:700}.exception-action-form select,.exception-action-form textarea{width:100%;padding:10px 11px;box-sizing:border-box;border:1px solid #ded0c7;border-radius:8px;background:#fff;color:inherit;font:inherit;font-weight:400;min-height:44px}.exception-action-form select:focus,.exception-action-form textarea:focus{outline:2px solid #8f59684d;border-color:#8f5968}.exception-action-form textarea{resize:vertical;min-height:86px}.exception-action-form .ack{display:flex;align-items:flex-start;gap:9px;padding:11px;border:1px solid #eadfd8;border-radius:8px;background:#fff;font-weight:400;line-height:1.8}.exception-action-form .ack input{width:17px;height:17px;flex:0 0 17px;margin:3px 0 0;accent-color:#762536}.candidate-pages{display:flex;align-items:center;gap:9px;font-size:11px}.action-explanation{margin:0;padding:10px 12px;border-radius:8px;background:#f5efe5;color:#705c43;font-size:11px;line-height:1.9}.action-submit{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:12px;border-top:1px solid #eadfd8}.action-submit p,.no-actions{margin:0;color:#806c70;font-size:10px;line-height:1.8}.action-submit .button{min-height:42px}.no-actions{padding:11px 13px;border:1px dashed #ded0c7;border-radius:9px;background:#fcfaf7}.action-result{padding:11px 13px;margin:12px 0 0;border:1px solid;border-radius:8px;font-size:11px;line-height:1.8}.action-result p{margin:2px 0}.action-result--success{border-color:#c8dfcc;background:#f3faf4;color:#386846}.action-result--error{border-color:#e8c4c5;background:#fff7f7;color:#9e2332}
@media(max-width:560px){.delivery-exception-actions{padding-block:15px}.exception-action-form{padding:13px}.action-submit{align-items:stretch}.action-submit .button{width:100%}}
</style>
