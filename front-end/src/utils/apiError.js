const validationLabels = { name: 'الاسم', phone: 'رقم الجوال', phone_normalized: 'رقم الجوال', email: 'البريد الإلكتروني', password: 'كلمة المرور', password_confirmation: 'تأكيد كلمة المرور', roles: 'الأدوار الإدارية', customer_uuid: 'حساب العميل' }
function validationMessage(message, field) {
  if (!/^validation\./i.test(message.trim())) return message
  const label = Object.hasOwn(validationLabels, field) ? validationLabels[field] : field.startsWith('roles.') ? 'الدور الإداري' : 'هذا الحقل'
  const rule = message.trim().slice('validation.'.length).match(/^[a-z0-9_.]+/i)?.[0] || ''
  if (rule === 'unique') return `${label} مستخدم بالفعل.`
  if (rule === 'required') return `${label} مطلوب.`
  if (rule === 'email') return 'أدخل بريدًا إلكترونيًا صحيحًا.'
  if (rule === 'min.string') return `${label} أقصر من الحد المطلوب. راجع الحد الأدنى الموضّح بجانب الحقل.`
  if (rule === 'max.string') return `${label} يتجاوز الحد الأقصى المسموح.`
  if (rule === 'password.mixed') return 'كلمة المرور يجب أن تحتوي على أحرف كبيرة وصغيرة.'
  if (rule === 'password.numbers') return 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.'
  return `راجع قيمة ${label} وأدخل بيانات صحيحة.`
}

export function normalizeApiError(error) {
  const status = error.response?.status ?? 0
  const messages = { 401: 'يجب تسجيل الدخول أو مراجعة بيانات الدخول.', 403: 'ليس لديك صلاحية لتنفيذ هذه العملية.', 422: 'راجع البيانات المدخلة في الحقول الموضحة.', 429: 'تم تجاوز عدد المحاولات المسموح بها. حاول لاحقًا.' }
  const raw = error.response?.data
  // Display only the known Laravel wrapper, never HTML/traces/Axios config.
  const safeMessage = raw?.success === false && typeof raw.message === 'string' && status < 500 && !/^validation\./i.test(raw.message.trim()) ? raw.message : null
  const errors = status === 422 && raw?.errors && typeof raw.errors === 'object'
    ? Object.fromEntries(Object.entries(raw.errors).map(([key, value]) => [key, [...new Set((Array.isArray(value) ? value : [value]).map(item=>typeof item==='string'?item:typeof item?.message==='string'?item.message:null).filter(Boolean).map(message=>validationMessage(message,key)))]])) : {}
  return { status, message: status >= 500 ? 'حدث خطأ في الخادم. حاول لاحقًا.' : safeMessage || messages[status] || (error.message?.startsWith('INVALID_') ? 'تعذر قراءة استجابة API. تحقق من إعداد عنوان الخادم.' : status ? 'تعذر تنفيذ الطلب.' : error.code === 'ECONNABORTED' ? 'انتهت مهلة الاتصال بالخادم. حاول مجددًا.' : 'تعذر الاتصال بالخادم. تحقق من الاتصال وإعداد عنوان API.'), errors, retryAfter: error.response?.headers?.['retry-after'] ?? null }
}
