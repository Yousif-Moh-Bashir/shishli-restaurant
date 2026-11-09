import assert from 'node:assert/strict'
import { test } from 'node:test'
import { normalizeApiError } from '../src/utils/apiError.js'

const invalid = errors => normalizeApiError({ response: { status: 422, data: { success: false, message: 'البيانات المدخلة غير صحيحة', errors } } })
test('duplicate technical unique messages become one readable message per field', () => {
  const result = invalid({ phone: ['validation.unique', 'validation.unique'], email: ['validation.unique'] })
  assert.deepEqual(result.errors.phone, ['رقم الجوال مستخدم بالفعل.'])
  assert.deepEqual(result.errors.email, ['البريد الإلكتروني مستخدم بالفعل.'])
})
test('untranslated length validation is readable without inventing a minimum', () => {
  const result = invalid({ password: ['validation.min.string'], name: ['validation.max.string'] })
  assert.match(result.errors.password[0], /كلمة المرور أقصر من الحد المطلوب/)
  assert.match(result.errors.name[0], /الاسم يتجاوز الحد الأقصى/)
  assert.equal(JSON.stringify(result).includes('validation.'), false)
})
test('backend Arabic messages retain exact limits and are not repeated', () => {
  const text = 'كلمة المرور يجب ألا تقل عن 12 حرفًا.'
  assert.deepEqual(invalid({ password: [text, text] }).errors.password, [text])
})
test('unknown validation keys and untranslated summary cannot leak technical names', () => {
  const result = normalizeApiError({ response: { status: 422, data: { success: false, message: 'validation.unique', errors: { internal_field: ['validation.custom_unknown'], roles: ['validation.required'], email: ['validation.email'], password: ['validation.password.mixed', 'validation.password.numbers'] } } } })
  assert.equal(JSON.stringify(result).includes('validation.'), false)
  assert.equal(JSON.stringify(result).includes('راجع قيمة internal_field'), false)
  assert.match(result.errors.roles[0], /الأدوار الإدارية مطلوب/)
  assert.equal(result.message, 'راجع البيانات المدخلة في الحقول الموضحة.')
})
test('structured business messages survive and errors without text remain hidden', () => {
  const result = invalid({ availability: [{ code: 'CLOSED', message: 'الفرع مغلق الآن.' }, { trace: 'private' }, null] })
  assert.deepEqual(result.errors.availability, ['الفرع مغلق الآن.'])
  assert.equal(JSON.stringify(result).includes('private'), false)
})
test('server failure never displays response traces or internal errors', () => {
  const result = normalizeApiError({ response: { status: 500, data: { success: false, message: 'private stack trace', errors: { database: ['private'] } } } })
  assert.deepEqual(result.errors, {})
  assert.equal(result.message, 'حدث خطأ في الخادم. حاول لاحقًا.')
})
