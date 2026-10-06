// Presentation of Laravel notification enums. No delivery/retry state machine.
export const notificationStatuses=Object.freeze({pending:'بانتظار الإرسال',processing:'جارٍ الإرسال',sent:'تم الإرسال',failed:'تعذر الإرسال',cancelled:'ملغي'})
export const notificationChannels=Object.freeze({sms:'SMS',whatsapp:'WhatsApp',email:'البريد الإلكتروني'})
export const notificationTypes=Object.freeze({order_placed:'استلام الطلب',order_confirmed:'تأكيد الطلب',order_preparing:'بدء التحضير',order_ready:'الطلب جاهز',order_out_for_delivery:'الخروج للتوصيل',order_completed:'اكتمال الطلب',order_cancelled:'إلغاء الطلب',payment_succeeded:'نجاح الدفع',payment_failed:'تعذر الدفع'})
export const notificationLabel=value=>notificationStatuses[value]||'حالة غير معروفة'
export const channelLabel=value=>notificationChannels[value]||'قناة غير معروفة'
export const typeLabel=value=>notificationTypes[value]||'نوع غير معروف'
export const notificationBadge=value=>value==='sent'?'good':['failed','cancelled'].includes(value)?'off':''
export const notificationPending=value=>['pending','processing'].includes(value)
