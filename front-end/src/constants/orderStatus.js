// Match Laravel's OrderStatusPresenter and supported enums.
export const orderStatusLabels = Object.freeze({
  pending: 'تم استلام الطلب', confirmed: 'تم تأكيد الطلب', preparing: 'جاري التحضير',
  ready: 'الطلب جاهز', out_for_delivery: 'خرج للتوصيل', completed: 'مكتمل', cancelled: 'ملغي',
})
export const orderTypeLabels = Object.freeze({ pickup: 'استلام من الفرع', delivery: 'توصيل' })
export {paymentMethodLabels,paymentStatusLabels,getPaymentMethodLabel,getPaymentStatusLabel} from '../utils/paymentStatus'
export const getOrderStatusLabel = status => orderStatusLabels[status] ?? 'حالة غير معروفة'
export const isTerminalOrder = status => ['completed', 'cancelled'].includes(status)
export const getOrderTypeLabel = type => orderTypeLabels[type] ?? 'غير محدد'

export function getTrackingErrorMessage(status) {
  if ([401, 403, 404, 422].includes(status)) return 'تعذر العثور على الطلب أو بيانات التتبع غير صحيحة.'
  if (status === 429) return 'محاولات كثيرة. انتظر قليلًا ثم حاول مجددًا.'
  return 'تعذر الاتصال بالخادم. حاول مجددًا لاحقًا.'
}
