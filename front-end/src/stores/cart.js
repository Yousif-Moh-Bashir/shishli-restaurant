import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { cartApi } from '../api/cart.api'
import { deliveryApi } from '../api/delivery.api'
import { checkoutApi } from '../api/checkout.api'
import { readCheckoutAttempts, persistCheckoutAttempts, saveOrderAccess } from '../utils/orderAccess'
import { normalizeApiError } from '../utils/apiError'
import { useAuthStore } from './auth'
import { useBranchStore } from './branch'
const tokenKey = 'shishli_cart_token'
function readToken() { try { return localStorage.getItem(tokenKey) || '' } catch { return '' } }
export const useCartStore = defineStore('cart', () => {
  const auth = useAuthStore(), branch = useBranchStore()
  const cart = ref(null), cartToken = ref(readToken()), isLoading = ref(false), isMutating = ref(false)
  const error = ref(null), initialized = ref(false), notice = ref(''), persistenceWarning = ref('')
  const checkoutAttempts = ref(readCheckoutAttempts()), checkoutBusy = ref(false)
  const checkoutScope = computed(() => auth.token ? 'account:' + auth.user?.uuid : 'guest')
  const pendingCheckout = computed(() => checkoutAttempts.value[checkoutScope.value] || null)
  let pending, revision = 0
  const items = computed(() => cart.value?.items || [])
  const itemsCount = computed(() => cart.value?.summary.quantity_total ?? 0)
  const subtotal = computed(() => cart.value?.summary.subtotal ?? null)
  const discount = computed(() => cart.value?.summary.discount_total ?? null)
  const total = computed(() => cart.value?.summary.total ?? null)
  const issues = computed(() => cart.value?.issues || [])
  const canCheckout = computed(() => cart.value?.can_checkout === true && !error.value && !isLoading.value && !isMutating.value)
  const isEmpty = computed(() => initialized.value && !error.value && items.value.length === 0)
  function saveToken(value) {
    cartToken.value = value
    try { if (value) localStorage.setItem(tokenKey, value); else localStorage.removeItem(tokenKey) }
    catch { persistenceWarning.value = 'التخزين غير متاح؛ احتفظ بهذه الصفحة للحفاظ على سلة الضيف.' }
  }
  function context() { return auth.token ? { uuid: cart.value?.id } : { token: cartToken.value } }
  function apply(value) {
    if (!value || typeof value.id !== 'string' || !Array.isArray(value.items) || !value.summary || !value.branch) throw new Error('INVALID_CART_RESPONSE')
    cart.value = value
    if (!auth.token && value.token) saveToken(value.token)
    branch.syncCartBranch(value.branch.id)
  }
  function failure(cause) {
    error.value = normalizeApiError(cause)
    return { ok: false, message: Object.values(error.value.errors).flat().join(' ') || error.value.message }
  }
  function initializeCart(force = false) {
    if (force && isMutating.value) return Promise.resolve({ ok: false, message: 'انتظر اكتمال تحديث السلة.' })
    if (pending) return pending
    if (initialized.value && !force) return Promise.resolve({ ok: Boolean(cart.value) || !error.value, message: error.value?.message })
    const version = revision
    isLoading.value = true; error.value = null
    pending = (async () => {
      try {
        await auth.initialize(); await branch.load()
        if (version !== revision) return { ok: false }
        // A converted cart returns 404 on GET. Never replace it while a checkout result is uncertain.
        if (pendingCheckout.value) {
          try { const value = await cartApi.show(auth.token ? { uuid: pendingCheckout.value.uuid } : context()); if (version !== revision) return { ok: false }; apply(value) }
          catch (cause) { if (version !== revision) return { ok: false }; if (cause.response?.status !== 404) throw cause }
          initialized.value = true
          return { ok: true }
        }
        let value
        if (auth.token) {
          try { value = await cartApi.show(context()) }
          catch (cause) {
            if (version !== revision) return { ok: false }
            if (![404, 422].includes(cause.response?.status) || !branch.selectedUuid) throw cause
            value = await cartApi.create(branch.selectedUuid)
          }
        } else if (cartToken.value) {
          try { value = await cartApi.show(context()) }
          catch (cause) {
            if (version !== revision) return { ok: false }
            if (cause.response?.status !== 404) throw cause
            saveToken(''); notice.value = 'انتهت صلاحية سلة الضيف أو لم تعد متاحة. يمكنك إنشاء سلة جديدة.'
            if (branch.selectedUuid) value = await cartApi.create(branch.selectedUuid)
          }
        } else if (branch.selectedUuid) value = await cartApi.create(branch.selectedUuid)
        if (version === revision) {
          if (!value && branch.error) { error.value = branch.error; return { ok: false, message: branch.error.message } }
          if (value) apply(value)
          initialized.value = true
        }
        return { ok: true }
      } catch (cause) { if (version === revision) return failure(cause); return { ok: false } }
      finally { if (version === revision) isLoading.value = false; pending = null }
    })()
    return pending
  }
  async function mutate(operation) {
    if (pendingCheckout.value || checkoutBusy.value) return { ok: false, message: 'تحقق من نتيجة الطلب المعلّق قبل تعديل السلة.' }
    if (!cart.value && initialized.value) initialized.value = false
    if (isMutating.value) return { ok: false, message: 'انتظر اكتمال تحديث السلة.' }
    const ready = await initializeCart()
    if (!ready.ok) return ready
    if (!cart.value) return { ok: false, message: 'اختر فرعًا أولًا.' }
    if (isMutating.value) return { ok: false, message: 'انتظر اكتمال تحديث السلة.' }
    const version = revision
    isMutating.value = true; error.value = null
    try {
      const value = await operation(context())
      if (version !== revision) return { ok: false, message: 'تغيرت الجلسة؛ أعد المحاولة.' }
      apply(value); return { ok: true }
    } catch (cause) {
      if (version !== revision) return { ok: false }
      const result = failure(cause)
      if (cause.response?.status === 409) {
        try { apply(await cartApi.show(context())) } catch { /* Preserve original conflict. */ }
      }
      return result
    } finally { isMutating.value = false }
  }
  async function addItem(product, quantity = 1, options = []) {
    if (!product?.real) return { ok: false, message: 'اختر منتجًا من المنيو الحقيقي للفرع.' }
    if (product.branchUuid !== branch.selectedUuid) return { ok: false, message: 'تغير الفرع؛ أعد تحميل المنتج.' }
    if(cart.value&&cart.value.branch.id!==product.branchUuid){const result=await changeBranch(product.branchUuid);if(!result.ok)return result}
    return mutate(ctx => {
      if (product.branchUuid !== cart.value.branch.id) throw new Error('INVALID_BRANCH_CONTEXT')
      return cartApi.add({ product_uuid: product.id, quantity, options }, ctx)
    })
  }
  const updateQuantity = (id, quantity) => mutate(ctx => cartApi.quantity(id, quantity, ctx))
  const updateOptions = (id, options) => mutate(ctx => cartApi.options(id, options, ctx))
  const removeItem = id => mutate(ctx => cartApi.remove(id, ctx))
  const clearCart = () => mutate(ctx => cartApi.clear(ctx))
  const refreshCart = () => mutate(ctx => cartApi.refresh(ctx))
  const applyCoupon = code => mutate(ctx => cartApi.applyCoupon(code,ctx))
  const removeCoupon = () => mutate(ctx => cartApi.removeCoupon(ctx))
  async function requestDeliveryQuote(body) {
    let quote
    const result = await mutate(async ctx => {
      quote = await deliveryApi.quote(body, ctx)
      // Quote explicitly reprices on the server; synchronize the cart snapshot.
      return cartApi.show(ctx)
    })
    return { ...result, data: result.ok ? quote : null }
  }
  async function submitCheckout(body) {
    if (checkoutBusy.value || isMutating.value || isLoading.value) return { ok: false, busy: true }
    const attempt = pendingCheckout.value || { uuid: cart.value?.id, account: auth.token ? auth.user?.uuid : null, type: body.type, timing: body.order_timing || 'asap', scheduledFor: body.scheduled_for || null, paymentMethod: body.payment_method }
    if (!attempt.uuid || (attempt.account === null && !cartToken.value)) return { ok: false, missingCart: true }
    const wasPending = Boolean(pendingCheckout.value), version = revision, scope = checkoutScope.value
    checkoutAttempts.value[scope] = attempt
    if (!persistCheckoutAttempts(checkoutAttempts.value)) persistenceWarning.value = 'احتفظ بالصفحة مفتوحة حتى يكتمل طلبك؛ قد يتعذر استعادته إذا أعدت تحميل الصفحة.'
    checkoutBusy.value = true; isMutating.value = true
    try {
      const order = await checkoutApi.submit(body, attempt.account === null ? { token: cartToken.value } : { uuid: attempt.uuid })
      if (!order || typeof order.id !== 'string' || !order.order_number || !Array.isArray(order.items) || !order.pricing) throw new Error('INVALID_ORDER_RESPONSE')
      // Save the one-time guest credential before navigation, even if the session changed in flight.
      const persisted = attempt.account === null ? saveOrderAccess(order.id, order.access_token, { number: order.order_number, placedAt: order.placed_at, expiresAt: order.tracking?.expires_at }) : true
      if (version !== revision) return { ok: false, sessionChanged: true }
      cart.value = null; initialized.value = true; error.value = null
      if (attempt.account === null) saveToken('')
      delete checkoutAttempts.value[scope]; persistCheckoutAttempts(checkoutAttempts.value)
      return { ok: true, order, guest: attempt.account === null, persisted }
    } catch (cause) {
      const normalized = normalizeApiError(cause)
      const raw = cause.response?.data?.errors
      const businessIssues = normalized.status === 422 && raw ? Object.values(raw).flat().filter(issue => issue && typeof issue === 'object' && typeof issue.message === 'string').map(issue => ({ code: issue.code, message: issue.message })) : []
      // Action-level 422 follows the existing-order lookup; it confirms there is no converted order.
      const finalRejection = normalized.status === 422 && (businessIssues.length > 0 || raw?.cart)
      // Network/5xx may happen after commit. Retry against the same cart, never a new cart.
      const uncertain = !normalized.status || normalized.status >= 500 || cause.message?.startsWith('INVALID_') || (wasPending && !finalRejection)
      if (!uncertain && version === revision) { delete checkoutAttempts.value[scope]; persistCheckoutAttempts(checkoutAttempts.value) }
      return { ok: false, error: normalized, uncertain, businessIssues }
    } finally { checkoutBusy.value = false; isMutating.value = false }
  }
  async function changeBranch(uuid, clear = false) {
    if (pendingCheckout.value || checkoutBusy.value) return { ok: false, message: 'تحقق من نتيجة الطلب المعلّق قبل تغيير الفرع.' }
    const ready = await initializeCart()
    if (!ready.ok) return ready
    if (!cart.value) { branch.syncCartBranch(uuid); initialized.value = false; return initializeCart() }
    if (cart.value.branch.id === uuid) return { ok: true }
    if (items.value.length && !clear) return { ok: false, message: 'يجب إفراغ السلة قبل تغيير الفرع.' }
    const version = revision
    return mutate(async ctx => {
      if (items.value.length) {
        const cleared = await cartApi.clear(ctx)
        if (version !== revision) throw new Error('INVALID_SESSION_CONTEXT')
        apply(cleared)
      }
      if (version !== revision) throw new Error('INVALID_SESSION_CONTEXT')
      return cartApi.branch(uuid, ctx)
    })
  }
  async function sessionChanged() {
    revision++; cart.value = null; initialized.value = false; error.value = null
    if (pending) await pending
    isLoading.value = false
    notice.value = auth.token && cartToken.value ? 'سلتك السابقة محفوظة، ويمكنك العودة إليها بعد تسجيل الخروج.' : ''
    return initializeCart()
  }
  async function handleStorageEvent(event) {
    if (event.key === tokenKey || event.key === null) { cartToken.value = readToken(); if (!auth.token) await sessionChanged() }
  }
  try { localStorage.removeItem('shishli.cart.v1') } catch { /* Never read demo data. */ }
  return { cart, cartToken, items, itemsCount, subtotal, discount, total, issues, canCheckout, isEmpty, initialized, isLoading, isMutating, isUpdating: isMutating, error, notice, persistenceWarning, checkoutBusy, pendingCheckout, submitCheckout, initializeCart, loadCart: () => initializeCart(true), applyCoupon, removeCoupon, addItem, updateQuantity, updateOptions, removeItem, clearCart, refreshCart, requestDeliveryQuote, changeBranch, sessionChanged, handleStorageEvent }
})
