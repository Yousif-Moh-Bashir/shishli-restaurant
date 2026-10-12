<script setup>
import { ref, watch, inject, computed } from 'vue'
import { adminDriversApi } from '../../api/drivers.api'
import { useAuthStore } from '../../stores/auth'
import { deliveryLabels, driverTime, driverError } from '../../constants/driverDelivery'
const props = defineProps({ order: Object, reload: Function })
const emit = defineEmits(['active'])
const auth = useAuthStore(), admin = inject('admin'), candidates = ref([]), target = ref(''), reason = ref(''), busy = ref(false), loading = ref(false), error = ref(null), opened = ref(false), candidatePage = ref(1), candidateLastPage = ref(1)
const canAssign = computed(() => auth.can('drivers.reassign') && props.order?.type === 'delivery' && !['completed','cancelled','pending','confirmed'].includes(props.order?.status))
async function loadCandidates(page = 1) {
  loading.value = true; error.value = null
  try { const result = await adminDriversApi.list({ branch_uuid: props.order.branch?.id, available: 1, per_page: 100, page }); candidates.value = result.data; candidatePage.value = page; candidateLastPage.value = result.meta.last_page }
  catch (cause) { error.value = driverError(cause) }
  finally { loading.value = false }
}
watch(() => props.order?.id, () => { opened.value = false; target.value = reason.value = ''; error.value = null })
async function open() { opened.value = !opened.value; if (opened.value) await loadCandidates() }
async function save() {
  if (busy.value || !target.value) return
  if (!await admin.confirmAction('تأكيد إسناد الطلب إلى المندوب المختار؟')) return
  busy.value = true; emit('active', true); error.value = null
  try { await adminDriversApi.reassign(props.order.id, { driver_uuid: target.value, reason: reason.value }); opened.value = false; admin.notify('تم إسناد الطلب.'); await props.reload?.() }
  catch (cause) { error.value = driverError(cause) }
  finally { busy.value = false; emit('active', false) }
}
</script>
<template><section v-if="order.type==='delivery'" class="driver-assignment"><h2>مندوب التوصيل</h2><p>{{ order.delivery?.driver?.name || 'لم يُسند الطلب إلى مندوب بعد.' }}<span v-if="order.delivery?.status"> · {{ deliveryLabels[order.delivery.status] }}</span></p><button v-if="canAssign" type="button" class="row-button" :disabled="busy" @click="open">{{ order.delivery?.driver?'إعادة إسناد الطلب':'إسناد لمندوب' }}</button><div v-if="opened" class="assignment-form"><label class="field">المندوب<select v-model="target" required :disabled="busy || loading"><option value="">اختر مندوبًا من الفرع</option><option v-for="driver in candidates.filter(item=>!item.current_order || item.current_order.id===order.id && order.delivery?.status==='failed')" :key="driver.id" :value="driver.id">{{ driver.name }}</option></select></label><p v-if="loading">جارٍ تحميل المناديب…</p><div v-if="candidateLastPage>1" class="form-actions"><button type="button" class="row-button" :disabled="loading || candidatePage<=1" @click="loadCandidates(candidatePage-1)">السابق</button><span>{{ candidatePage }} / {{ candidateLastPage }}</span><button type="button" class="row-button" :disabled="loading || candidatePage>=candidateLastPage" @click="loadCandidates(candidatePage+1)">التالي</button></div><label class="field">سبب الإسناد<textarea v-model="reason" required minlength="3" maxlength="1000" :disabled="busy" rows="2"/></label><button type="button" class="button" :disabled="busy || loading || !target || reason.trim().length<3" @click="save">تأكيد الإسناد</button></div><div v-if="error" role="alert"><p>{{ error.message }}</p><p v-for="(messages,key) in error.errors" :key="key">{{ Array.isArray(messages)?messages.join(' '):messages }}</p></div><details v-if="order.delivery?.history?.length"><summary>سجل التوصيل والإسناد</summary><article v-for="(entry,index) in order.delivery.history" :key="index"><p>{{ deliveryLabels[entry.to_status] }} · {{ entry.driver }} · {{ entry.actor }}</p><p v-if="entry.reason">{{ entry.reason }}</p><small>{{ driverTime(entry.at) }}</small></article></details></section></template>
<style scoped>.driver-assignment{margin-block:20px;padding-block:16px;border-top:1px solid var(--line)}.driver-assignment h2{font-size:16px}.assignment-form{display:grid;gap:14px;margin-top:18px;max-width:500px}.assignment-form .field{display:grid;gap:8px}.assignment-form select,.assignment-form textarea{width:100%;min-height:44px;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit;background:white}.driver-assignment details{margin-top:16px}.driver-assignment summary{cursor:pointer;min-height:40px}.driver-assignment details article{border-bottom:1px solid var(--line);padding:8px 0}.driver-assignment details p{margin:5px 0}</style>
