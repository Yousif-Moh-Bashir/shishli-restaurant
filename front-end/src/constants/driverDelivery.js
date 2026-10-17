export const preparationLabels = { pending: 'بانتظار تأكيد المطعم', confirmed: 'تم تأكيد الطلب', preparing: 'قيد التجهيز', ready: 'جاهز لدى المطعم', out_for_delivery: 'اكتمل التجهيز', completed: 'اكتمل التجهيز', cancelled: 'ملغي' }
export const deliveryLabels = { unassigned: 'بانتظار المندوب', assigned: 'تم إسناد الطلب للمندوب', picked_up: 'استلم المندوب من الفرع', on_the_way: 'في الطريق للعميل', delivered: 'تم التسليم للعميل', failed: 'تعذر التسليم' }
export const deliveryActionLabels = { picked_up: 'استلام من الفرع', on_the_way: 'بدء التوصيل', delivered: 'إكمال الطلب' }
export function isDriverOrderClosed(order) {
  return order?.delivery_status === 'delivered' || ['completed', 'cancelled'].includes(order?.preparation_status)
}
export function deliveryPaymentMessage(order) {
  if (!order || isDriverOrderClosed(order) || order.delivery_status !== 'on_the_way'
    || order.payment_status === 'paid' || order.available_actions?.includes('delivered')) return ''
  return order.payment_status === 'pending' && order.available_actions?.includes('collect_payment')
    ? 'أكّد تحصيل المبلغ من العميل، ثم أكمل الطلب. طريقة الدفع لا تمنع التحصيل.'
    : 'لا يمكن تأكيد التسليم قبل تأكيد الدفع. تواصل مع إدارة الفرع لمراجعة حالة الدفع، ثم حدّث الطلب.'
}
export function driverError(error) {
  const status = error.response?.status || 0
  const messages = { 401: 'انتهت جلستك. سجّل الدخول مجددًا.', 403: 'هذه العملية غير متاحة لحسابك.', 404: 'الطلب غير متاح لك. ربما أعيد إسناده.', 409: 'تغيّر الطلب أو نُفذت العملية بالفعل. حدّث الصفحة قبل المحاولة.', 422: 'راجع البيانات المدخلة.', 429: 'انتظر قليلًا قبل المحاولة مجددًا.' }
  const errors = status === 422 ? error.response?.data?.errors : null
  return { status, message: messages[status] || (status >= 500 ? 'تعذر إكمال العملية. حاول لاحقًا.' : 'تعذر الاتصال. تحقق من الإنترنت وحاول مجددًا.'), errors, retryAfter: error.response?.headers?.['retry-after'] ?? null }
}
export function driverTime(value) { return value ? new Intl.DateTimeFormat('ar-SA', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '' }
