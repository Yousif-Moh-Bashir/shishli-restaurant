export function normalizeApiError(error) {
  const status = error.response?.status ?? 0
  const messages = { 401: 'يجب تسجيل الدخول أو مراجعة بيانات الدخول.', 403: 'ليس لديك صلاحية لتنفيذ هذه العملية.', 422: 'راجع البيانات المدخلة في الحقول الموضحة.', 429: 'تم تجاوز عدد المحاولات المسموح بها. حاول لاحقًا.' }
  const raw = error.response?.data
  // Display only the known Laravel wrapper, never HTML/traces/Axios config.
  const safeMessage = raw?.success === false && typeof raw.message === 'string' && status < 500 ? raw.message : null
  const errors = status === 422 && raw?.errors && typeof raw.errors === 'object'
    ? Object.fromEntries(Object.entries(raw.errors).map(([key, value]) => [key, (Array.isArray(value) ? value : [value]).map(item=>typeof item==='string'?item:typeof item?.message==='string'?item.message:null).filter(Boolean)])) : {}
  return { status, message: status >= 500 ? 'حدث خطأ في الخادم. حاول لاحقًا.' : safeMessage || messages[status] || (error.message?.startsWith('INVALID_') ? 'تعذر قراءة استجابة API. تحقق من إعداد عنوان الخادم.' : status ? 'تعذر تنفيذ الطلب.' : error.code === 'ECONNABORTED' ? 'انتهت مهلة الاتصال بالخادم. حاول مجددًا.' : 'تعذر الاتصال بالخادم. تحقق من الاتصال وإعداد عنوان API.'), errors, retryAfter: error.response?.headers?.['retry-after'] ?? null }
}
