import assert from 'node:assert/strict'
import { createRenderer, ref, nextTick } from 'vue'
import { useOrderPolling } from '../src/composables/useOrderPolling.js'

globalThis.document = Object.assign(new EventTarget(), { visibilityState: 'visible' })
globalThis.window = new EventTarget()
const renderer = createRenderer({
  createComment: () => ({}), createText: () => ({}), createElement: () => ({}),
  insert() {}, remove() {}, setText() {}, setElementText() {}, patchProp() {},
  parentNode: () => null, nextSibling: () => null,
})
function mount(request, options) {
  let state
  const app = renderer.createApp({ setup() { state = useOrderPolling(request, options); return () => null } })
  app.mount({})
  return { state, close: () => app.unmount() }
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
async function until(condition) {
  for (let i = 0; i < 100; i++) { if (condition()) return; await sleep(5) }
  throw Error('Polling did not resume')
}

const paused = ref(false), enabled = ref(true)
let calls = 0
const first = mount(async () => ({ sequence: ++calls }), { interval: 20, paused: () => paused.value, enabled: () => enabled.value })
try {
  await first.state.load()
  paused.value = true
  await nextTick()
  document.visibilityState = 'hidden'; document.dispatchEvent(new Event('visibilitychange'))
  document.visibilityState = 'visible'; document.dispatchEvent(new Event('visibilitychange'))
  const before = calls
  await sleep(45)
  assert.equal(calls, before, 'An open operation pauses background refresh')
  paused.value = false
  await until(() => calls > before)
  const resumed = calls
  await until(() => calls > resumed)
  enabled.value = false; await nextTick()
  const disabled = calls
  await sleep(45)
  assert.equal(calls, disabled)
  enabled.value = true
  await until(() => calls > disabled)
  const beforeOnline = calls
  window.dispatchEvent(new Event('online'))
  await until(() => calls > beforeOnline)
} finally { first.close() }
const afterUnmount = calls
document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('online'))
await sleep(45)
assert.equal(calls, afterUnmount, 'Unmount removes listeners and timers')

let attempts = 0
const rateLimit = mount(async () => {
  if (++attempts === 1) throw { response: { status: 429, headers: { 'retry-after': '60' } } }
  return 'new filters'
}, { interval: 20 })
try {
  await rateLimit.state.load()
  assert.equal(rateLimit.state.error.value.status, 429)
  await rateLimit.state.load(); assert.equal(attempts, 1, '429 retry delay is respected')
  rateLimit.state.reset()
  await rateLimit.state.load()
  assert.equal(rateLimit.state.data.value, 'new filters', 'Reset clears the old request cooldown')
} finally { rateLimit.close() }

const held = []
const race = mount(signal => new Promise(resolve => held.push({ signal, resolve })), { interval: 10000 })
try {
  const oldRequest = race.state.load()
  const replacement = race.state.load(true, true)
  assert.equal(held.length, 2, 'A post-mutation refresh supersedes an in-flight read')
  assert.equal(held[0].signal.aborted, true)
  held[1].resolve('after mutation'); await replacement
  held[0].resolve('stale snapshot'); await oldRequest
  assert.equal(race.state.data.value, 'after mutation')
} finally { race.close() }

let terminal
const terminalApp = renderer.createApp({ setup() {
  terminal = useOrderPolling(async () => ({ complete: true }), { enabled: () => !terminal.data.value?.complete, interval: 20 })
  return () => null
} })
terminalApp.mount({})
await terminal.load()
assert.equal(terminal.data.value.complete, true, 'The enabled callback can reference the returned polling state')
terminalApp.unmount()
console.log('PASS: resume after hidden/paused, recurring updates, enable/disable, online recovery, cleanup, 429 reset, post-mutation stale-response protection, and tracking state initialization.')
