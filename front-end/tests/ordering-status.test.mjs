import assert from 'node:assert/strict'
import { test } from 'node:test'
import { orderingResumeMessage } from '../src/utils/orderingStatus.js'
import { zonedDateTimeInput } from '../src/utils/formDates.js'

test('resume estimate uses restaurant timezone rather than device timezone', () => {
  assert.equal(zonedDateTimeInput('2026-11-05T15:30:00Z', 'Asia/Riyadh'), '2026-11-05T18:30')
  assert.match(orderingResumeMessage('2026-11-05T15:30:00Z', 'Asia/Riyadh', Date.parse('2026-11-05T12:00:00Z')), /اليوم الساعة/)
})
test('tomorrow label follows restaurant midnight including year changes', () => {
  assert.match(orderingResumeMessage('2027-01-01T06:00:00Z', 'Asia/Riyadh', Date.parse('2026-12-31T20:00:00Z')), /غدًا/)
  assert.match(orderingResumeMessage('2026-11-06T06:00:00Z', 'Asia/Riyadh', Date.parse('2026-11-05T22:00:00Z')), /اليوم/)
})
test('later days include a full Gregorian date and clearly indicate an estimate', () => {
  const text = orderingResumeMessage('2026-11-10T15:30:00Z', 'Asia/Riyadh', Date.parse('2026-11-05T12:00:00Z'))
  assert.match(text, /سنعاود استقبال الطلبات/)
  assert.match(text, /نوفمبر/)
  assert.match(text, /موعد متوقع/)
})
test('passed and absent estimates never display an outdated reopening promise', () => {
  const now = Date.parse('2026-11-05T12:00:00Z')
  for (const value of [null, '', 'invalid', '2026-11-05T12:00:00Z', '2026-11-04T12:00:00Z']) assert.equal(orderingResumeMessage(value, 'Asia/Riyadh', now), '')
  assert.equal(orderingResumeMessage('2026-11-06T12:00:00Z', 'invalid/timezone', now), '')
  assert.equal(zonedDateTimeInput('invalid', 'Asia/Riyadh'), '')
})
