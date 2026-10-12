import { watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useApiState } from './useApiState'
import { ordersApi } from '../api/orders.api'
import { readOrderAccess, saveOrderAccess } from '../utils/orderAccess'
export function useOrderDetails(customerOnly = false) {
  const route = useRoute(), auth = useAuthStore(), state = useApiState()
  async function load() {
    const id = route.params.orderUuid
    return state.run(async signal => {
      await auth.initialize()
      if (signal.aborted) throw { code: 'ERR_CANCELED' }
      if (typeof id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) throw new Error('INVALID_ORDER_IDENTIFIER')
      const token = customerOnly ? null : readOrderAccess(id)
      if (!token && !auth.isAuthenticated) throw { response: { status: 404, data: { success: false, message: 'لا تتوفر بيانات الوصول إلى الطلب في هذه الجلسة. إذا فُقد رد التأكيد فلن يعيد الخادم إصدار رمز الضيف. تواصل مع المطعم ولا تُعد الطلب بسلة جديدة.' } } }
      const order = token ? await ordersApi.guest(id, token, signal) : await ordersApi.show(id, signal)
      if (!order || order.id !== id || !order.order_number || !Array.isArray(order.items) || !order.pricing) throw new Error('INVALID_ORDER_RESPONSE')
      if (token) saveOrderAccess(id, token, { number: order.order_number, placedAt: order.placed_at })
      return order
    })
  }
  watch([() => route.params.orderUuid, () => auth.token], () => { state.clear(); void load() }, { immediate: true })
  return { ...state, load }
}
