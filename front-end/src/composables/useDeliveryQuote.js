import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useCartStore } from '../stores/cart'
import { useAuthStore } from '../stores/auth'
export function useDeliveryQuote(addressSource, orderType) {
  const cart = useCartStore(), auth = useAuthStore(), quote = ref(null), quoteError = ref(null), isQuoting = ref(false)
  let revision = 0, disposed = false, controller, syncing = false
  const cartSignature = computed(() => JSON.stringify(cart.cart))
  function invalidate() {
    revision++; controller?.abort(); controller = null
    quote.value = null; quoteError.value = null; isQuoting.value = false
  }
  watch([addressSource, orderType, () => auth.token, () => cart.cart?.id, () => cart.cart?.branch.id], invalidate, { flush: 'sync' })
  watch(cartSignature, () => { if (!syncing) invalidate() }, { flush: 'sync' })
  watch(() => [cart.isLoading, cart.isMutating], values => { if (values.some(Boolean)) invalidate() }, { flush: 'sync' })
  async function requestQuote() {
    if (isQuoting.value || !addressSource.value || orderType.value !== 'delivery') return false
    invalidate()
    const version = revision, current = new AbortController()
    controller = current; isQuoting.value = true
    try {
      const result = await cart.requestDeliveryQuote(addressSource.value, { signal: current.signal, onSync(operation) { syncing = true; try { operation() } finally { syncing = false } } })
      if (disposed || version !== revision || current.signal.aborted) return false
      if (!result.ok) { if (!result.stale && !result.busy) quoteError.value = result.error || { message: result.message || 'تعذر التحقق من التوصيل.', errors: {} }; return false }
      if (typeof result.data?.available !== 'boolean' || typeof result.data.delivery_fee !== 'string' || typeof result.data.estimated_total !== 'string') throw Error('INVALID_DELIVERY_QUOTE')
      quote.value = result.data; return true
    } catch { if (!disposed && version === revision) quoteError.value = { message: 'تعذر قراءة نتيجة التحقق من التوصيل. أعد المحاولة.', errors: {} }; return false }
    finally { if (!disposed && version === revision) { isQuoting.value = false; controller = null } }
  }
  onBeforeUnmount(() => { disposed = true; invalidate() })
  return { quote, quoteError, isQuoting, requestQuote, invalidate }
}
