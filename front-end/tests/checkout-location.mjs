import assert from 'node:assert/strict'
import { reactive, createRenderer, nextTick } from 'vue'
import { addressForm } from '../src/constants/addressFields.js'
import { useAddressLocation } from '../src/composables/useAddressLocation.js'
import { validLocation } from '../src/utils/location.js'
import { normalizeGeocodingResult } from '../src/services/geocodingService.js'

const renderer = createRenderer({ createComment: () => ({}), insert() {}, remove() {}, parentNode: () => null, nextSibling: () => null })
globalThis.window = { isSecureContext: true, location: { origin: 'http://localhost' } }
let success, failure, calls = 0, fetchCalls = 0, resolveFetch
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { geolocation: { getCurrentPosition(ok, fail) { calls++; success = ok; failure = fail } } } })
globalThis.fetch = () => { fetchCalls++; return new Promise(resolve => { resolveFetch = resolve }) }
const response = city => ({ ok: true, json: async () => ({ display_name: city, address: { city, suburb: 'المصيف', road: 'شارع الاختبار', house_number: '999' } }) })
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
async function until(predicate) { for (let i = 0; i < 100; i++) { if (predicate()) return; await sleep(30) } throw Error('Timed out') }
function mount() {
  const props = reactive({ form: addressForm(), disabled: false })
  let state
  const app = renderer.createApp({ setup() { state = useAddressLocation(props.form, () => props.disabled); return () => null } })
  app.mount({})
  return { props, state, close: () => app.unmount() }
}
assert.equal(validLocation('', ''), false)
assert.equal(validLocation(91, 36), false)
assert.equal(validLocation(28.4, 36.6), true)
assert.equal(normalizeGeocodingResult({ address: { town: 'تبوك', quarter: 'المصيف', house_number: '999' } }).district, 'المصيف')
const first = mount()
assert.equal(calls, 0)
first.state.locate(); first.state.locate(); assert.equal(calls, 1)
success({ coords: { latitude: 28.4, longitude: 36.6, accuracy: 130 } })
assert.equal(first.state.showMap.value, true)
assert.equal(first.state.accuracy.value, 130)
await until(() => resolveFetch)
const previous = resolveFetch
first.state.selectLocation({ latitude: 28.5, longitude: 36.7 })
previous(response('عنوان قديم'))
await sleep(20)
assert.equal(first.props.form.city, '', 'Stale reverse result cannot overwrite a new point')
resolveFetch = null
await until(() => resolveFetch)
first.props.form.city = 'مدينة كتبها العميل'
resolveFetch(response('تبوك'))
await until(() => !first.state.reverseGeocoding.value)
assert.equal(first.props.form.city, 'مدينة كتبها العميل', 'Provider preserves edits made while waiting')
assert.equal(first.props.form.building_number, '', 'Provider never fills building information')
first.state.locate(); first.state.manualAddress()
success({ coords: { latitude: 29, longitude: 37 } })
assert.equal(first.props.form.latitude, '')
first.state.locate(); failure({ code: 1 })
assert.equal(first.state.opened.value, true)
assert.match(first.state.message.value, /الخريطة/)
first.state.locate(); first.close()
success({ coords: { latitude: 29, longitude: 37 } })
assert.equal(first.props.form.latitude, '')
const second = mount()
window.isSecureContext = false
second.state.locate()
assert.equal(second.state.locating.value, false)
second.state.openMap(); assert.equal(second.state.showMap.value, true, 'HTTP fallback still permits map selection')
second.close()
window.isSecureContext = true
const third = mount()
navigator.geolocation.getCurrentPosition = () => { throw Error('Unavailable') }
third.state.locate(); assert.equal(third.state.opened.value, true)
third.close()
const fourth = mount()
let watchdog
const actualTimeout = globalThis.setTimeout
globalThis.setTimeout = callback => { watchdog = callback; return 0 }
navigator.geolocation.getCurrentPosition = () => {}
fourth.state.locate()
globalThis.setTimeout = actualTimeout
watchdog()
assert.equal(fourth.state.locating.value, false, 'Pending permission cannot wait forever')
assert.equal(fourth.state.opened.value, true)
fourth.close()
console.log('Location tests passed: permissions, coordinates, fallback, races, text preservation and building data')
