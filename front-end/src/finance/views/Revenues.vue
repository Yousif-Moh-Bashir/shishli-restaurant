<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { financeApi } from '../api'
import { useFinanceSession } from '../session'
import { currency, errorMessage, today, totalAmounts, normalizeAmountInput } from '../utils'
import FinanceFilters from '../components/FinanceFilters.vue'
import FinanceModal from '../components/FinanceModal.vue'
import FieldErrors from '../components/FieldErrors.vue'
import Pagination from '../components/Pagination.vue'
import FinanceRevenueCopy from '../components/FinanceRevenueCopy.vue'
const session = useFinanceSession(), rows = ref([]), meta = ref(null), loading = ref(false), busy = ref(false), error = ref(''), notice = ref(''), errors = ref({}), modal = ref(false), correction = ref(false), selected = ref(null), approval = ref(null)
const filters = ref({ from: '', to: '', branch_uuid: '', source_uuid: '', status: '' })
const form = reactive({ branch_uuid: '', date: today(), notes: '', reason: '', version: 1, lines: [] })
const total = computed(() => totalAmounts(form.lines))
const editable = computed(() => !selected.value ? session.can('revenues.create') : correction.value ? session.can('revenues.correct') : selected.value.status === 'draft' && session.can('revenues.edit'))
const visibleSources = computed(() => session.sources.filter(s => s.is_active || form.lines.some(l => l.source_uuid === s.uuid)))
let loadId = 0
async function load(page = 1) {
  const id = ++loadId; loading.value = true; error.value = ''
  try { const result = await financeApi.list('revenues', { ...filters.value, page }); if (id === loadId) { rows.value = result.data; meta.value = result.meta } }
  catch (e) { if (id === loadId) error.value = errorMessage(e) }
  finally { if (id === loadId) loading.value = false }
}
function open(row = null, correct = false) {
  selected.value = row; correction.value = correct; errors.value = {}; error.value = ''; notice.value = ''
  Object.assign(form, { branch_uuid: row?.branch?.uuid || session.branches[0]?.uuid || '', date: row?.date || today(), notes: row?.notes || '', reason: '', version: row?.version || 1,
    lines: row ? row.lines.map(l => ({ source_uuid: l.source.uuid, amount: l.amount })) : session.sources.filter(s => s.is_active).map(s => ({ source_uuid: s.uuid, amount: '' })) })
  modal.value = true
}
function sourceAmount(uuid) { return form.lines.find(l => l.source_uuid === uuid) }
function setAmount(uuid, value) { const line = sourceAmount(uuid); if (line) line.amount = value; else form.lines.push({ source_uuid: uuid, amount: value }) }
async function save() {
  if (busy.value) return
  if (form.date > today()) { errors.value = { date: ['لا يمكن تسجيل إيرادات بتاريخ مستقبلي. اختر اليوم أو تاريخًا سابقًا.'] }; error.value = 'راجع تاريخ الإيرادات.'; return }
  if (total.value === null || total.value === '0.00') { error.value = total.value === null ? 'أدخل مبالغ صحيحة غير سالبة بمنزلتين عشريتين كحد أقصى.' : 'أدخل مبلغ إيراد موجبًا في وسيلة واحدة على الأقل.'; return }
  busy.value = true; errors.value = {}; error.value = ''
  try { await financeApi.saveRevenue({ ...form }, selected.value?.uuid, correction.value); modal.value = false; notice.value = correction.value ? 'تم حفظ التصحيح وتوثيق سببه.' : 'تم حفظ مسودة الإيرادات.'; await load(meta.value?.current_page || 1) }
  catch (e) { error.value = errorMessage(e); errors.value = e.response?.data?.errors || {} }
  finally { busy.value = false }
}
async function approve() {
  if (busy.value) return
  busy.value = true; error.value = ''
  try { await financeApi.approve(approval.value.uuid, approval.value.version); approval.value = null; notice.value = 'تم اعتماد الإقفال.'; await load(meta.value?.current_page || 1) }
  catch (e) { error.value = errorMessage(e) }
  finally { busy.value = false }
}
onMounted(load)
</script>
<template><div class="page-intro"><div><h2>كل يوم، بصورة مكتملة</h2><p class="muted">سجّل الإيرادات حسب المصدر، وراجع الإجمالي قبل اعتماد الإقفال.</p></div><button class="primary" v-if="session.can('revenues.create')" @click="open()"><FinanceIcon name="plus" /> تسجيل يوم جديد</button></div>
<FinanceFilters v-model="filters" sources statuses title="تصفية الإيرادات" @apply="load()"/>
<p class="notice success" role="status" v-if="notice">{{ notice }}</p><p class="notice danger" role="alert" v-if="error && !modal && !approval">{{ error }} <button @click="load()">إعادة المحاولة</button></p>
<section class="card finance-revenue-list" :aria-busy="loading"><div v-if="loading" class="loading" role="status">جارٍ تحميل الإيرادات…</div><div v-else-if="!rows.length" class="empty"><FinanceIcon name="wallet" /><h3>لا توجد سجلات لهذه الفترة</h3><p>غيّر الفلاتر أو ابدأ بتسجيل إيرادات اليوم.</p></div><div v-else class="table-wrap revenue-table-wrap"><table class="revenue-table" role="table" aria-label="الإيرادات اليومية"><thead role="rowgroup"><tr role="row"><th role="columnheader">التاريخ / الفرع</th><th role="columnheader">المصادر</th><th role="columnheader">الإجمالي</th><th role="columnheader">الموظف</th><th role="columnheader">الإقفال</th><th role="columnheader">الإجراءات</th></tr></thead><tbody role="rowgroup"><tr v-for="row in rows" :key="row.uuid" role="row">
<td class="revenue-date-cell" role="cell"><strong><time :datetime="row.date">{{ row.date }}</time></strong><small>{{ row.branch?.name }}</small></td>
<td class="revenue-sources-cell" role="cell"><span class="revenue-mobile-label" aria-hidden="true">حسب مصدر الإيراد</span><div class="revenue-source-list"><small v-for="line in row.lines" :key="line.source.uuid"><span>{{ line.source.name }}</span><b>{{ currency(line.amount) }}</b></small></div></td>
<td class="money revenue-total-cell" role="cell"><span class="revenue-mobile-label" aria-hidden="true">إجمالي الإيرادات</span><strong>{{ currency(row.total) }}</strong></td>
<td class="revenue-creator-cell" role="cell"><span class="revenue-mobile-label" aria-hidden="true">الموظف</span><span>{{ row.creator?.name }}</span></td>
<td class="revenue-status-cell" role="cell"><span class="badge" :class="row.status">{{ row.status === 'approved' ? 'معتمد' : 'مسودة' }}</span><small v-if="row.approver">{{ row.approver.name }}<br>{{ row.approved_at }}</small></td>
<td class="revenue-actions-cell" role="cell"><div class="actions"><button @click="open(row)"><FinanceIcon name="pencil" />{{ row.status === 'draft' && session.can('revenues.edit') ? 'عرض وتعديل' : 'عرض' }}</button><FinanceRevenueCopy :record="row" :include-branch="session.branches.length > 1" /><button v-if="row.status === 'draft' && session.can('revenues.approve')" @click="approval = row; error = ''"><FinanceIcon name="shield-check" />اعتماد</button><button v-if="row.status === 'approved' && session.can('revenues.correct')" @click="open(row, true)"><FinanceIcon name="pencil" />تصحيح موثق</button></div></td>
</tr></tbody></table></div><Pagination :meta="meta" :busy="loading" @page="load"/></section>
<FinanceModal v-if="modal" :busy="busy" :title="correction ? 'تصحيح إقفال معتمد' : selected ? 'سجل الإيرادات اليومية' : 'تسجيل إيرادات يوم جديد'" @close="modal = false"><form @submit.prevent="save"><div class="modal-body"><p class="notice danger" role="alert" v-if="error">{{ error }}</p><FieldErrors :errors="errors"/><div class="form-grid"><label>التاريخ<input type="date" v-model="form.date" :max="today()" :disabled="!!selected || !editable" required></label><label>الفرع<select v-model="form.branch_uuid" required :disabled="!!selected || !editable"><option v-for="b in session.branches" :key="b.uuid" :value="b.uuid">{{ b.name }}</option></select></label></div><p class="muted">الوسائل الفارغة تُحسب صفرًا. أدخل مبلغًا موجبًا في وسيلة واحدة على الأقل.</p><div class="revenue-inputs"><label v-for="source in visibleSources" :key="source.uuid"><span>{{ source.name }} <small v-if="!source.is_active">(معطّل)</small></span><div class="amount-field"><input :value="sourceAmount(source.uuid)?.amount ?? ''" placeholder="0.00" @input="setAmount(source.uuid, normalizeAmountInput($event))" inputmode="decimal" type="text" title="أدخل مبلغًا غير سالب بمنزلتين عشريتين كحد أقصى، أو اترك الحقل فارغًا." pattern="[0-9]{1,12}(\.[0-9]{1,2})?" :disabled="!editable"><span>ر.س</span></div></label></div><div class="total-row"><span>إجمالي الإيرادات</span><strong>{{ total === null ? 'راجع المبالغ' : currency(total) }}</strong></div><label>ملاحظات<textarea v-model="form.notes" maxlength="5000" :disabled="!editable" rows="3"/></label><label v-if="correction">سبب التصحيح (إلزامي)<textarea v-model="form.reason" required minlength="5" maxlength="2000" rows="3"/></label></div><div class="modal-actions"><button type="button" :disabled="busy" @click="modal = false"><FinanceIcon name="x" />إغلاق</button><button v-if="editable" class="primary" :disabled="busy || !form.lines.length"><FinanceIcon name="badge-check" />{{ busy ? 'جارٍ الحفظ…' : correction ? 'حفظ التصحيح الموثق' : 'حفظ المسودة' }}</button></div></form></FinanceModal>
<FinanceModal v-if="approval" title="اعتماد الإقفال اليومي" :busy="busy" @close="approval = null"><div class="modal-body"><p>اعتماد إيرادات {{ approval.date }} لفرع {{ approval.branch?.name }} بقيمة <strong>{{ currency(approval.total) }}</strong>؟</p><p class="muted">سيصبح التعديل متاحًا فقط عبر إجراء تصحيح موثق.</p><p class="notice danger" v-if="error" role="alert">{{ error }}</p></div><div class="modal-actions"><button :disabled="busy" @click="approval = null"><FinanceIcon name="arrow-left" />رجوع</button><button class="primary" :disabled="busy" @click="approve">{{ busy ? 'جارٍ الاعتماد…' : 'تأكيد الاعتماد' }}</button></div></FinanceModal></template>
