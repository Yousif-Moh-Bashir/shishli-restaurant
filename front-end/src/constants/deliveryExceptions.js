export const exceptionActionLabels = {
  fail: 'تسجيل تعذر التسليم', start_return: 'بدء إرجاع الطلب للمطعم',
  confirm_return: 'تأكيد استلام المطعم وإنهاء عهدة المندوب',
  release: 'إنهاء العهدة قبل الاستلام من المطعم', cancel: 'إلغاء استثنائي بعد حسم العهدة',
  reassign: 'نقل المهمة إلى مندوب آخر',
  resolve_custody: 'حسم عهدة طلب مفقود أو تالف دون إثبات عودة',
}
export function exceptionPayload(order, action, reason, driverUuid, key = globalThis.crypto.randomUUID()) {
  return { action, reason: reason.trim(), expected_revision: order.delivery_exception?.revision ?? order.delivery_revision ?? 0,
    operation_key: key, acknowledged: true, ...(action === 'reassign' ? { driver_uuid: driverUuid } : {}) }
}
