<script setup>
import { ref, watch, computed } from 'vue'
import { RouterView, RouterLink, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { driversApi } from '../api/drivers.api'
import { driverError } from '../constants/driverDelivery'
import { useOrderPolling } from '../composables/useOrderPolling'
import { useNewOrderSound } from '../composables/useNewOrderSound'
import SiteIcon from '../components/site/SiteIcon.vue'
import '../assets/css/driver.css'
const auth = useAuthStore(), router = useRouter(), busy = ref(false), error = ref('')
const route = useRoute()
const canReceiveOrders = computed(() => auth.isDriver && auth.driverProfile?.enabled === true && auth.driverProfile?.available === true)
const orderSound = useNewOrderSound(() => canReceiveOrders.value, {
  identity: order => order.id ? `${order.id}:${order.preparation_status}` : null,
  storageKey: 'shishli.driverOrderSoundMuted',
})
const incomingOrders = useOrderPolling(async signal => {
  const params = { scope: 'available', period: 'current', per_page: 100 }
  const result = await driversApi.orders({ ...params, page: 1 }, signal)
  if (!result.driver_context?.can_accept) return result
  const rows = [...result.data]
  for (let page = 2; page <= result.meta.last_page; page++) {
    const next = await driversApi.orders({ ...params, page }, signal)
    if (!next.driver_context?.can_accept) return next
    rows.push(...next.data)
  }
  return { ...result, data: rows }
}, {
  enabled: () => canReceiveOrders.value, paused: () => busy.value, normalizeError: driverError,
})
watch(incomingOrders.data, value => {
  if (value?.driver_context?.can_accept) orderSound.observe(value.data || [])
})
watch([() => auth.token, () => auth.driverProfile?.branch?.id, canReceiveOrders], () => {
  incomingOrders.reset(); orderSound.reset()
  if (canReceiveOrders.value) void incomingOrders.load(true)
}, { immediate: true })
const pageTitle = computed(() => ({ 'driver.orders': 'الطلبات', 'driver.order': 'تفاصيل الطلب', 'driver.collections': 'التحصيلات' })[route.name] || 'الطلبات')
const profile = useOrderPolling(signal => driversApi.me(signal), { interval: 30000, enabled: () => auth.isDriver, paused: () => busy.value, normalizeError: driverError })
watch(profile.data, value => { if (value) auth.driverProfile = value })
watch(profile.error, value => { if (value?.status === 403) { auth.driverProfile = null; void router.replace('/forbidden') } })
void profile.load(true)
async function availability() {
  if (busy.value) return
  busy.value = true; error.value = ''; profile.reset()
  try { auth.driverProfile = await driversApi.availability(!auth.driverProfile.available) }
  catch (cause) { error.value = driverError(cause).message }
  finally { busy.value = false; void profile.load(true) }
}
async function logout() {
  if (busy.value) return
  busy.value = true; error.value = ''
  try { await auth.logout(); await router.replace('/login') }
  catch (cause) { error.value = driverError(cause).message }
  finally { busy.value = false }
}
</script>
<template><div class="driver-layout" dir="rtl"><header class="driver-header"><RouterLink to="/driver/orders" class="driver-brand">Şişli <span>{{ pageTitle }}</span></RouterLink><button class="login-button account-logout" type="button" :disabled="busy" :title="`${auth.user?.name || auth.driverProfile?.name || 'حساب المندوب'} · تسجيل الخروج`" @click="logout">{{ auth.user?.name || auth.driverProfile?.name || 'حساب المندوب' }} · خروج</button></header><div class="driver-session"><div><strong>{{ auth.driverProfile?.name }}</strong><p>{{ auth.driverProfile?.branch?.name }}</p></div><button class="driver-availability" :class="{ 'is-on': auth.driverProfile?.available }" :aria-pressed="!!auth.driverProfile?.available" :disabled="busy || !auth.driverProfile?.enabled" @click="availability"><span/>{{ !auth.driverProfile?.enabled ? 'موقوف عن استقبال الطلبات' : auth.driverProfile?.available ? 'متاح للطلبات' : 'غير متاح' }}</button></div><p v-if="error" class="driver-error" role="alert">{{ error }}</p><section class="driver-sound-controls"><button type="button" class="driver-button secondary sidebar-sound-toggle" :aria-pressed="orderSound.enabled.value" :disabled="!canReceiveOrders" @click="orderSound.toggle">{{ orderSound.enabled.value ? 'كتم تنبيه الطلبات' : 'تفعيل صوت الطلبات' }}</button><p class="driver-muted">فعّل الصوت لاستقبال تنبيه عند وصول طلب متاح أو جاهزيته أثناء فتح واجهة المندوب.</p><p v-if="orderSound.message.value" role="status">{{ orderSound.message.value }}</p><p v-if="incomingOrders.error.value" class="driver-error" role="status">تعذر تحديث تنبيهات الطلبات. <button type="button" class="driver-link" @click="incomingOrders.load()">إعادة المحاولة</button></p></section><RouterView/><nav class="driver-nav" aria-label="تنقل المندوب"><RouterLink to="/driver/orders"><SiteIcon name="truck"/>الطلبات</RouterLink><RouterLink to="/driver/collections"><SiteIcon name="shopping-bag"/>التحصيلات</RouterLink><RouterLink v-if="auth.hasAdminAccess" to="/admin">الإدارة</RouterLink></nav></div></template>
