// Endpoint and permission presentation only. Eligibility is returned by Laravel.
export const orderOperations=Object.freeze({
  confirm:{label:'تأكيد الطلب',permission:'orders.confirm'},
  start_preparing:{label:'بدء التحضير',permission:'orders.start_preparing'},
  mark_ready:{label:'الطلب جاهز',permission:'orders.mark_ready'},
  dispatch:{label:'إرسال للتوصيل',permission:'orders.dispatch'},
  complete:{label:'إكمال الطلب',permission:'orders.complete'},
  cancel:{label:'إلغاء الطلب',permission:'orders.cancel'},
})
