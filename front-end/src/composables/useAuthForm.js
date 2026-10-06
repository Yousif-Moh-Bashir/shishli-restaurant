import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { normalizeApiError } from '../utils/apiError'
import { authenticatedHome, safeIntendedRoute } from '../router/authGuards'

export function useAuthForm(mode) {
  const isRegister = mode === 'register', auth = useAuthStore(), router = useRouter(), route = useRoute()
  const form = reactive(isRegister ? { name: '', email: '', phone: '', password: '', password_confirmation: '' } : { phone: '', password: '' })
  const errors = reactive({}), status = ref(''), generalError = ref('')
  const isSubmitting = computed(() => auth.isLoading)
  const showPassword = ref(false), showConfirmation = ref(false), inputs = new Map()
  let disposed = false
  const setInput = (field, element) => element ? inputs.set(field, element) : inputs.delete(field)
  const errorText = field => errors[field]?.join(' ') || ''
  function clearError(field) { delete errors[field]; generalError.value = status.value = '' }
  function showRecoveryNotice() { status.value = 'استعادة كلمة المرور غير متاحة حاليًا عبر API. لم تُرسل رسالة أو رمز.' }
  function normalizePhone(value) {
    const digits = value.replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit)).replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[\s()-]/g, '')
    return digits.replace(/^00/, '+')
  }
  async function focusError() { await nextTick(); inputs.get(Object.keys(form).find(field => errors[field]?.length))?.focus() }
  async function submit() {
    if (isSubmitting.value) return
    Object.keys(errors).forEach(field => delete errors[field]); status.value = generalError.value = ''
    const phone = normalizePhone(form.phone)
    if (isRegister && (!form.name.trim() || form.name.length > 255)) errors.name = ['أدخل اسمًا لا يتجاوز 255 حرفًا.']
    if (isRegister && (!form.email.trim() || form.email.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))) errors.email = ['أدخل بريدًا إلكترونيًا صحيحًا.']
    const emailLogin = !isRegister && form.phone.includes('@')
    if (emailLogin ? (form.phone.trim().length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.phone.trim())) : ((!isRegister || phone) && !/^\+[1-9][0-9]{7,14}$/.test(phone))) errors.phone = ['أدخل رقمًا دوليًا أو بريدًا إلكترونيًا صحيحًا.']
    if (!form.password || form.password.length > 72 || (isRegister && form.password.length < 8)) errors.password = [isRegister ? 'استخدم كلمة مرور من 8 إلى 72 حرفًا.' : 'أدخل كلمة المرور، بحد أقصى 72 حرفًا.']
    if (isRegister && form.password_confirmation !== form.password) errors.password_confirmation = ['تأكيد كلمة المرور غير مطابق.']
    if (Object.keys(errors).length) { await focusError(); return }
    const payload = isRegister
      ? { name: form.name.trim(), email: form.email.trim(), ...(phone ? { phone } : {}), password: form.password, password_confirmation: form.password_confirmation }
      : { ...(emailLogin ? { email: form.phone.trim() } : { phone }), password: form.password }
    form.password = ''; if (isRegister) form.password_confirmation = ''
    showPassword.value = showConfirmation.value = false
    try {
      const success = isRegister ? await auth.register(payload) : await auth.login(payload)
      if (!success || disposed) return
      if (isRegister) status.value = 'تم إنشاء الحساب. سجل الدخول باستخدام البريد الإلكتروني أو رقم الجوال وكلمة المرور.'
      else await router.replace(safeIntendedRoute(router, auth, route.query.redirect) || authenticatedHome(auth))
    } catch (cause) {
      if (disposed) return
      const normalized = normalizeApiError(cause)
      Object.assign(errors, normalized.errors)
      if (!isRegister && normalized.errors.email) errors.phone = normalized.errors.email
      generalError.value = normalized.status === 401 && !isRegister ? 'بيانات الدخول غير صحيحة أو الحساب غير متاح.' : normalized.message
      await focusError()
    } finally { payload.password = ''; if (isRegister) payload.password_confirmation = '' }
  }
  onBeforeUnmount(() => { disposed = true; form.password = ''; if (isRegister) form.password_confirmation = ''; inputs.clear() })
  return { form, errors, generalError, status, isSubmitting, showPassword, showConfirmation, setInput, errorText, clearError, showRecoveryNotice, submit }
}
