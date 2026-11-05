import assert from 'node:assert/strict'
import { test } from 'node:test'
import { totalAmounts, setFinanceTimezone, today } from '../src/finance/utils.js'
import { auditDetails, auditDateTime } from '../src/finance/auditPresentation.js'
import { revenueSummary } from '../src/finance/revenueSummary.js'

const summaryRecord = () => ({ date: '2026-10-25', status: 'approved', total: '5055.00', branch: { name: 'الفرع الرئيسي' }, lines: [['شبكة', '3238.00'], ['كاش', '1020.00'], ['تحويل', '777.00'], ['كيتا', '20.00'], ['جاهز', '0.00']].map(([name, amount]) => ({ source: { name }, amount })) })
test('copied daily revenue matches the old work group message and omits zero channels', () => {
  assert.equal(revenueSummary(summaryRecord()), 'حساب اليوم 25/10/2026\nشبكة 3238\nكاش 1020\nتحويل 777\nكيتا 20\nالمجموع 5055')
})
test('copied revenue preserves cents and large amounts without floating point rounding', () => {
  const record = summaryRecord(); record.total = '99999999999999.99'; record.lines = [{ source: { name: 'مصدر جديد' }, amount: '150.50' }, { source: { name: 'كاش' }, amount: '0.75' }]
  assert.match(revenueSummary(record), /مصدر جديد 150\.50\nكاش 0\.75\nالمجموع 99999999999999\.99$/)
})
test('draft and branch context are explicit and internal notes are never copied', () => {
  const record = { ...summaryRecord(), status: 'draft', notes: 'private note', uuid: 'private-id' }
  const text = revenueSummary(record, { includeBranch: true })
  assert.match(text, /الفرع: الفرع الرئيسي/); assert.match(text, /الحالة: مسودة/)
  assert.equal(text.includes('private'), false)
})
test('copied source labels cannot inject additional message lines', () => {
  const record = summaryRecord(); record.lines[0].source.name = 'شبكة\nالمجموع\tآخر'
  assert.match(revenueSummary(record), /شبكة المجموع آخر 3238/)
})
test('invalid financial data does not produce a misleading copied summary', () => {
  for (const amount of ['-1', '', null, '1e3', '1.001']) assert.throws(() => revenueSummary({ ...summaryRecord(), total: amount }))
  assert.throws(() => revenueSummary({ ...summaryRecord(), date: null }))
})

test('audit creation presents business fields in Arabic and hides technical metadata', () => {
  const result = auditDetails({ old_values: [], new_values: { uuid: 'private-uuid', version: 1, created_at: '2026-11-03T15:05:09Z', amount: '74.00', description: 'شراء غاز للمحل', payment_method: 'cash', attachment_changed: false, unexpected_internal: { secret: 'hidden' } } })
  assert.equal(result.comparison, false)
  assert.deepEqual(result.fields.map(field => field.label), ['المبلغ', 'الوصف', 'طريقة الدفع', 'المرفق'])
  assert.match(result.fields[0].after, /٧٤/)
  assert.equal(result.fields.find(field => field.key === 'payment_method').after, 'نقدًا')
  assert.equal(result.fields.find(field => field.key === 'attachment_changed').after, 'لم يُرفق ملف جديد')
  assert.equal(JSON.stringify(result).includes('private-uuid'), false)
  assert.equal(JSON.stringify(result).includes('hidden'), false)
})

test('audit approval and correction show only changed values including explicit zero', () => {
  const result = auditDetails({ old_values: { date: '2026-11-03', total: '0.00', status: 'draft', version: 1 }, new_values: { date: '2026-11-03', total: '150.30', status: 'approved', version: 2 } })
  assert.equal(result.comparison, true)
  assert.deepEqual(result.fields.map(field => field.key), ['total', 'status'])
  assert.match(result.fields[0].before, /٠/)
  assert.equal(result.fields[1].before, 'مسودة')
  assert.equal(result.fields[1].after, 'معتمد')
})

test('audit source amounts, roles and report filters resolve names instead of JSON identifiers', () => {
  const refs = { sources: [{ uuid: 'source-id', name: 'شبكة' }], branches: [{ uuid: 'branch-id', name: 'الفرع الرئيسي' }] }
  const result = auditDetails({ old_values: [], new_values: { role: 'finance_accountant', branch_uuids: ['branch-id'], lines: [{ source_uuid: 'source-id', amount: '0.75' }], format: 'xlsx', filters: { from: '2026-11-01', to: '2026-11-03', branch_uuid: 'branch-id', source_uuid: null, group_by: 'month' } } }, refs)
  assert.equal(result.fields.find(field => field.key === 'role').after, 'محاسب')
  assert.equal(result.fields.find(field => field.key === 'branch_uuids').after, 'الفرع الرئيسي')
  assert.equal(result.fields.find(field => field.label === 'شبكة').after.includes('٠٫٧٥'), true)
  assert.equal(result.fields.find(field => field.key === 'filter:source_uuid').after, 'جميع المصادر')
  assert.equal(result.fields.find(field => field.key === 'filter:group').after, 'شهري')
  assert.equal(result.fields.find(field => field.key === 'format').after, 'Excel')
  assert.equal(auditDateTime('not-a-date'), 'غير مسجل')
})

test('revenue inputs add decimal fractions exactly', () => {
  assert.equal(totalAmounts([{ amount: '0.10' }, { amount: '0.20' }]), '0.30')
  assert.equal(totalAmounts([{ amount: '100' }, { amount: '0.5' }]), '100.50')
})
test('large revenue totals preserve every cent beyond Number safe integer precision', () => {
  assert.equal(totalAmounts(Array.from({ length: 100 }, () => ({ amount: '999999999999.99' }))), '99999999999999.00')
})
test('invalid inputs cannot produce a valid displayed total', () => {
  for (const amount of ['-1', '1.001', '1e3', 'NaN', '1000000000000']) assert.equal(totalAmounts([{ amount }]), null)
})

test('blank revenue channels contribute zero and ordinary decimals retain exact values', () => {
  assert.equal(totalAmounts([{ amount: '' }]), '0.00')
  assert.equal(totalAmounts([{ amount: '150' }, { amount: '' }, { amount: null }]), '150.00')
  assert.equal(totalAmounts([{ amount: '100' }, { amount: '200' }, { amount: '' }]), '300.00')
  assert.equal(totalAmounts([{ amount: '0' }]), '0.00')
  for (const [amount, expected] of [['5', '5.00'], ['15', '15.00'], ['150', '150.00'], ['150.50', '150.50'], ['0.75', '0.75']]) {
    assert.equal(totalAmounts([{ amount }]), expected)
  }
})
test('financial date follows the configured timezone and uses a stable date contract', () => {
  setFinanceTimezone('Asia/Riyadh')
  assert.match(today(), /^\d{4}-\d{2}-\d{2}$/)
})
