import { currency } from './utils.js'

export const auditActions = {
  'revenues.create': 'تسجيل إيرادات', 'revenues.update': 'تعديل مسودة', 'revenues.approve': 'اعتماد إقفال', 'revenues.correct': 'تصحيح إقفال',
  'expenses.create': 'إضافة مصروف', 'expenses.update': 'تعديل مصروف', 'purchases.create': 'إضافة مشتريات', 'purchases.update': 'تعديل مشتريات',
  'users.create': 'إنشاء مستخدم', 'users.update': 'تعديل مستخدم', 'users.bootstrap_manager': 'منح صلاحية مدير مالي', 'reports.export': 'تصدير تقرير',
  'sources.create': 'إضافة مصدر', 'sources.update': 'تعديل مصدر', 'categories.create': 'إضافة تصنيف', 'categories.update': 'تعديل تصنيف',
  'suppliers.create': 'إضافة مورد', 'suppliers.update': 'تعديل مورد',
}
const roles = { finance_entry: 'موظف إدخال', finance_accountant: 'محاسب', finance_manager: 'مدير مالي', customer: 'عميل', manager: 'مدير مطعم', super_admin: 'مدير النظام', cashier: 'كاشير', kitchen: 'المطبخ', driver: 'مندوب' }
const methods = { cash: 'نقدًا', network: 'شبكة', transfer: 'تحويل بنكي' }
const statuses = { draft: 'مسودة', approved: 'معتمد' }
const empty = value => value === null || value === undefined || value === ''
const text = value => empty(value) ? 'غير مسجل' : typeof value === 'string' || typeof value === 'number' ? String(value) : 'غير متاح'
const money = value => empty(value) ? 'غير مسجل' : /^\d+(?:\.\d{1,2})?$/.test(String(value)) ? currency(value) : 'مبلغ غير متاح'
const flag = value => [true, 1, '1'].includes(value)
export function auditDateTime(value, timezone = 'Asia/Riyadh') {
  if (!value || !Number.isFinite(new Date(value).getTime())) return 'غير مسجل'
  return new Intl.DateTimeFormat('ar-SA-u-ca-gregory', { dateStyle: 'medium', timeStyle: 'short', timeZone: timezone }).format(new Date(value))
}
function reference(value, list, missing, fallback) {
  if (empty(value)) return missing
  return list?.find(item => item.uuid === value || (item.id !== undefined && String(item.id) === String(value)))?.name || fallback
}
function entries(snapshot, refs) {
  if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) return []
  const fields = []
  const add = (key, label, format = text) => {
    if (Object.hasOwn(snapshot, key)) fields.push({ key, label, raw: snapshot[key], value: format(snapshot[key]) })
  }
  add('date', 'التاريخ')
  add('name', 'الاسم'); add('email', 'البريد الإلكتروني'); add('phone', 'الهاتف'); add('tax_number', 'الرقم الضريبي')
  add('invoice_number', 'رقم الفاتورة'); add('amount', 'المبلغ', money); add('total', 'إجمالي الإيرادات', money)
  add('description', 'الوصف'); add('payment_method', 'طريقة الدفع', value => methods[value] || 'غير مسجلة')
  add('status', 'حالة الإقفال', value => statuses[value] || 'غير مسجلة')
  add('is_active', 'حالة التفعيل', value => flag(value) ? 'نشط' : 'معطّل')
  add('role', 'الدور المالي', value => roles[value] || 'دور آخر')
  add('roles', 'الأدوار', value => Array.isArray(value) && value.length ? value.map(role => roles[role] || 'دور آخر').join('، ') : 'لا توجد أدوار')
  add('branch_uuids', 'الفروع المسموحة', value => Array.isArray(value) && value.length ? value.map(id => reference(id, refs.branches, 'غير مسجل', 'فرع مسجل')).join('، ') : 'لا توجد فروع')
  add('branch_id', 'الفرع', value => reference(value, refs.branches, 'غير مسجل', 'فرع مسجل'))
  add('supplier_id', 'المورد', value => reference(value, refs.suppliers, 'شراء مباشر — بدون مورد', 'مورد مسجل'))
  add('category_id', 'التصنيف', value => reference(value, refs.categories, 'غير مسجل', 'تصنيف مسجل'))
  add('notes', 'ملاحظات')
  add('approved_at', 'وقت الاعتماد', value => auditDateTime(value, refs.timezone))
  add('attachment_changed', 'المرفق', value => flag(value) ? 'تم إرفاق ملف جديد' : 'لم يُرفق ملف جديد')
  add('format', 'صيغة التقرير', value => ({ pdf: 'PDF', xlsx: 'Excel' })[value] || 'غير مسجلة')
  if (Array.isArray(snapshot.lines)) snapshot.lines.forEach((line, index) => {
    if (!line || typeof line !== 'object') return
    const name = reference(line.source_uuid, refs.sources, `مصدر إيراد ${index + 1}`, `مصدر إيراد ${index + 1}`)
    fields.push({ key: `source:${line.source_uuid || index}`, label: name, raw: line.amount, value: money(line.amount) })
  })
  if (snapshot.filters && typeof snapshot.filters === 'object') {
    const filters = snapshot.filters
    for (const [key, label] of [['from', 'من تاريخ'], ['to', 'إلى تاريخ']]) {
      if (Object.hasOwn(filters, key)) fields.push({ key: `filter:${key}`, label, raw: filters[key], value: text(filters[key]) })
    }
    for (const [key, label, list, all, fallback] of [
      ['branch_uuid', 'فرع التقرير', refs.branches, 'جميع الفروع', 'فرع محدد'],
      ['source_uuid', 'مصدر التقرير', refs.sources, 'جميع المصادر', 'مصدر محدد'],
    ]) if (Object.hasOwn(filters, key)) fields.push({ key: `filter:${key}`, label, raw: filters[key], value: reference(filters[key], list, all, fallback) })
    if (filters.group_by) fields.push({ key: 'filter:group', label: 'عرض التقرير', raw: filters.group_by, value: ({ day: 'يومي', month: 'شهري', year: 'سنوي' })[filters.group_by] || 'غير مسجل' })
  }
  return fields
}
export function auditDetails(row, references = {}) {
  const old = entries(row.old_values, references), next = entries(row.new_values, references)
  const before = new Map(old.map(field => [field.key, field])), after = new Map(next.map(field => [field.key, field]))
  const keys = [...new Set([...next, ...old].map(field => field.key))]
  return {
    comparison: old.length > 0,
    fields: keys.filter(key => !old.length || JSON.stringify(before.get(key)?.raw) !== JSON.stringify(after.get(key)?.raw)).map(key => ({
      key, label: (after.get(key) || before.get(key)).label,
      before: before.get(key)?.value || 'غير مسجل', after: after.get(key)?.value || 'غير مسجل',
    })),
  }
}
