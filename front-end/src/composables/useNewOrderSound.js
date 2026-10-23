import { ref, onMounted, onBeforeUnmount } from 'vue'

export function useNewOrderSound(allowed = () => true, { identity, storageKey = 'shishli.orderSoundMuted' } = {}) {
  function preference() { try { return localStorage.getItem(storageKey) } catch { return null } }
  const enabled = ref(preference() === '0'), message = ref('')
  let context, watermark = null, seen = new Set(), activating = false
  function muted() { return preference() === '1' }
  function saveMute(value) { try { localStorage.setItem(storageKey, value ? '1' : '0') } catch {} }
  function reset() { watermark = null; seen.clear() }
  function ring() {
    if (!allowed() || !enabled.value || !context) return
    if (context.state !== 'running') {
      void context.resume().then(() => {
        if (context.state === 'running') ring()
        else { enabled.value = false; message.value = 'الصوت متوقف من المتصفح. اضغط تفعيل صوت الطلبات.' }
      }).catch(() => { enabled.value = false; message.value = 'اضغط تفعيل صوت الطلبات للسماح بالتنبيه.' })
      return
    }
    const now = context.currentTime
    for (const offset of [0, 1.7]) {
      const oscillator = context.createOscillator(), gain = context.createGain()
      oscillator.frequency.value = 880
      gain.gain.setValueAtTime(0, now + offset)
      gain.gain.linearRampToValueAtTime(.16, now + offset + .01)
      gain.gain.exponentialRampToValueAtTime(.001, now + offset + .28)
      oscillator.connect(gain); gain.connect(context.destination)
      oscillator.start(now + offset); oscillator.stop(now + offset + .3)
    }
  }
  async function resumeAudio() {
    try {
      const Audio = window.AudioContext || window.webkitAudioContext
      if (!Audio) throw Error('AUDIO_UNAVAILABLE')
      context ||= new Audio()
      await context.resume()
      if (context.state !== 'running') throw Error('AUDIO_BLOCKED')
      message.value = ''
      return true
    } catch {
      message.value = 'اضغط على الصفحة للسماح بتشغيل صوت التنبيه.'
      return false
    }
  }
  async function toggle() {
    if (activating || !allowed()) return
    if (enabled.value) { enabled.value = false; saveMute(true); message.value = ''; return }
    activating = true
    try {
      if (!await resumeAudio()) throw Error('AUDIO_BLOCKED')
      enabled.value = true; saveMute(false); message.value = ''
    } catch { enabled.value = false; message.value = 'تعذر تشغيل الصوت. اضغط تفعيل الصوت مرة أخرى.' }
    finally { activating = false }
  }
  function observe(orders) {
    // Driver listings have no creation timestamp; readiness is a new arrival too.
    if (identity) {
      const keys = orders.map(identity).filter(Boolean)
      const arrived = watermark !== null && keys.some(key => !seen.has(key))
      watermark = 0
      seen = new Set([...seen, ...keys].slice(-500))
      if (arrived) ring()
      return
    }
    const arrivalTime = order => Date.parse(order.created_at ?? order.placed_at)
    const rows = orders.filter(order => order.id && Number.isFinite(arrivalTime(order)))
    if (!rows.length) { if (watermark === null) watermark = 0; return }
    const newest = Math.max(...rows.map(arrivalTime))
    const arrived = watermark !== null && rows.some(order => !seen.has(order.id) && arrivalTime(order) >= watermark)
    watermark = Math.max(watermark ?? 0, newest)
    seen = new Set([...seen, ...rows.map(order => order.id)].slice(-500))
    if (arrived) ring()
  }
  function unlock(event) {
    if (!allowed() || event.target?.closest('.sidebar-sound-toggle') || muted()) return
    if (!enabled.value) { void toggle(); return }
    void resumeAudio()
  }
  onMounted(() => { document.addEventListener('pointerdown', unlock); document.addEventListener('keydown', unlock) })
  onBeforeUnmount(() => { document.removeEventListener('pointerdown', unlock); document.removeEventListener('keydown', unlock); enabled.value = false; void context?.close(); reset() })
  return { enabled, message, toggle, observe, reset }
}
