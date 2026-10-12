import test from 'node:test'
import assert from 'node:assert/strict'
import { localPhone, internationalPhone, phoneRequest } from '../src/utils/phone.js'

test('Saudi mobile inputs accept local, international, pasted and Arabic digits', () => {
  for (const value of ['0551234567', '+966551234567', '966551234567', '00966551234567', '٠٥٥١٢٣٤٥٦٧', '۰۵۵۱۲۳۴۵۶۷', '055 123-4567']) {
    assert.equal(localPhone(value), '0551234567')
    assert.equal(internationalPhone(value), '+966551234567')
  }
  assert.equal(localPhone(''), '')
  assert.equal(internationalPhone('+14155552671'), '+14155552671')
  assert.equal(internationalPhone('05123'), '05123')
  assert.equal(internationalPhone('bad number'), 'badnumber')
})

test('API conversion covers account, order, address, driver, branch and settings phone fields', () => {
  const source = { phone: '0551234567', customer: { phone: '٠٥٥١٢٣٤٥٦٧' }, address: { phone: '0551234567' }, whatsapp: '0551234567', settings: { 'restaurant.phone': '0551234567', 'restaurant.whatsapp': '0551234567' }, records: [{ phone: '0551234567' }], password: '055 1234567', notes: 'call 0551234567', email: '0551234567@example.test', nullable: { phone: null }, other: { value: '0551234567' } }
  const result = phoneRequest(source)
  for (const value of [result.phone, result.customer.phone, result.address.phone, result.whatsapp, result.settings['restaurant.phone'], result.settings['restaurant.whatsapp'], result.records[0].phone]) assert.equal(value, '+966551234567')
  assert.equal(source.phone, '0551234567')
  for (const key of ['password', 'notes', 'email']) assert.equal(result[key], source[key])
  assert.equal(result.nullable.phone, null)
  assert.equal(result.other.value, '0551234567')
  assert.deepEqual(phoneRequest(result), result)
  const files = new FormData()
  assert.equal(phoneRequest(files), files)
})
