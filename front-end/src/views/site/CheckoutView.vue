<script setup>
import PhoneInput from '../../components/common/PhoneInput.vue'
import { localPhone } from '../../utils/phone'
import AppSkeleton from "../../components/common/AppSkeleton.vue"
import { ref, reactive, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import { RouterLink, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useCartStore } from '../../stores/cart'
import { useAuthStore } from '../../stores/auth'
import { useBranchStore } from '../../stores/branch'
import { addressFields, addressForm, addressPayload, quoteAddress, completeAddress } from '../../constants/addressFields'
import { useDeliveryQuote } from '../../composables/useDeliveryQuote'
import { storeToRefs } from 'pinia'
import { usePublicSettingsStore } from '../../stores/publicSettings'
import { useOperationsStore } from '../../stores/operations'
import CheckoutAddress from '../../components/site/CheckoutAddress.vue'
import AddressBook from '../../components/site/AddressBook.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'
import { formatCurrency } from '../../utils/formatters'
import {checkoutPaymentMethods,getPaymentMethodLabel} from '../../utils/paymentStatus'
const cart = useCartStore(), auth = useAuthStore(), branches = useBranchStore(), router = useRouter()
const orderType = ref(cart.pendingCheckout?.type || ''), addressMode = ref('manual')
const hasSavedAddresses = ref(false), addressesLoaded = ref(false), addressesError = ref(null)
function loadedAddresses(items) {
  hasSavedAddresses.value = items.length > 0
  if (!hasSavedAddresses.value) { selectedAddress.value = null; addressMode.value = 'manual' }
  else if (!addressesLoaded.value) addressMode.value = 'saved'
  addressesLoaded.value = true
}
const selectedAddress = ref(null), manual = reactive(addressForm()), customer = reactive({ name: auth.user?.name || '', phone: auth.user?.phone || '', email: auth.user?.email || '' })
const locationBusy = ref(false)
const locationEditor = ref(null)
async function chooseAnotherLocation() {
  addressMode.value = 'manual'
  await nextTick()
  locationEditor.value?.openMap()
}
const notice = ref(''), addressBook = ref(null), paymentMethod = ref(cart.pendingCheckout?.paymentMethod || 'cash'), notes = ref(''), orderTiming = ref(cart.pendingCheckout?.timing || 'asap'), scheduledFor = ref(''), submitError = ref(null), businessIssues = ref([])
let retryBody = null
const retrySeconds = ref(0)
let retryTimer
function stopRetryTimer() { clearInterval(retryTimer); retrySeconds.value=0 }
function throttleRetry(value) {
  stopRetryTimer()
  const numeric=Number(value), until=Number.isFinite(numeric) && numeric>0 ? Date.now()+numeric*1000 : Date.parse(value)
  if (!Number.isFinite(until) || until<=Date.now()) return
  const tick=()=>{retrySeconds.value=Math.max(0,Math.ceil((until-Date.now())/1000));if(!retrySeconds.value)clearInterval(retryTimer)}
  tick();retryTimer=setInterval(tick,250)
}
onBeforeUnmount(stopRetryTimer)
const settings=usePublicSettingsStore(),operations=useOperationsStore(),checking=ref(false)
const {data:config,loading:configLoading,error:configError}=storeToRefs(settings)
const loadSettings=()=>settings.load(true)
void settings.load(); void cart.initializeCart()
const branch = computed(() => branches.branches.find(item => item.id === cart.cart?.branch.id))
watch(() => [customer.name, customer.phone], ([name, phone]) => {
  manual.recipient_name = name; manual.phone = phone
}, { immediate: true, flush: 'sync' })
let defaultCity = ''
watch(() => cart.cart?.branch.id, (id, previous) => {
  if (previous && id !== previous) {
    manual.latitude = ''; manual.longitude = ''; manual.district = ''
  }
}, { flush: 'sync' })
watch(() => [branch.value?.id, branch.value?.name, branch.value?.location?.city, auth.token], () => {
  const city = branch.value?.location?.city || ''
  if (!manual.city || manual.city === defaultCity) manual.city = city
  defaultCity = city
  manual.recipient_name = customer.name; manual.phone = customer.phone
}, { immediate: true, flush: 'post' })
const availability = computed(() => cart.cart?.availability)
const scheduled = computed(() => orderTiming.value === 'scheduled')
const pickupAvailable = computed(() => branch.value?.supports_pickup === true && (scheduled.value ? config.value?.pickup?.enabled === true : availability.value?.pickup?.available === true))
const deliveryAvailable = computed(() => branch.value?.supports_delivery === true && (scheduled.value ? config.value?.delivery?.enabled === true : availability.value?.delivery?.available === true))
const addressReady = computed(() => addressMode.value === 'saved' ? !!selectedAddress.value : completeAddress(manual))
const source = computed(() => orderType.value !== 'delivery' ? null : addressMode.value === 'saved' ? selectedAddress.value ? { address_uuid: selectedAddress.value.id } : null : { address: quoteAddress(manual) })
const { quote, quoteError, isQuoting, requestQuote, invalidate } = useDeliveryQuote(source, orderType)
const outsideDeliveryArea = computed(() => quote.value?.issue?.code === 'ADDRESS_OUTSIDE_DELIVERY_AREA')
const deliveryDuration = computed(() => {
  const estimate = quote.value?.estimated_delivery
  const minutes = value => value != null && value !== '' && Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value) : null
  const min = minutes(estimate?.min_minutes), max = minutes(estimate?.max_minutes)
  if (min !== null && max !== null) return min === max ? `${min} دقيقة` : `${min}–${max} دقيقة`
  if (min !== null) return `من ${min} دقيقة`
  if (max !== null) return `حتى ${max} دقيقة`
  return ''
})
const fieldErrors = computed(() => submitError.value?.errors || {})
const addressCheckErrors = ref({}), addressCheckMessage = ref('')
const deliveryFieldsErrors = computed(() => ({ ...quoteError.value?.errors, ...fieldErrors.value, ...addressCheckErrors.value }))
const pending = computed(() => Boolean(cart.pendingCheckout))
const contactTouched = reactive({name:false,phone:false})
const nameError = computed(() => !customer.name.trim() ? 'اسم العميل مطلوب.' : customer.name.trim().length < 2 ? 'أدخل اسمًا من حرفين على الأقل.' : '')
const phoneError = computed(() => !customer.phone.trim() ? 'رقم الجوال مطلوب.' : !/^(?:05[0-9]{8}|(?:\+966|966)5[0-9]{8})$/.test(localPhone(customer.phone)) ? 'أدخل رقم جوال صحيح مثل 0551234567.' : '')
const contactReady = computed(() => !nameError.value && !phoneError.value)
// Current opening hours cannot decide availability at a future scheduled time; Laravel validates that time.
const cartReady = computed(() => scheduled.value ? cart.items.length > 0 && !cart.issues.length && cart.items.every(item => !item.issues?.length) && !cart.error : cart.canCheckout)
const preparationReady = computed(() => config.value && !configError.value && !configLoading.value && config.value.orders?.enabled===true && config.value.orders?.maintenance_mode===false && (!scheduled.value||config.value.orders?.allow_scheduled_orders===true) && (auth.isAuthenticated || config.value.orders?.allow_guest_checkout === true) && contactReady.value && checkoutPaymentMethods.includes(paymentMethod.value) && (!scheduled.value || scheduledFor.value) && cartReady.value && (orderType.value === 'pickup' ? pickupAvailable.value : deliveryAvailable.value && addressReady.value && quote.value?.available === true))
const recoveryReady = computed(() => pending.value && (retryBody || contactReady.value && (orderType.value === 'pickup' || addressReady.value)))
watch([pickupAvailable, deliveryAvailable], () => { if (pending.value) return; if (orderType.value === 'pickup' && !pickupAvailable.value || orderType.value === 'delivery' && !deliveryAvailable.value || !orderType.value) orderType.value = pickupAvailable.value ? 'pickup' : deliveryAvailable.value ? 'delivery' : '' }, { immediate: true })
watch(() => auth.token, () => { stopRetryTimer(); selectedAddress.value = null; addressMode.value = 'manual'; hasSavedAddresses.value = false; addressesLoaded.value = false; addressesError.value = null; Object.assign(manual, addressForm()); Object.assign(customer, { name: auth.user?.name || '', phone: auth.user?.phone || '', email: auth.user?.email || '' }); submitError.value = null; retryBody = null; notes.value = ''; notice.value = ''; orderType.value = cart.pendingCheckout?.type || '' })
watch(() => auth.user, () => { if (auth.user) Object.assign(customer, { name: auth.user.name || '', phone: auth.user.phone || '', email: auth.user.email || '' }) })
watch(() => cart.pendingCheckout, value => {
  if (!value) return
  orderType.value = value.type; orderTiming.value = value.timing || 'asap'; paymentMethod.value = value.paymentMethod || 'cash'
  if (value.scheduledFor) { const at = new Date(value.scheduledFor); if (!Number.isNaN(at.getTime())) scheduledFor.value = new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0,16) }
}, { immediate: true })
watch(manual, () => { invalidate(); addressCheckErrors.value={}; addressCheckMessage.value=''; }, { deep: true, flush: 'sync' })
watch([orderType, addressMode, orderTiming, scheduledFor], () => { invalidate(); if (orderType.value === 'pickup') selectedAddress.value = null })
async function selectAddress(record) { selectedAddress.value = record }
let quoteTimer
const quoteInputReady = computed(() => addressMode.value === 'saved' ? Boolean(selectedAddress.value) : Boolean(manual.city?.trim() && manual.district?.trim()) && (manual.latitude === '' && manual.longitude === '' ? config.value?.delivery?.require_coordinates !== true : Number.isFinite(Number(manual.latitude)) && Number.isFinite(Number(manual.longitude)) && manual.latitude !== '' && manual.longitude !== ''))
function scheduleQuote() {
  clearTimeout(quoteTimer)
  if (pending.value || orderType.value !== 'delivery' || !deliveryAvailable.value || !quoteInputReady.value || locationBusy.value || cart.isLoading || cart.isMutating || cart.checkoutBusy || quote.value || isQuoting.value) return
  quoteTimer = setTimeout(() => { if (!locationBusy.value && quoteInputReady.value && !pending.value && !cart.isMutating && !cart.isLoading && !quote.value) void requestQuote() }, 900)
}
watch([source, () => JSON.stringify(manual), () => JSON.stringify(cart.cart), locationBusy, deliveryAvailable, () => cart.isLoading, () => cart.isMutating, () => cart.checkoutBusy, orderType, addressMode], scheduleQuote, { flush: 'post', immediate: true })
onBeforeUnmount(() => clearTimeout(quoteTimer))
async function verify() {
  if (locationBusy.value || isQuoting.value || cart.checkoutBusy || cart.isLoading || cart.isMutating || !deliveryAvailable.value || pending.value) return
  addressCheckErrors.value = {}; addressCheckMessage.value = ''
  if (addressMode.value === 'saved' && !selectedAddress.value) {
    addressCheckMessage.value = 'اختر عنوانًا محفوظًا أولًا أو أدخل عنوانًا يدويًا.'
    return
  }
  if (addressMode.value === 'manual') {
    const errors = {}
    for (const field of addressFields.filter(field => !['label','recipient_name','phone'].includes(field.key))) {
      const value = String(manual[field.key] || '').trim()
      if (field.required && !value) errors['address.' + field.key] = [field.label + ' مطلوب.']
      else if (value.length > field.max) errors['address.' + field.key] = ['تجاوز الحد المسموح لهذا الحقل.']
    }
    const hasLatitude = manual.latitude !== '', hasLongitude = manual.longitude !== ''
    if (hasLatitude !== hasLongitude) errors['address.' + (hasLatitude ? 'longitude' : 'latitude')] = ['أدخل خط العرض وخط الطول معًا أو امسحهما.']
    for (const [key, limit] of [['latitude',90],['longitude',180]]) {
      if (manual[key] !== '' && (!Number.isFinite(Number(manual[key])) || Math.abs(Number(manual[key])) > limit)) errors['address.' + key] = ['اختر موقعًا صحيحًا على الخريطة.']
    }
    if (Object.keys(errors).length) {
      addressCheckErrors.value = errors; addressCheckMessage.value = 'أكمل حقول العنوان المطلوبة قبل التحقق من التوصيل.'
      await nextTick()
      const first = [...document.querySelectorAll('.checkout-address-section input, .checkout-address-section select, .checkout-address-section textarea')].find(control => errors['address.' + control.name])
      first?.focus({preventScroll:true}); first?.scrollIntoView({behavior:'smooth',block:'center'})
      return
    }
  }
  if (!addressReady.value) { addressCheckMessage.value = 'راجع عنوان التوصيل والموقع المحدد.'; return }
  await requestQuote()
  if (quoteError.value?.status === 404) { selectedAddress.value = null; void addressBook.value?.reload() }
}
function payload() {
  const body = { type: orderType.value, customer: { name: customer.name.trim(), phone: customer.phone.trim(), email: customer.email.trim() || null }, payment_method: paymentMethod.value, notes: notes.value.trim() || null, order_timing: orderTiming.value }
  if (scheduled.value) body.scheduled_for = new Date(scheduledFor.value).toISOString()
  if (orderType.value === 'delivery') Object.assign(body, addressMode.value === 'saved' ? { address_uuid: selectedAddress.value.id } : { address: addressPayload(manual, false) })
  return body
}
async function submit() {
  if (locationBusy.value || checking.value || cart.checkoutBusy || isQuoting.value || retrySeconds.value) return
  if (!retryBody) {
    contactTouched.name = true; contactTouched.phone = true
    const errors = {}, controls = [...document.querySelectorAll('.checkout-fields input, .checkout-fields select, .checkout-fields textarea')]
    if (nameError.value) errors['customer.name'] = [nameError.value]
    if (phoneError.value) errors['customer.phone'] = [phoneError.value]
    for (const control of controls) {
      if (control.disabled || !control.name || ['customer_name','customer_phone'].includes(control.name)) continue
      const blank = control.required && !control.value.trim()
      if (!blank && control.checkValidity()) continue
      const key = control.name === 'customer_email' ? 'customer.email' : control.closest('.address-fields') ? 'address.' + control.name : control.name
      errors[key] = [blank ? 'هذا الحقل مطلوب.' : control.type === 'email' ? 'أدخل بريدًا إلكترونيًا صحيحًا.' : 'راجع قيمة هذا الحقل.']
    }
    if (Object.keys(errors).length) {
      submitError.value = {message:'أكمل الحقول المطلوبة وصحح البيانات الموضحة أدناه.',errors}
      await nextTick()
      const first = controls.find(control => errors[control.name === 'customer_name' ? 'customer.name' : control.name === 'customer_phone' ? 'customer.phone' : control.name === 'customer_email' ? 'customer.email' : control.closest('.address-fields') ? 'address.' + control.name : control.name])
      first?.focus({preventScroll:true}); first?.scrollIntoView({behavior:'smooth',block:'center'})
      return
    }
  }
  if (!(preparationReady.value || recoveryReady.value)) {
    notice.value = orderType.value === 'delivery' && !addressReady.value ? 'اختر عنوان التوصيل أو أكمل بيانات العنوان المطلوبة.' : orderType.value === 'delivery' && quote.value?.available !== true ? 'تحقق من إمكانية التوصيل إلى العنوان قبل تأكيد الطلب.' : 'راجع حالة الفرع وطريقة الاستلام قبل تأكيد الطلب.'
    return
  }
  if(!pending.value){
    checking.value=true
    const session=auth.token,id=cart.cart?.id,branchId=cart.cart?.branch.id,requestedType=orderType.value
    try{
      const [current,status]=await Promise.all([settings.load(true),operations.load(branchId,true),branches.refresh(branchId)])
      if(session!==auth.token||id!==cart.cart?.id){notice.value='تغيرت الجلسة أو السلة. راجع بيانات الطلب.';return}
      if(orderType.value!==requestedType){notice.value='تغيّرت طرق الاستلام المتاحة. اختر طريقة الاستلام وراجع الطلب مجددًا.';return}
      if(!current||!status){notice.value='تعذر التحقق من حالة التشغيل. أعد المحاولة.';return}
      if(!scheduled.value&&status[orderType.value]?.available!==true){notice.value=status.reason?.message||'طريقة الاستلام غير متاحة حاليًا.';invalidate();await cart.loadCart();return}
      if(!preparationReady.value){notice.value='تغيّرت إعدادات استقبال الطلبات. راجع حالة الفرع وطريقة الاستلام.';return}
    }catch{notice.value='تعذر التحقق من الفرع. حدّث حالة التشغيل وأعد المحاولة.';return}
    finally{checking.value=false}
  }
  submitError.value = null; businessIssues.value = []; notice.value = ''
  let body
  try { body = retryBody || payload() } catch { submitError.value = { message: 'راجع وقت الجدولة والعنوان.', errors: {} }; return }
  retryBody = body
  const result = await cart.submitCheckout(body)
  if (result.ok) {
    if (result.guest && !result.persisted) notice.value = 'تم إنشاء الطلب، لكن تعذر حفظ رمز الوصول في هذه الجلسة.'
    await router.replace({ name: 'order-success', params: { orderUuid: result.order.id } })
  } else if (result.error) {
    submitError.value = result.error; businessIssues.value = result.businessIssues || []
    // Cross-origin deployments may hide Retry-After; wait one audited rate-limit window in that case.
    if(result.error.status===429) throttleRetry(result.error.retryAfter || 60)
    if (result.uncertain) { if (result.error.status===422) retryBody=null; notice.value = 'قد يكون الطلب أُنشئ بالفعل. أعد التحقق باستخدام السلة نفسها؛ لن تُنشأ سلة بديلة. بعد تحديث الصفحة أعد إدخال بيانات الطلب للتحقق.' }
    else {
      retryBody = null
      if (result.error.status === 422) { invalidate(); await Promise.all([cart.loadCart(),settings.load(true),operations.load(cart.cart?.branch.id,true)]) }
      if (result.error.status === 404) { invalidate(); selectedAddress.value=null; void addressBook.value?.reload(); notice.value='العنوان أو السلة لم يعد متاحًا. راجع بيانات العنوان والسلة قبل إعادة المحاولة.' }
    }
  } else if (result.sessionChanged) notice.value = 'تغيرت الجلسة أثناء إرسال الطلب. ارجع إلى الحساب الذي أرسل الطلب للتحقق من النتيجة.'
  else notice.value = 'تعذر تحديد السلة. أعد تحميلها.'
}
onBeforeRouteLeave(() => !cart.checkoutBusy&&!checking.value)
</script>
<template>
  <main id="main" class="container checkout-main checkout-page" :aria-busy="cart.checkoutBusy">
    <RouterLink class="auth-back" to="/cart">→ العودة للسلة</RouterLink>
    <div class="section-heading"><div><span class="section-kicker">طلبك من شيشلي</span><h1>تأكيد الطلب</h1><p>يراجع المطعم الأسعار والتوفر والتوصيل عند التأكيد.</p></div></div>
    <AppSkeleton v-if="cart.isLoading || configLoading" variant="detail" label="جارٍ تحميل السلة وإعدادات الطلب…"/>
    <div v-if="configError" role="alert" class="auth-status is-error"><p>{{ configError.message }}</p><button class="button" @click="loadSettings">إعادة تحميل إعدادات الطلب</button></div>
    <div v-if="cart.error" role="alert"><p>{{ cart.error.message }}</p><button class="button" :disabled="cart.isLoading || cart.checkoutBusy" @click="cart.loadCart()">إعادة تحميل السلة</button></div>
    <p v-if="cart.persistenceWarning" role="alert">{{ cart.persistenceWarning }}</p>
    <p v-if="pending" class="auth-status" role="alert">هناك محاولة طلب تحتاج إلى التحقق. استخدم زر «التحقق من نتيجة الطلب» لاسترجاع الطلب من السلة نفسها. بعد تحديث الصفحة أعد إدخال بيانات الطلب؛ لا نحتفظ ببياناتك الشخصية.</p>
    <section v-if="cart.isEmpty && !cart.isLoading && !pending" class="checkout-empty"><h2>سلتك فارغة</h2><RouterLink class="button button-primary" to="/menu">تصفح المنيو</RouterLink></section>
    <div v-if="cart.items.length || pending" class="checkout-layout">
      <section class="checkout-panel checkout-data-panel">
        <div>
          <fieldset class="checkout-fields" :disabled="checking || cart.checkoutBusy"><legend class="checkout-data-title">بيانات الطلب</legend>
            <section class="checkout-step checkout-receipt-step"><div class="checkout-step-heading"><span class="checkout-step-number">1</span><div><h2>طريقة الاستلام</h2><p>اختر الطريقة المناسبة لطلبك.</p></div></div><p class="checkout-branch-name"><SiteIcon name="map-pin"/>{{ cart.cart?.branch.name }}</p><p v-if="availability?.reason" class="catalog-note">{{ availability.reason.message }}</p>
            <label v-if="config?.orders?.allow_scheduled_orders" class="field">توقيت الطلب<select v-model="orderTiming" name="order_timing" form="checkout-submit-form" :disabled="pending"><option value="asap">في أقرب وقت</option><option value="scheduled">طلب مجدول</option></select></label>
            <label v-if="scheduled" class="field">موعد الطلب بتوقيت جهازك<input v-model="scheduledFor" name="scheduled_for" form="checkout-submit-form" type="datetime-local" required :disabled="pending && !!retryBody"><small>اختر موعدًا خلال {{ config?.orders?.max_scheduling_days }} أيام، ضمن أوقات عمل الفرع.</small><small class="field-error">{{ fieldErrors.scheduled_for?.join(' ') }}</small></label>
            <div class="order-type-options" role="group" aria-label="طريقة الاستلام"><label v-if="pickupAvailable || pending && orderType==='pickup'" class="checkout-fulfillment-option" :class="{'is-selected':orderType==='pickup'}"><input v-model="orderType" type="radio" value="pickup" name="order_type" :disabled="pending"><SiteIcon name="shopping-bag"/><span><strong>استلام من الفرع</strong><small>استلم طلبك من فرع المطعم.</small></span></label><label v-if="deliveryAvailable || pending && orderType==='delivery'" class="checkout-fulfillment-option" :class="{'is-selected':orderType==='delivery'}"><input v-model="orderType" type="radio" value="delivery" name="order_type" :disabled="pending"><SiteIcon name="truck"/><span><strong>توصيل إلى عنوانك</strong><small>تحقق من التوصيل بعد اختيار العنوان.</small></span></label></div>
            <p v-if="checking" role="status">جارٍ تحديث إعدادات الطلب وحالة الفرع.</p><p v-if="config?.orders?.maintenance_mode" role="alert">{{ config.orders.maintenance_message }}</p><p v-else-if="config?.orders?.enabled===false" role="alert">استقبال الطلبات متوقف حاليًا.</p><p v-if="!orderType">لا توجد طريقة استلام متاحة حاليًا.</p>
            <div v-if="orderType==='pickup' && branch" class="checkout-pickup-location"><SiteIcon name="map-pin"/><div><strong>{{ branch.name }}</strong><p>{{ [branch.location?.city,branch.location?.district,branch.location?.address].filter(Boolean).join('، ') }}</p><RouterLink to="/branches" class="text-button">تفاصيل الفرع</RouterLink></div></div>
            </section><section class="checkout-step checkout-contact-step"><div class="checkout-step-heading checkout-step-divider"><span class="checkout-step-number">2</span><div><h3>بيانات التواصل</h3><p>لنتمكن من التواصل معك بخصوص الطلب.</p></div></div>
            <div class="form-grid"><label class="field">اسم العميل *<input v-model="customer.name" name="customer_name" :aria-invalid="Boolean(fieldErrors['customer.name'] || contactTouched.name && nameError)" @blur="contactTouched.name=true" minlength="2" autocomplete="name" placeholder="الاسم الكامل" form="checkout-submit-form" maxlength="150" required :disabled="pending && !!retryBody"><small class="field-error">{{ fieldErrors['customer.name']?.join(' ') || (contactTouched.name?nameError:'') }}</small></label><label class="field">رقم الجوال *<PhoneInput v-model="customer.phone" name="customer_phone" :aria-invalid="Boolean(fieldErrors['customer.phone'] || contactTouched.phone && phoneError)" @blur="contactTouched.phone=true" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="05xxxxxxxx" form="checkout-submit-form" type="tel" required :disabled="pending && !!retryBody"/><small class="field-error">{{ fieldErrors['customer.phone']?.join(' ') || (contactTouched.phone?phoneError:'') }}</small></label><label class="field">البريد الإلكتروني (اختياري)<input v-model="customer.email" name="customer_email" autocomplete="email" dir="ltr" placeholder="name@example.com" form="checkout-submit-form" type="email" maxlength="255" :disabled="pending && !!retryBody"><small class="field-error">{{ fieldErrors['customer.email']?.join(' ') }}</small></label></div>
            </section><section v-if="orderType==='delivery'" class="checkout-step checkout-address-section" aria-label="عنوان التوصيل">
              <div class="checkout-step-heading checkout-step-divider"><SiteIcon name="map-pin"/><div><h3>عنوان التوصيل</h3><p>اختر عنوانًا محفوظًا أو أضف عنوان هذا الطلب.</p></div></div>
              <div v-if="auth.isAuthenticated && hasSavedAddresses" class="form-actions"><label class="check"><input v-model="addressMode" type="radio" value="saved" name="address_mode" :disabled="pending && !!retryBody">عنوان محفوظ</label><label class="check"><input v-model="addressMode" type="radio" value="manual" name="address_mode" :disabled="pending && !!retryBody">عنوان يدوي لهذا الطلب</label><RouterLink to="/account/addresses">إدارة عناويني</RouterLink></div>
              <AppSkeleton v-if="auth.isAuthenticated && !addressesLoaded && !addressesError" variant="inline" label="جارٍ تحميل عناوينك…"/>
              <p v-if="auth.isAuthenticated && addressesError" class="catalog-note" role="status">تعذر تحميل العناوين المحفوظة. يمكنك إدخال عنوان جديد أو <button type="button" class="text-button" @click="addressBook?.reload()">إعادة المحاولة</button>.</p>
              <AddressBook v-if="auth.isAuthenticated" v-show="addressMode==='saved'" @load-error="addressesError=$event" @loaded="loadedAddresses" ref="addressBook" selectable :selected-id="selectedAddress?.id" @selected="selectAddress" @changed="invalidate" />
              <CheckoutAddress v-if="addressMode==='manual' && (!auth.isAuthenticated || addressesLoaded || addressesError)" ref="locationEditor" :key="cart.cart?.branch.id + auth.token" :form="manual" :errors="deliveryFieldsErrors" :center="branch?.location" :coverage="branch?.delivery_coverage" :require-coordinates="config?.delivery?.require_coordinates === true" :quote="quote" :quote-error="quoteError" :quoting="isQuoting" :disabled="checking || cart.checkoutBusy || pending && !!retryBody" @busy="locationBusy=$event" @changed="invalidate" @confirm="verify" />
              <p class="catalog-note">العنوان اليدوي لهذا الطلب فقط، ولا يُحفظ في حسابك.</p>
              <AppSkeleton v-if="isQuoting" variant="inline" label="جارٍ تحميل رسوم ومدة التوصيل…"/><button v-if="!pending && addressMode==='saved'" type="button" class="button button-primary" :disabled="locationBusy || !deliveryAvailable || isQuoting || cart.checkoutBusy || cart.isLoading || cart.isMutating" @click="verify">{{ isQuoting ? 'جارٍ التحقق من التوصيل…' : 'التحقق من إمكانية التوصيل' }}</button>
              <p v-if="addressCheckMessage" class="field-error" role="alert">{{ addressCheckMessage }}</p><p>النتيجة تقديرية؛ يُعاد حساب رسوم التوصيل عند تأكيد الطلب.</p>
              <div v-if="quoteError" role="alert"><h3>تعذر التحقق من التوصيل</h3><p>{{ quoteError.message }}</p><p v-for="(messages,key) in quoteError.errors" :key="key">{{ messages.join(' ') }}</p><p>هذا خطأ تحقق أو اتصال، ولا يعني أن العنوان خارج النطاق.</p></div>
              <section v-if="quote" class="delivery-quote checkout-delivery-result" :class="quote.available ? 'is-available' : 'is-unavailable'" role="status">
                <div class="delivery-result-heading">
                  <span class="delivery-result-icon"><SiteIcon :name="quote.available ? 'badge-check' : 'map-pin'"/></span>
                  <div><h3>{{ quote.available ? 'التوصيل متاح لهذا العنوان' : 'لا يمكن التوصيل إلى هذا العنوان حاليًا' }}</h3><p v-if="quote.issue">{{ outsideDeliveryArea ? (pickupAvailable ? 'موقعك خارج منطقة توصيل الفرع. اختر موقعًا آخر أو استلم طلبك من الفرع.' : 'موقعك خارج منطقة توصيل الفرع. اختر موقعًا آخر.') : quote.issue.message }}</p></div>
                </div>
                <dl v-if="!outsideDeliveryArea && (quote.zone || quote.available || Number(quote.minimum_order)>0 || deliveryDuration)" class="delivery-result-details">
                  <div v-if="quote.zone"><dt>منطقة التوصيل</dt><dd>{{ quote.zone.name }}</dd></div>
                  <div v-if="quote.available && quote.delivery_fee!=null" class="delivery-result-fee"><dt>رسوم التوصيل</dt><dd>{{ Number(quote.delivery_fee)===0 ? 'مجانًا' : formatCurrency(quote.delivery_fee) }}</dd></div>
                  <div v-if="config?.delivery?.show_minimum_order === true && Number(quote.minimum_order)>0"><dt>الحد الأدنى للطلب</dt><dd>{{ formatCurrency(quote.minimum_order) }}</dd></div>
                  <div v-if="deliveryDuration"><dt>مدة التوصيل المتوقعة</dt><dd>{{ deliveryDuration }}</dd></div>
                </dl>
                <p v-if="config?.delivery?.show_minimum_order === true && !outsideDeliveryArea && quote.minimum_order_met===false && Number(quote.remaining_amount)>0" class="delivery-result-note">أضف {{ formatCurrency(quote.remaining_amount) }} لإكمال الحد الأدنى للطلب.</p>
                <p v-if="!outsideDeliveryArea && quote.free_delivery_applied" class="delivery-result-note">التوصيل مجاني لطلبك.</p>
                <div v-if="outsideDeliveryArea" class="delivery-result-actions"><button type="button" class="button button-primary" @click="chooseAnotherLocation">اختيار موقع آخر</button><button v-if="pickupAvailable" type="button" class="button" @click="orderType='pickup'">الاستلام من الفرع</button></div>
              </section>
            </section>
            <section class="checkout-step checkout-payment-step"><div class="checkout-step-heading checkout-step-divider"><span class="checkout-step-number">3</span><div><h3>الدفع والملاحظات</h3><p>راجع طريقة الدفع وأضف تعليماتك للمطعم.</p></div></div>
            <label class="field">طريقة الدفع<select v-model="paymentMethod" name="payment_method" form="checkout-submit-form" required :disabled="pending && !!retryBody"><option v-for="method in checkoutPaymentMethods" :key="method" :value="method">{{ getPaymentMethodLabel(method) }}</option></select><small>الدفع عند الاستلام نقدًا أو عبر الشبكة لدى المطعم. يُسجل الدفع بعد التحصيل الفعلي.</small><small class="field-error">{{ fieldErrors.payment_method?.join(' ') }}</small></label>
            <label class="field">ملاحظات للمطعم (اختياري)<textarea v-model="notes" name="order_notes" rows="3" placeholder="مثال: الصوص على الجانب" form="checkout-submit-form" maxlength="1000" :disabled="pending && !!retryBody"></textarea><small class="field-error">{{ fieldErrors.notes?.join(' ') }}</small></label>
            </section>
          </fieldset>
          <p v-if="!auth.isAuthenticated && config?.orders?.allow_guest_checkout===false" role="alert">يلزم تسجيل الدخول لإتمام الطلب. <RouterLink to="/login?redirect=/checkout">تسجيل الدخول</RouterLink></p>
          <div v-if="submitError" role="alert" class="auth-status is-error"><p>{{ submitError.message }}</p><p v-for="(messages,key) in fieldErrors" :key="key">{{ messages.join(' ') }}</p><p v-for="(issue,index) in businessIssues" :key="index">{{ issue.message }}</p><p v-if="submitError.retryAfter">انتظر {{ submitError.retryAfter }} ثانية قبل إعادة المحاولة.</p></div>
          <p v-for="(issue,index) in cart.issues" :key="index" role="alert">{{ issue.message }}</p><template v-for="item in cart.items" :key="item.id"><p v-for="(issue,index) in item.issues" :key="index" role="alert">{{ item.product?.name }}: {{ issue.message }}</p></template>
          <form id="checkout-submit-form" novalidate @submit.prevent="submit"><p class="checkout-confirm-hint">راجع بيانات الطلب وطريقة الاستلام، ثم أكد طلبك.</p><button class="button button-primary auth-submit" type="submit" :disabled="locationBusy || (!pending && orderType==='delivery' && (!addressReady || quote?.available!==true)) || checking || cart.checkoutBusy || isQuoting || retrySeconds>0 || (!pending && (!cartReady || !orderType || configLoading || !config || configError))"><SiteIcon name="badge-check"/>{{ checking ? 'جارٍ التحقق من حالة الفرع…' : cart.checkoutBusy ? 'جارٍ تأكيد الطلب…' : pending ? 'التحقق من نتيجة الطلب' : 'تأكيد الطلب' }}</button></form><p v-if="retrySeconds" role="status">يمكن إعادة المحاولة بعد {{ retrySeconds }} ثانية.</p>
          <p role="status">{{ notice }}</p>
        </div>
      </section>
      <aside class="checkout-panel checkout-summary" aria-label="مراجعة الطلب">
        <div class="checkout-review-heading"><span><SiteIcon name="shopping-bag"/></span><div><h2>مراجعة طلبك</h2><p>{{ cart.itemsCount }} قطعة{{ cart.cart?.branch.name?' · '+cart.cart.branch.name:'' }}</p></div><RouterLink v-if="!pending&&!cart.checkoutBusy" class="text-button" to="/cart">تعديل</RouterLink></div>
        <div class="checkout-review-items"><article v-for="item in cart.items" :key="item.id" class="checkout-review-item"><span class="checkout-review-quantity">{{ item.quantity }}×</span><div><h3>{{ item.product?.name }}</h3><p v-for="(option,index) in item.options" :key="index">{{ option.group.name }}: {{ option.value.name }}</p></div><strong>{{ formatCurrency(item.pricing.line_total_after_discount??item.pricing.line_total) }}</strong></article></div>
        <dl v-if="cart.cart" class="checkout-review-pricing"><div><dt>إجمالي المنتجات</dt><dd>{{ formatCurrency(cart.subtotal) }}</dd></div><div v-if="Number(cart.discount)>0" class="checkout-review-discount"><dt>الخصم</dt><dd>− {{ formatCurrency(cart.discount) }}</dd></div><div v-if="orderType==='delivery'&& quote?.available"><dt>التوصيل المقدر</dt><dd>{{ formatCurrency(quote.delivery_fee) }}</dd></div></dl>
        <div v-if="cart.cart" class="checkout-review-total"><div><span>{{ orderType==='delivery'&& quote?.available?'الإجمالي التقديري':'إجمالي السلة' }}</span><small>{{ orderType==='delivery'&&!quote?.available?'رسوم التوصيل بعد التحقق من العنوان':'تُراجع الأسعار عند التأكيد' }}</small></div><strong>{{ formatCurrency(orderType==='delivery'&& quote?.available?quote.estimated_total:cart.total) }}</strong></div>
        <div v-if="orderType" class="checkout-review-method"><SiteIcon :name="orderType==='delivery'?'truck':'shopping-bag'"/><span>{{ orderType==='delivery'?'توصيل إلى عنوانك':'استلام من الفرع' }}</span><small>{{ getPaymentMethodLabel(paymentMethod) }}</small></div>
        <p class="checkout-review-note">راجع الأطباق وبيانات الاستلام قبل التأكيد. يعتمد الطلب على الأسعار والتوفر الحاليين لدى المطعم.</p>
      </aside>
    </div>
  </main>
</template>
<style scoped>
.checkout-fields{border:0;padding:0;margin:0;min-width:0;width:100%}
.checkout-fields :deep(.field){display:flex;flex-direction:column;align-items:stretch;gap:8px;min-width:0;margin:0}
.checkout-fields>.field{margin-block:18px}
.checkout-fields :deep(input:not([type=radio]):not([type=checkbox])),.checkout-fields :deep(select),.checkout-fields :deep(textarea){display:block;width:100%;min-width:0;max-width:100%;box-sizing:border-box}
.checkout-fields>.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-block:18px}
.checkout-fields :deep(.field>small){display:block;white-space:normal;overflow-wrap:anywhere;line-height:1.7}
.checkout-fields :deep(.field-error:empty){display:none}
.checkout-address-section{display:block;min-width:0;margin-block:24px;clear:both}
.checkout-address-section :deep(.address-fields){width:100%;min-width:0;gap:18px;margin-block:18px}
.checkout-address-section :deep(.field-wide){grid-column:1/-1;min-width:0}
.checkout-address-section :deep(.field-wide>.text-button){display:inline-flex;margin:0 0 10px 14px;min-height:36px}
.checkout-address-section>p{font-size:12px;line-height:1.8;color:var(--muted);margin-block:12px}
.checkout-address-section>p.field-error{color:#a42b2b}
.checkout-fields :deep(input[aria-invalid=true]){border-color:#d71920;background:#fffafa}.field-error{color:#a42b2b}.checkout-panel form{min-width:0}
.checkout-page.checkout-main .checkout-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px;align-items:start}
.checkout-page.checkout-main .checkout-data-panel{padding:0;background:transparent;border:0;box-shadow:none;min-width:0}
.checkout-page.checkout-main .checkout-data-title{padding-bottom:16px}
.checkout-step{display:flow-root;min-width:0;border:1px solid var(--border);border-radius:12px;background:#fffdfa;padding:22px;margin:0 0 20px;box-sizing:border-box}
.checkout-step .checkout-step-heading{margin:0 0 18px;padding:0;border:0;align-items:flex-start}
.checkout-step .form-grid,.checkout-step :deep(.address-fields){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:18px 0 0;align-items:start}
.checkout-contact-step .field:last-child{grid-column:1/-1}
.checkout-step>.field{margin:0 0 18px}
.checkout-step>.field:last-child{margin-bottom:0}
.checkout-fields :deep(input:not([type=radio]):not([type=checkbox])),.checkout-fields :deep(select),.checkout-fields :deep(textarea){font:inherit;font-size:14px;line-height:1.6;min-height:46px;padding:11px 12px;border:1px solid var(--border);border-radius:8px;background:white;color:var(--primary-dark);position:static}
.checkout-fields :deep(textarea){resize:vertical;min-height:100px}
.checkout-step :deep(.field){position:static;float:none;height:auto;white-space:normal}
.checkout-address-section{margin:0 0 20px}
.checkout-address-section>.button{width:100%;justify-content:center;white-space:normal;min-height:46px;margin-top:8px}
.checkout-page.checkout-main #checkout-submit-form{margin:0;padding:20px;border:1px solid var(--border);border-radius:12px;background:#fffdfa}
.checkout-page.checkout-main .checkout-review-item{display:grid;grid-template-columns:30px minmax(0,1fr);gap:8px 10px}
.checkout-page.checkout-main .checkout-review-item>strong{grid-column:2;white-space:normal;overflow-wrap:anywhere;padding:0}
.checkout-page.checkout-main .checkout-summary{max-height:none;align-self:start}
.checkout-delivery-result{padding:18px;border-radius:12px;background:#fff;border-color:var(--border);overflow-wrap:anywhere}
.checkout-delivery-result.is-available{background:#f5faf6;border-color:#d2e5d7}
.checkout-delivery-result.is-unavailable{background:#fff8f2;border-color:#eed9c5}
.delivery-result-heading{display:flex;align-items:flex-start;gap:12px}
.delivery-result-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:50%;background:#e2f0e6;color:#287345}
.is-unavailable .delivery-result-icon{background:#f8e9da;color:#99612b}
.delivery-result-icon .icon{width:20px;height:20px}
.delivery-result-heading>div{min-width:0}
.checkout-delivery-result .delivery-result-heading h3{margin:4px 0 0;font-size:15px;font-weight:700;line-height:1.7;color:#28633e}
.is-unavailable .delivery-result-heading h3{color:var(--primary-dark)}
.checkout-delivery-result .delivery-result-heading p{margin:5px 0 0;color:var(--muted);font-size:13px;line-height:1.8}
.delivery-result-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:16px 0 0;padding-top:14px;border-top:1px solid #dce8df}
.delivery-result-fee dd{color:#28633e;font-weight:700}
.delivery-result-details>div{min-width:0}
.delivery-result-details dt{font-size:12px;color:var(--muted);line-height:1.7}
.delivery-result-details dd{margin:4px 0 0;color:var(--primary-dark);font-size:14px;font-weight:600;line-height:1.7}
.checkout-delivery-result .delivery-result-note{margin:12px 0 0;font-size:13px;line-height:1.8;color:var(--primary-dark)}
.delivery-result-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.delivery-result-actions .button{min-height:42px;font-size:13px;white-space:normal;justify-content:center}
@media(max-width:480px){.checkout-delivery-result{padding:14px}.delivery-result-details{grid-template-columns:minmax(0,1fr);gap:10px}.delivery-result-details>div{display:flex;justify-content:space-between;align-items:baseline;gap:12px}.delivery-result-details dd{margin:0;text-align:end}.delivery-result-actions .button{flex:1 1 140px}}
@media(max-width:1050px){.checkout-page.checkout-main .checkout-layout{grid-template-columns:minmax(0,1fr)}.checkout-page.checkout-main .checkout-summary{position:static}}
@media(max-width:700px){.checkout-page.checkout-main .checkout-step{padding:18px;margin-bottom:16px}.checkout-step .form-grid,.checkout-step :deep(.address-fields){grid-template-columns:minmax(0,1fr);gap:16px}.checkout-page.checkout-main .checkout-layout{gap:16px}.checkout-page.checkout-main .checkout-panel.checkout-data-panel{padding:0}.checkout-page.checkout-main #checkout-submit-form{padding:18px}}
@media(max-width:700px){.checkout-fields>.form-grid,.checkout-address-section :deep(.address-fields){grid-template-columns:minmax(0,1fr);gap:16px}}
</style>


