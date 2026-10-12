<script setup>
import { ref, watch, computed } from 'vue'
import { RouterView, RouterLink, useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { driversApi } from '../api/drivers.api'
import { driverError } from '../constants/driverDelivery'
import { useOrderPolling } from '../composables/useOrderPolling'
import SiteIcon from '../components/site/SiteIcon.vue'
import '../assets/css/driver.css'
const auth = useAuthStore(), router = useRouter(), busy = ref(false), error = ref('')
const route = useRoute()
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
<template><div class="driver-layout" dir="rtl"><header class="driver-header"><RouterLink to="/driver/orders" class="driver-brand">Şişli <span>{{ pageTitle }}</span></RouterLink><button class="login-button account-logout" type="button" :disabled="busy" :title="`${auth.user?.name || auth.driverProfile?.name || 'حساب المندوب'} · تسجيل الخروج`" @click="logout">{{ auth.user?.name || auth.driverProfile?.name || 'حساب المندوب' }} · خروج</button></header><div class="driver-session"><div><strong>{{ auth.driverProfile?.name }}</strong><p>{{ auth.driverProfile?.branch?.name }}</p></div><button class="driver-availability" :class="{ 'is-on': auth.driverProfile?.available }" :aria-pressed="!!auth.driverProfile?.available" :disabled="busy || !auth.driverProfile?.enabled" @click="availability"><span/>{{ !auth.driverProfile?.enabled ? 'موقوف عن استقبال الطلبات' : auth.driverProfile?.available ? 'متاح للطلبات' : 'غير متاح' }}</button></div><p v-if="error" class="driver-error" role="alert">{{ error }}</p><RouterView/><nav class="driver-nav" aria-label="تنقل المندوب"><RouterLink to="/driver/orders"><SiteIcon name="truck"/>الطلبات</RouterLink><RouterLink to="/driver/collections"><SiteIcon name="shopping-bag"/>التحصيلات</RouterLink><RouterLink v-if="auth.hasAdminAccess" to="/admin">الإدارة</RouterLink></nav></div></template>
