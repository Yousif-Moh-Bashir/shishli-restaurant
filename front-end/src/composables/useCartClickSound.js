import { onBeforeUnmount } from 'vue'

export function useCartClickSound() {
  let context, disposed = false
  function prepare() {
    if (disposed) return
    try {
      const Audio = window.AudioContext || window.webkitAudioContext
      if (!Audio) return
      context ||= new Audio()
      if (context.state === 'suspended') void context.resume().catch(() => {})
    } catch { /* The visual confirmation also works when audio is unavailable. */ }
  }
  function play() {
    if (disposed || context?.state !== 'running') return
    try {
      const now = context.currentTime
      const oscillator = context.createOscillator(), gain = context.createGain()
      oscillator.type = 'triangle'
      oscillator.frequency.setValueAtTime(1500, now)
      oscillator.frequency.exponentialRampToValueAtTime(450, now + .045)
      gain.gain.setValueAtTime(.0001, now)
      gain.gain.exponentialRampToValueAtTime(.12, now + .002)
      gain.gain.exponentialRampToValueAtTime(.0001, now + .055)
      oscillator.connect(gain); gain.connect(context.destination)
      oscillator.onended = () => { oscillator.disconnect(); gain.disconnect() }
      oscillator.start(now); oscillator.stop(now + .06)
    } catch { /* Audio must not interrupt a successful cart update. */ }
  }
  onBeforeUnmount(() => { disposed = true; void context?.close().catch(() => {}) })
  return { prepare, play }
}
