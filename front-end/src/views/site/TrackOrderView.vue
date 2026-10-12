<script setup>
import { reactive,ref,watch,computed,onBeforeUnmount } from 'vue'
import { RouterLink,useRoute,useRouter } from 'vue-router'
import { ordersApi } from '../../api/orders.api'
import { saveOrderAccess } from '../../utils/orderAccess'
import { normalizeApiError } from '../../utils/apiError'
import { getTrackingErrorMessage } from '../../constants/orderStatus'
import OrderTrackingPanel from '../../components/orders/OrderTrackingPanel.vue'
import SavedGuestOrders from '../../components/site/SavedGuestOrders.vue'
import { useAuthStore } from '../../stores/auth'
const auth=useAuthStore()
const route=useRoute(),router=useRouter(),form=reactive({order_number:'',token:''}),busy=ref(false),error=ref(null)
const id=computed(()=>typeof route.params.orderUuid==='string'?route.params.orderUuid:null)
let revision=0,controller,cooldownTimer
const coolingDown=ref(false)
watch(()=>route.fullPath,()=>{revision++;controller?.abort();busy.value=false;error.value=null;form.token='';form.order_number=typeof route.query.order==='string'?route.query.order.slice(0,80):''},{immediate:true})
onBeforeUnmount(()=>{revision++;controller?.abort();clearTimeout(cooldownTimer);form.token=''})
async function search(){if(busy.value||coolingDown.value)return;const current=revision,body={order_number:form.order_number.trim(),token:form.token.trim()};controller=new AbortController();busy.value=true;error.value=null;try{const value=await ordersApi.trackGuest(body,controller.signal);if(current!==revision)return;if(!value?.order?.uuid)throw Error('INVALID_TRACKING_RESPONSE');saveOrderAccess(value.order.uuid,body.token,{number:value.order.number,placedAt:value.order.placed_at});form.token='';await router.replace({name:'track-order.detail',params:{orderUuid:value.order.uuid}})}catch(cause){if(current===revision&&cause.code!=='ERR_CANCELED'){error.value=normalizeApiError(cause);if(error.value.status===429){coolingDown.value=true;cooldownTimer=setTimeout(()=>{coolingDown.value=false},Math.max(60000,Number(error.value.retryAfter||0)*1000))}}}finally{if(current===revision)busy.value=false}}
</script>
<template><main id="main" class="container checkout-main tracking-page"><RouterLink class="auth-back" to="/orders">→ العودة إلى طلباتي</RouterLink><div class="section-heading"><div><span class="section-kicker">طلبك من شيشلي</span><h1>تتبع الطلب</h1><p>الحالة والمراحل والتقديرات من المطعم.</p></div></div><OrderTrackingPanel v-if="id" :order-uuid="id"/><template v-else><SavedGuestOrders :signed-in="auth.isAuthenticated"/><form class="checkout-panel order-tracking-form" @submit.prevent="search"><label class="field">رقم الطلب<input v-model="form.order_number" name="tracking_number" placeholder="SH-0033" maxlength="80" required autocomplete="off" dir="ltr" :disabled="busy"><small>{{ error?.errors.order_number?.join(' ') }}</small></label><label class="field">رمز الوصول الخاص بالطلب<input v-model="form.token" name="tracking_token" type="password" minlength="64" maxlength="64" pattern="[a-zA-Z0-9]{64}" required autocomplete="off" :disabled="busy"><small>{{ error?.errors.token?.join(' ') }}</small></label><p>رمز الوصول الذي استلمته مع تأكيد الطلب مطلوب. لا تضعه في رابط عام.</p><button class="button button-primary auth-submit" :disabled="busy||coolingDown">{{ busy?'جارٍ البحث…':'تتبع الطلب' }}</button><p v-if="error" role="alert">{{ getTrackingErrorMessage(error.status) }}</p><RouterLink to="/account/orders">طلباتي المسجلة</RouterLink></form></template></main></template>

<style scoped>
.tracking-page :deep(.tracking-panel){padding:24px;border:1px solid var(--border);border-radius:14px;background:#fffdfa}
.order-tracking-form{max-width:540px;margin:0 auto 32px;padding:26px;border:1px solid var(--border);border-radius:12px;background:#fffdfa;display:grid;gap:20px;box-sizing:border-box}.order-tracking-form .field{display:flex;flex-direction:column;gap:8px;min-width:0;font-size:13px}.order-tracking-form input{box-sizing:border-box;width:100%;min-height:46px;padding:12px;border:1px solid var(--border);border-radius:8px;background:white;font:inherit}.order-tracking-form p{font-size:12px;color:var(--muted);line-height:1.8}.order-tracking-form .auth-submit{background:#d71920;border-color:#d71920;color:white;min-height:46px}.order-tracking-form small:empty{display:none}
@media(max-width:600px){.tracking-page :deep(.tracking-panel){padding:16px}.order-tracking-form{padding:20px}}
</style>
