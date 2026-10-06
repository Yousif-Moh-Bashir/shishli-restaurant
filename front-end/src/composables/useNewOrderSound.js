import { ref, onMounted, onBeforeUnmount } from 'vue'

export function useNewOrderSound(allowed = () => true) {
  const enabled = ref(false), message = ref('')
  let context, watermark = null, seen = new Set(), activating = false
  function muted() { try { return localStorage.getItem('shishli.orderSoundMuted') === '1' } catch { return false } }
  function saveMute(value) { try { localStorage.setItem('shishli.orderSoundMuted', value ? '1' : '0') } catch {} }
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
    for (const [offset, frequency] of [[0, 784], [1, 1046], [2, 784]]) {
      const oscillator = context.createOscillator(), gain = context.createGain()
      oscillator.frequency.value = frequency
      gain.gain.setValueAtTime(0, now + offset)
      gain.gain.linearRampToValueAtTime(.2, now + offset + .015)
      gain.gain.exponentialRampToValueAtTime(.001, now + offset + .95)
      oscillator.connect(gain); gain.connect(context.destination)
      oscillator.start(now + offset); oscillator.stop(now + offset + 1)
    }
  }
  async function toggle() {
    if (activating || !allowed()) return
    if (enabled.value) { enabled.value = false; saveMute(true); message.value = ''; return }
    activating = true
    try {
      const Audio = window.AudioContext || window.webkitAudioContext
      if (!Audio) throw Error('AUDIO_UNAVAILABLE')
      context ||= new Audio()
      await context.resume()
      if (context.state !== 'running') throw Error('AUDIO_BLOCKED')
      enabled.value = true; saveMute(false); message.value = ''; ring()
    } catch { enabled.value = false; message.value = 'تعذر تشغيل الصوت. اضغط تفعيل الصوت مرة أخرى.' }
    finally { activating = false }
  }
  function observe(orders) {
    const rows = orders.filter(order => order.id && Number.isFinite(Date.parse(order.placed_at)))
    if (!rows.length) { if (watermark === null) watermark = 0; return }
    const newest = Math.max(...rows.map(order => Date.parse(order.placed_at)))
    const arrived = watermark !== null && rows.some(order => !seen.has(order.id) && Date.parse(order.placed_at) >= watermark)
    watermark = Math.max(watermark ?? 0, newest)
    seen = new Set([...seen, ...rows.map(order => order.id)].slice(-500))
    if (arrived) ring()
  }
  function unlock(event) {
    if (!allowed() || event.target?.closest('.sidebar-sound-toggle') || muted() || enabled.value) return
    void toggle()
  }
  onMounted(() => { document.addEventListener('pointerdown', unlock); document.addEventListener('keydown', unlock) })
  onBeforeUnmount(() => { document.removeEventListener('pointerdown', unlock); document.removeEventListener('keydown', unlock); enabled.value = false; void context?.close(); reset() })
  return { enabled, message, toggle, observe, reset }
}
