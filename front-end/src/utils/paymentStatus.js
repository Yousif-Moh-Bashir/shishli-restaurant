// Presentation of the audited Laravel enums; no payment state machine.
export const paymentStatusLabels = Object.freeze({pending:'بانتظار الدفع',processing:'جارٍ معالجة الدفع',paid:'مدفوع',failed:'تعذر الدفع',cancelled:'ملغي',partially_refunded:'مسترد جزئيًا',refunded:'مسترد'})
export const paymentMethodLabels = Object.freeze({cash:'نقدًا',network:'شبكة',card:'بطاقة'})
export const transactionTypeLabels = Object.freeze({authorization:'تفويض',capture:'تحصيل التفويض',sale:'تحصيل',refund:'استرداد',void:'إلغاء التفويض'})
export const transactionStatusLabels = Object.freeze({pending:'قيد الانتظار',succeeded:'ناجحة',failed:'تعذرت'})
export const getPaymentStatusLabel = value => paymentStatusLabels[value] || 'حالة دفع غير معروفة'
export const getPaymentMethodLabel = value => paymentMethodLabels[value] || 'طريقة غير معروفة'
export const paymentBadgeClass = value => value === 'paid' ? 'good' : ['failed','cancelled'].includes(value) ? 'off' : ''
export const isPaymentPending = value => ['pending','processing'].includes(value)
export function paymentAmount(amount,currency){
  if(amount==null||!currency||!Number.isFinite(Number(amount)))return 'غير متاح'
  try{return new Intl.NumberFormat('ar-SA',{style:'currency',currency}).format(Number(amount))}catch{return `${amount} ${currency}`}
}
// No production provider adapter is registered in Laravel config/payments.php.
// This is a deployment limitation, not a browser-selected provider or price policy.
export const checkoutPaymentMethods = Object.freeze(['cash','network'])
