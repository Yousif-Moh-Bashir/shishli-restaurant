<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { financeApi } from '../api'
import { useFinanceSession } from '../session'
import { errorMessage } from '../utils'
import FinanceModal from '../components/FinanceModal.vue'
import FieldErrors from '../components/FieldErrors.vue'
const props = defineProps({ kind: String }), session = useFinanceSession(), current = ref(props.kind), rows = ref([]), loading = ref(false), busy = ref(false), modal = ref(false), selected = ref(null), error = ref(''), errors = ref({}), notice = ref(''), form = reactive({})
const supplier = computed(() => current.value === 'suppliers')
const section = computed(() => current.value === 'sources'
  ? { title: 'مصادر الإيراد', singular: 'مصدر إيراد', icon: 'wallet', description: 'وسائل التحصيل التي تظهر عند تسجيل الإيرادات اليومية.' }
  : { title: 'تصنيفات المصروفات', singular: 'تصنيف مصروف', icon: 'tags', description: 'نظّم المصروفات في تصنيفات واضحة لتسهيل مراجعتها.' })
const activeCount = computed(() => rows.value.filter(row => row.is_active).length)
const canSave = computed(() => session.can(supplier.value ? `suppliers.${selected.value ? 'edit' : 'create'}` : 'settings.manage'))
async function load() { loading.value = true; error.value = ''; try { rows.value = await financeApi.settings(current.value) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false } }
function open(row = null) { selected.value = row; error.value = ''; errors.value = {}; Object.assign(form, { name: row?.name || '', is_active: row?.is_active ?? true, phone: row?.phone || '', tax_number: row?.tax_number || '', notes: row?.notes || '' }); modal.value = true }
async function save() {
  if (busy.value) return
  busy.value = true; error.value = ''; errors.value = {}
  try { await financeApi.saveSetting(current.value, supplier.value ? { ...form } : { name: form.name, is_active: form.is_active }, selected.value?.uuid); modal.value = false; notice.value = 'تم حفظ البيانات.'; await session.load(); await load() }
  catch (e) { error.value = errorMessage(e); errors.value = e.response?.data?.errors || {} }
  finally { busy.value = false }
}
onMounted(load)
</script>
<template>
  <div :class="{ 'finance-settings-page': !supplier }">
    <div class="page-intro">
      <div><h2>{{ supplier ? 'شركاء التوريد' : 'إعدادات تناسب عملك' }}</h2><p class="muted">{{ supplier ? 'بيانات الموردين متاحة لفواتير المشتريات.' : 'نظّم مصادر إيراداتك وتصنيفات مصروفاتك بما يناسب العمل اليومي.' }}</p></div>
      <button v-if="session.can(supplier ? 'suppliers.create' : 'settings.manage')" class="primary" @click="open()"><FinanceIcon name="plus" />{{ supplier ? 'إضافة مورد' : `إضافة ${section.singular}` }}</button>
    </div>
    <nav v-if="!supplier" class="tabs finance-settings-tabs" aria-label="أقسام الإعدادات">
      <button :class="{ active: current === 'sources' }" :aria-pressed="current === 'sources'" @click="current = 'sources'; load()"><FinanceIcon name="wallet" /><span>مصادر الإيراد</span></button>
      <button :class="{ active: current === 'categories' }" :aria-pressed="current === 'categories'" @click="current = 'categories'; load()"><FinanceIcon name="tags" /><span>تصنيفات المصروفات</span></button>
    </nav>
    <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    <p v-if="error && !modal" class="notice danger" role="alert">{{ error }} <button @click="load">إعادة المحاولة</button></p>
    <section class="card" :aria-busy="loading">
      <div v-if="!supplier" class="finance-settings-heading">
        <div class="finance-settings-heading-title"><span class="finance-settings-symbol"><FinanceIcon :name="section.icon" /></span><div><h3>{{ section.title }}</h3><p class="muted">{{ section.description }}</p></div></div>
        <div v-if="!loading && !error" class="finance-settings-counts" aria-label="ملخص العناصر"><span><b>{{ rows.length }}</b> إجمالي</span><span><i class="finance-settings-active-dot" /><b>{{ activeCount }}</b> نشط</span><span><b>{{ rows.length - activeCount }}</b> معطّل</span></div>
      </div>
      <div v-if="loading" class="loading" role="status">جارٍ التحميل…</div>
      <div v-else-if="!rows.length && !error" class="empty"><FinanceIcon :name="supplier ? 'truck' : section.icon" /><p>لا توجد عناصر بعد. أضف أول عنصر للبدء.</p><button v-if="session.can(supplier ? 'suppliers.create' : 'settings.manage')" @click="open()"><FinanceIcon name="plus" />{{ supplier ? 'إضافة مورد' : `إضافة ${section.singular}` }}</button></div>
      <div v-else-if="!error" class="table-wrap finance-responsive-wrap">
        <table class="finance-responsive-table" role="table">
          <thead role="rowgroup"><tr role="row"><th role="columnheader">الاسم</th><th v-if="supplier" role="columnheader">الهاتف</th><th v-if="supplier" role="columnheader">الرقم الضريبي</th><th role="columnheader">الحالة</th><th role="columnheader">الإجراء</th></tr></thead>
          <tbody role="rowgroup"><tr v-for="row in rows" :key="row.uuid" role="row">
            <td role="cell"><span class="finance-cell-label" aria-hidden="true">الاسم</span><div class="finance-cell-value"><strong>{{ row.name }}</strong><small v-if="supplier">{{ row.notes }}</small></div></td>
            <td v-if="supplier" role="cell"><span class="finance-cell-label" aria-hidden="true">الهاتف</span><div class="finance-cell-value">{{ row.phone || '—' }}</div></td>
            <td v-if="supplier" role="cell"><span class="finance-cell-label" aria-hidden="true">الرقم الضريبي</span><div class="finance-cell-value">{{ row.tax_number || '—' }}</div></td>
            <td role="cell"><span class="finance-cell-label" aria-hidden="true">الحالة</span><div class="finance-cell-value"><span class="badge" :class="row.is_active ? 'approved' : 'draft'">{{ row.is_active ? 'نشط' : 'معطّل' }}</span></div></td>
            <td class="finance-cell-actions" role="cell"><span class="finance-cell-label" aria-hidden="true">الإجراء</span><div class="finance-cell-value"><button @click="open(row)"><FinanceIcon :name="session.can(supplier ? 'suppliers.edit' : 'settings.manage') ? 'pencil' : 'search'" />{{ session.can(supplier ? 'suppliers.edit' : 'settings.manage') ? 'تعديل' : 'عرض' }}</button></div></td>
          </tr></tbody>
        </table>
      </div>
    </section>
    <p class="muted section-note" :class="{ 'finance-settings-note': !supplier }"><FinanceIcon v-if="!supplier" name="shield-check" />تعطيل العنصر يمنع اختياره في السجلات الجديدة ويحافظ على السجلات السابقة.</p>
    <FinanceModal v-if="modal" :title="supplier ? 'بيانات المورد' : current === 'sources' ? 'مصدر الإيراد' : 'تصنيف المصروف'" :busy="busy" @close="modal = false">
      <form @submit.prevent="save">
        <div class="modal-body">
          <p v-if="error" class="notice danger" role="alert">{{ error }}</p><FieldErrors :errors="errors" />
          <label>الاسم<input v-model="form.name" required maxlength="120" :disabled="!canSave"></label>
          <template v-if="supplier"><label>الهاتف<input v-model="form.phone" maxlength="40" type="tel" :disabled="!canSave"></label><label>الرقم الضريبي<input v-model="form.tax_number" maxlength="40" :disabled="!canSave"></label><label>ملاحظات<textarea v-model="form.notes" rows="3" maxlength="5000" :disabled="!canSave" /></label></template>
          <div :class="{ 'finance-settings-availability': !supplier }"><label class="check"><input v-model="form.is_active" type="checkbox" :disabled="!canSave">نشط ومتاح للاستخدام</label><p v-if="!supplier" class="muted">عند التعطيل، تبقى السجلات السابقة محفوظة، ولن يظهر العنصر في السجلات الجديدة.</p></div>
        </div>
        <div class="modal-actions"><button type="button" :disabled="busy" @click="modal = false"><FinanceIcon name="x" />إغلاق</button><button v-if="canSave" class="primary" :disabled="busy"><FinanceIcon name="badge-check" />{{ busy ? 'جارٍ الحفظ…' : 'حفظ' }}</button></div>
      </form>
    </FinanceModal>
  </div>
</template>
