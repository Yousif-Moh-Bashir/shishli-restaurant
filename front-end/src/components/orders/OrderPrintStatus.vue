<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { printingApi } from '../../api/printing.api'
import { useAuthStore } from '../../stores/auth'
import { normalizeApiError } from '../../utils/apiError'
import { formatDateTime } from '../../utils/formatters'

const props = defineProps({ order: { type: Object, required: true } })
const auth = useAuthStore()
const jobs = ref([]), loading = ref(false), busy = ref(false), error = ref(''), selected = ref(null), reason = ref(''), acknowledged = ref(false)
const emit = defineEmits(['active'])
defineExpose({ busy })
let controller, timer, generation = 0, operationKey
const labels = { pending: 'بانتظار الوكيل', leased: 'محجوزة للوكيل', executing: 'بدأ التنفيذ', spooler_accepted: 'استلمها نظام طباعة Windows — خروج الورق غير مؤكد', simulated: 'معاينة فقط — لم تُطبع', failed: 'فشلت', uncertain: 'النتيجة غير مؤكدة — افحص الورق قبل الإعادة', cancelled: 'أُلغيت قبل التنفيذ', review_required: 'متأخرة — تحتاج مراجعة' }
labels.not_submitted = 'لم تُرسل إلى الطابعة'
labels.lease_expired = 'انتهى الحجز قبل التنفيذ'
const errorLabels = { PRINTER_UNAVAILABLE: 'الطابعة غير متاحة', RENDER_FAILED: 'تعذر تجهيز النسخة', SPOOLER_FAILED: 'تعذر تأكيد الإرسال إلى Windows', AGENT_RESTART: 'أعيد تشغيل الوكيل', NETWORK_INTERRUPTED: 'انقطع الاتصال', LEASE_EXPIRED: 'انتهت مهلة الاتصال' }
const canReprint = computed(() => auth.can('printing.reprint') && props.order.status !== 'cancelled')
const retryable = job => ['spooler_accepted', 'simulated', 'failed', 'uncertain', 'review_required'].includes(job.status)
async function load() {
  const current = ++generation
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  try {
    const result = await printingApi.list(props.order.id, controller.signal)
    if (current === generation) { jobs.value = result; error.value = '' }
  } catch (cause) {
    if (current === generation && !controller.signal.aborted) error.value = normalizeApiError(cause).message
  } finally { if (current === generation) loading.value = false }
}
function choose(job) { selected.value = job; reason.value = ''; acknowledged.value = false; operationKey = crypto.randomUUID() }
async function reprint() {
  if (busy.value || !acknowledged.value || reason.value.trim().length < 3) return
  busy.value = true; emit('active', true); error.value = ''
  try {
    await printingApi.reprint(props.order.id, selected.value.uuid, { operation_key: operationKey, reason: reason.value.trim(), acknowledge_duplicate_risk: true })
    selected.value = null
    await load()
  } catch (cause) { error.value = normalizeApiError(cause).message }
  finally { busy.value = false; emit('active', false) }
}
watch(() => [props.order.id, auth.token, auth.can('printing.view')], () => {
  clearInterval(timer); controller?.abort(); jobs.value = []; selected.value = null; error.value = ''
  if (auth.can('printing.view')) {
    void load()
    timer = setInterval(() => { if (!busy.value && !selected.value && !document.hidden && !loading.value) void load() }, 10000)
  }
}, { immediate: true })
onBeforeUnmount(() => { ++generation; clearInterval(timer); controller?.abort() })
</script>

<template>
  <section v-if="auth.can('printing.view')" class="panel print-status" aria-label="حالة طباعة الطلب">
    <div class="print-heading"><h2>طباعة الطلب</h2><button class="row-button" :disabled="loading || busy" @click="load">تحديث</button></div>
    <p v-if="error" role="alert">{{ error }}</p>
    <p v-if="!jobs.length && !loading && !error">لا توجد مهام طباعة لهذا الطلب.</p>
    <article v-for="job in jobs" :key="job.uuid" class="print-job">
      <strong>{{ job.destination === 'kitchen' ? 'المطبخ' : 'الكاشير' }}{{ job.copy_key !== '00000000-0000-0000-0000-000000000000' ? ' — إعادة طباعة' : '' }}</strong>
      <p>{{ labels[job.status] || job.status }}</p>
      <details v-if="job.attempts?.length"><summary>المحاولات ({{ job.attempts.length }})</summary><p v-for="attempt in job.attempts" :key="attempt.uuid">{{ formatDateTime(attempt.created_at) }} · {{ labels[attempt.status] || attempt.status }} <span v-if="attempt.error_code">· {{ errorLabels[attempt.error_code] || 'تحتاج المحاولة إلى مراجعة' }}</span></p></details>
      <button v-if="canReprint && retryable(job)" class="row-button" :disabled="busy" @click="choose(job)">إعادة الطباعة</button>
    </article>
    <form v-if="selected && canReprint" class="print-confirm" @submit.prevent="reprint">
      <p>قد تكون النسخة السابقة خرجت بالفعل. تحقق من الورق قبل طلب نسخة إضافية.</p>
      <label>سبب الإعادة <input v-model="reason" required minlength="3" maxlength="250" :disabled="busy" /></label>
      <label><input v-model="acknowledged" type="checkbox" required :disabled="busy" /> تحققت وأوافق على احتمال خروج نسخة مكررة.</label>
      <button class="button" :disabled="busy || !acknowledged || reason.trim().length < 3">{{ busy ? 'جارٍ الإرسال…' : 'طلب نسخة إضافية' }}</button>
      <button type="button" class="row-button" :disabled="busy" @click="selected = null">إلغاء</button>
    </form>
  </section>
</template>

<style scoped>
.print-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.print-job{padding-block:14px;border-top:1px solid var(--line);overflow-wrap:anywhere}.print-job p{margin-block:8px}.print-confirm{display:grid;gap:14px;padding-top:16px;border-top:1px solid var(--line)}.print-confirm label{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.print-confirm input:not([type=checkbox]){width:100%;min-height:42px}.print-job button{margin-top:10px}
</style>
