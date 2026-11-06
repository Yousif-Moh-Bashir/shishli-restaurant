<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { financeApi } from '../api'
import { useFinanceSession } from '../session'
import { currency, errorMessage, today, normalizeAmountInput } from '../utils'
import FinanceModal from '../components/FinanceModal.vue'
import FinanceFilters from '../components/FinanceFilters.vue'
import FieldErrors from '../components/FieldErrors.vue'
import Pagination from '../components/Pagination.vue'
import FinanceAttachment from '../components/FinanceAttachment.vue'
const props = defineProps({ kind: String })
const purchase = computed(() => props.kind === 'purchases'), session = useFinanceSession(), rows = ref([]), references = ref([]), meta = ref(null), loading = ref(false), busy = ref(false), modal = ref(false), selected = ref(null), error = ref(''), errors = ref({}), notice = ref('')
const filters = ref({ from: '', to: '', branch_uuid: '' }), form = reactive({}), attachment = ref(null)
const methods = { cash: 'كاش', network: 'شبكة', transfer: 'تحويل' }
const editable = computed(() => session.can(`${props.kind}.${selected.value ? 'edit' : 'create'}`))
async function load(page = 1) {
  loading.value = true; error.value = ''
  try { const result = await financeApi.list(props.kind, { ...filters.value, page }); rows.value = result.data; meta.value = result.meta }
  catch (e) { error.value = errorMessage(e) }
  finally { loading.value = false }
}
function open(row = null) {
  selected.value = row; attachment.value = null; errors.value = {}; error.value = ''; notice.value = ''
  Object.assign(form, { date: row?.date || today(), branch_uuid: row?.branch?.uuid || session.branches[0]?.uuid || '', amount: row?.amount ?? '', description: row?.description || '', payment_method: row?.payment_method || 'cash', reference_uuid: row?.[purchase.value ? 'supplier' : 'category']?.uuid || '', invoice_number: row?.invoice_number || '', version: row?.version || 1, reason: '' })
  modal.value = true
}
async function save() {
  if (busy.value) return
  busy.value = true; error.value = ''; errors.value = {}
  const data = new FormData()
  for (const field of ['date', 'branch_uuid', 'amount', 'description', 'payment_method', 'version', 'reason']) if (form[field] !== '' || (purchase.value && field === 'description')) data.append(field, form[field])
  data.append(purchase.value ? 'supplier_uuid' : 'category_uuid', form.reference_uuid)
  if (purchase.value) data.append('invoice_number', form.invoice_number)
  if (attachment.value) data.append('attachment', attachment.value)
  try { await financeApi.saveOutflow(props.kind, data, selected.value?.uuid); modal.value = false; notice.value = 'تم حفظ السجل وتوثيق العملية.'; await load(meta.value?.current_page || 1) }
  catch (e) { error.value = errorMessage(e); errors.value = e.response?.data?.errors || {} }
  finally { busy.value = false }
}
async function download(row) {
  if (busy.value) return
  busy.value = true
  try { await financeApi.download(`${props.kind}/${row.uuid}/attachment`, `attachment-${row.date}`) }
  catch (e) { error.value = errorMessage(e) }
  finally { busy.value = false }
}
onMounted(async () => {
  await load()
  try { references.value = purchase.value ? await financeApi.settings('suppliers') : session.categories }
  catch (e) { error.value = errorMessage(e) }
})
</script>
<template><div class="page-intro"><div><h2>{{ purchase ? 'مشتريات منظّمة، موردون واضحون' : 'تابع أين تُنفق أموالك' }}</h2><p class="muted">{{ purchase ? 'سجّل فواتير المشتريات المدفوعة هنا، دون تكرارها في المصروفات.' : 'سجّل المصروفات المدفوعة مع التصنيف وطريقة الدفع والمستند.' }}</p></div><button class="primary" v-if="session.can(`${kind}.create`)" @click="open()"><FinanceIcon name="plus" /> {{ purchase ? 'فاتورة مشتريات' : 'إضافة مصروف' }}</button></div><FinanceFilters v-model="filters" :title="purchase ? 'تصفية المشتريات' : 'تصفية المصروفات'" @apply="load()"/><p class="notice success" v-if="notice" role="status">{{ notice }}</p><p class="notice danger" v-if="error && !modal" role="alert">{{ error }} <button @click="load()">إعادة المحاولة</button></p><section class="card" :aria-busy="loading"><div class="loading" role="status" v-if="loading">جارٍ تحميل السجلات…</div><div v-else-if="!rows.length" class="empty"><FinanceIcon name="shopping-bag" /><h3>لا توجد سجلات</h3><p>ستظهر هنا العمليات المسجلة ضمن الفترة المختارة.</p></div><div v-else class="table-wrap finance-responsive-wrap"><table class="finance-responsive-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader">التاريخ / الفرع</th><th role="columnheader">{{ purchase ? 'المورد / الفاتورة' : 'التصنيف' }}</th><th role="columnheader">الوصف</th><th role="columnheader">المبلغ</th><th role="columnheader">الدفع</th><th role="columnheader">الموظف</th><th role="columnheader">الإجراءات</th></tr></thead><tbody role="rowgroup"><tr v-for="row in rows" :key="row.uuid" role="row"><td role="cell"><span class="finance-cell-label" aria-hidden="true">التاريخ / الفرع</span><div class="finance-cell-value">{{ row.date }}<small>{{ row.branch?.name }}</small></div></td><td role="cell"><span class="finance-cell-label" aria-hidden="true">{{ purchase ? 'المورد / الفاتورة' : 'التصنيف' }}</span><div class="finance-cell-value">{{ row[purchase ? 'supplier' : 'category']?.name || (purchase ? 'شراء مباشر — بدون مورد' : '—') }}<small v-if="purchase">{{ row.invoice_number }}</small></div></td><td class="description" role="cell"><span class="finance-cell-label" aria-hidden="true">الوصف</span><div class="finance-cell-value">{{ row.description }}</div></td><td class="finance-cell-amount money" role="cell"><span class="finance-cell-label" aria-hidden="true">المبلغ</span><div class="finance-cell-value">{{ currency(row.amount) }}</div></td><td role="cell"><span class="finance-cell-label" aria-hidden="true">الدفع</span><div class="finance-cell-value">{{ methods[row.payment_method] }}</div></td><td role="cell"><span class="finance-cell-label" aria-hidden="true">الموظف</span><div class="finance-cell-value">{{ row.creator?.name }}</div></td><td class="finance-cell-actions" role="cell"><span class="finance-cell-label" aria-hidden="true">الإجراءات</span><div class="finance-cell-value"><div class="actions"><button @click="open(row)"><FinanceIcon name="pencil" />{{ session.can(`${kind}.edit`) ? 'تعديل' : 'عرض' }}</button><button v-if="row.has_attachment" :disabled="busy" @click="download(row)"><FinanceIcon name="upload" />المرفق</button></div></div></td></tr></tbody></table></div><Pagination :meta="meta" :busy="loading" @page="load"/></section>
<FinanceModal v-if="modal" :title="purchase ? 'فاتورة مشتريات مدفوعة' : 'سجل مصروف'" :busy="busy" @close="modal = false"><form @submit.prevent="save"><div class="modal-body"><p v-if="error" class="notice danger" role="alert">{{ error }}</p><FieldErrors :errors="errors"/><div class="form-grid"><label>التاريخ<input v-model="form.date" type="date" :max="today()" required :disabled="!editable"></label><label>الفرع<select v-model="form.branch_uuid" required :disabled="!editable"><option v-for="b in session.branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option></select></label><label>{{ purchase ? 'المورد (اختياري)' : 'التصنيف' }}<select v-model="form.reference_uuid" :required="!purchase" :disabled="!editable"><option value="" :disabled="!purchase">{{ purchase ? 'شراء مباشر — بدون مورد' : 'اختر من القائمة' }}</option><option v-for="r in references.filter(r => r.is_active || r.uuid === form.reference_uuid)" :key="r.uuid" :value="r.uuid">{{ r.name }}{{ r.is_active ? '' : ' (معطّل)' }}</option></select></label><label>المبلغ (ر.س)<input :value="form.amount" @input="form.amount = normalizeAmountInput($event)" type="text" dir="ltr" placeholder="0.00" inputmode="decimal" pattern="[0-9]{1,12}(\.[0-9]{1,2})?" required :disabled="!editable"></label><label v-if="purchase">رقم الفاتورة (اختياري)<input v-model="form.invoice_number" maxlength="100" :disabled="!editable"></label><label>طريقة الدفع<select v-model="form.payment_method" required :disabled="!editable"><option v-for="(label, value) in methods" :value="value" :key="value">{{ label }}</option></select></label></div><label>الوصف{{ purchase ? ' (اختياري)' : '' }}<textarea v-model="form.description" :required="!purchase" rows="3" maxlength="5000" :disabled="!editable"/></label><FinanceAttachment v-if="editable && purchase" v-model="attachment" :busy="busy" :has-stored-attachment="!!selected?.has_attachment"/><label v-else-if="editable">المرفق الاختياري<input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="attachment = $event.target.files[0] || null"><small>PDF أو JPG أو PNG · حتى 10 ميغابايت. اختيار ملف جديد يستبدل المرفق الظاهر.</small></label><label v-if="selected && editable">سبب التعديل<textarea v-model="form.reason" required minlength="5" maxlength="2000" rows="2"/></label></div><div class="modal-actions"><button type="button" :disabled="busy" @click="modal = false"><FinanceIcon name="x" />إغلاق</button><button v-if="editable" class="primary" :disabled="busy"><FinanceIcon name="badge-check" />{{ busy ? 'جارٍ الحفظ…' : 'حفظ السجل' }}</button></div></form></FinanceModal></template>
