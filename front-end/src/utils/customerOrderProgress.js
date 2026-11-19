const messages = Object.freeze({
  pending: ['استلمنا طلبك للمراجعة', 'إرسال الطلب لا يعني قبوله. يتأكد الطلب فقط بعد قبول المطعم، ولم يبدأ التجهيز بعد.'],
  confirmed: ['قبِل المطعم طلبك', 'وافق المطعم على الطلب. سيظهر بدء التجهيز عند تسجيله فعليًا.'],
  preparing: ['طلبك قيد التجهيز', 'بدأ المطعم تجهيز طلبك. تابع هنا لمعرفة التحديثات.'],
  ready: ['طلبك جاهز', 'أعلن المطعم جاهزية الطلب. راجع طريقة الاستلام أدناه.'],
  out_for_delivery: ['خرج طلبك للتوصيل', 'سجّل المندوب بدء التوصيل. تابع حالة التسليم هنا.'],
  completed: ['اكتمل طلبك', 'سُجّل اكتمال الطلب. شكرًا لطلبك من شيشلي.'],
  cancelled: ['أُلغي الطلب', 'هذا الطلب ملغي. راجع حالة الدفع وتواصل مع المطعم عند الحاجة.'],
})

export function customerOrderMessage(status) {
  const [title, description] = messages[status] || ['متابعة طلبك', 'راجع الحالة المسجلة وتواصل مع المطعم عند الحاجة.']
  return { title, description }
}

export const canShowOrderEstimates = status => ['confirmed', 'preparing', 'ready', 'out_for_delivery'].includes(status)

// Also tolerate older API responses while frontend/backend are deployed separately.
export const occurredOrderTimeline = (timeline, currentStatus) => (timeline || []).filter(step =>
  step.state !== 'upcoming' && (step.occurred_at || step.status === currentStatus))
