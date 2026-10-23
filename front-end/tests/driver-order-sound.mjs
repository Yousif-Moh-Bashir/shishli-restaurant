import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

// Exercise the real sound detector with lifecycle and Web Audio test doubles.
const source = (await readFile(new URL('../src/composables/useNewOrderSound.js', import.meta.url), 'utf8'))
  .replace("import { ref, onMounted, onBeforeUnmount } from 'vue'", 'const ref = value => ({ value }); const onMounted = () => {}; const onBeforeUnmount = () => {}')
const { useNewOrderSound } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
let played = 0, allowed = true
const stored = new Map()
globalThis.localStorage = { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value) }
globalThis.window = { AudioContext: class {
  state = 'running'; currentTime = 0; destination = {}
  async resume() {}
  createOscillator() { return { frequency: {}, connect() {}, start() { played++ }, stop() {} } }
  createGain() { return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} } }
} }
const sound = useNewOrderSound(() => allowed, { identity: order => `${order.id}:${order.preparation_status}`, storageKey: 'shishli.driverOrderSoundMuted' })
const order = (id, preparation_status = 'preparing') => ({ id, preparation_status })
await sound.toggle()
sound.observe([order('existing')])
assert.equal(played, 0, 'initial listing stays silent')
sound.observe([order('existing'), order('new')])
assert.equal(played, 2, 'new order sounds once')
sound.observe([order('existing'), order('new')])
assert.equal(played, 2, 'polling does not repeat the sound')
sound.observe([order('new', 'ready')])
assert.equal(played, 4, 'ready transition sounds without timestamps')
sound.observe([]); sound.observe([order('new', 'ready')])
assert.equal(played, 4, 'reappearing order does not repeat')
await sound.toggle(); sound.observe([order('muted')])
assert.equal(played, 4)
assert.equal(stored.get('shishli.driverOrderSoundMuted'), '1')
assert.equal(stored.has('shishli.orderSoundMuted'), false, 'admin preference is independent')
const restoredMuted = useNewOrderSound(() => allowed, { identity: item => `${item.id}:${item.preparation_status}`, storageKey: 'shishli.driverOrderSoundMuted' })
assert.equal(restoredMuted.enabled.value, false, 'muted preference survives a new layout instance')
await sound.toggle()
const restoredEnabled = useNewOrderSound(() => allowed, { identity: item => `${item.id}:${item.preparation_status}`, storageKey: 'shishli.driverOrderSoundMuted' })
assert.equal(restoredEnabled.enabled.value, true, 'enabled preference survives a new layout instance')
allowed = false; sound.observe([order('blocked')])
assert.equal(played, 4, 'unavailable driver stays silent')
allowed = true; sound.reset(); sound.observe([order('other-branch')])
assert.equal(played, 4, 'branch reset establishes a silent baseline')
sound.observe([order('after-reset')])
assert.equal(played, 6)
console.log('Passed: driver arrivals, readiness, deduplication, mute restore, enable restore, eligibility, independent preference and branch reset (mock audio).')
