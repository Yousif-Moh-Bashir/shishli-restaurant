import { ref, inject } from 'vue'

export function useCartFeedback() {
  const pending = ref(false)
  const { prepareCartSound } = inject('site')
  async function submit(request) {
    if (pending.value) return { ok: false, busy: true }
    prepareCartSound()
    pending.value = true
    try {
      return await request()
    } finally { pending.value = false }
  }
  return { pending, submit }
}
