<script setup>
import { useCustomerPush } from '../../composables/useCustomerPush'
import { formatDateTime } from '../../utils/formatters'
const push = useCustomerPush()
</script>
<template>
  <section class="customer-push-settings" aria-label="إشعارات أجهزة العميل">
    <h2>إشعارات هذا الجهاز</h2><p>فعّل تنبيهًا عامًا عند تحديث طلبات حسابك. التفاصيل تبقى داخل الموقع بعد تسجيل الدخول، ولا تُرسل مراحل قديمة.</p>
    <p v-if="!push.supported.value" role="status">متصفحك لا يدعم إشعارات الجهاز هنا. تابع مركز الإشعارات؛ على iPhone قد يلزم إضافة الموقع للشاشة الرئيسية.</p>
    <p v-else-if="push.permission.value==='denied'" role="status">إذن الإشعارات مرفوض. يمكنك السماح به من إعدادات المتصفح، ثم تحديث الإعدادات.</p>
    <p v-if="push.settings.value && !push.settings.value.enabled" role="status">إشعارات الأجهزة غير مفعلة حاليًا في الموقع. سجل إشعاراتك يعمل كالمعتاد.</p>
    <p v-if="push.loading.value" role="status">جارٍ تحديث إعدادات الأجهزة…</p>
    <p v-if="push.message.value" role="status">{{ push.message.value }}</p>
    <div class="push-controls"><button type="button" class="button customer-push-enable" :disabled="push.busy.value || push.loading.value || !push.supported.value || !push.settings.value?.enabled || push.permission.value==='denied'" @click="push.enable">{{ push.busy.value?'جارٍ تحديث الجهاز…':push.enabled.value?'تجديد إشعارات هذا الجهاز':'تفعيل إشعارات هذا الجهاز' }}</button><button class="button" type="button" :disabled="push.busy.value || push.loading.value" @click="push.refresh">تحديث الإعدادات</button></div>
    <p v-if="push.enabled.value" class="push-device-active" role="status">إشعارات هذا الجهاز مفعلة.</p>
    <ul v-if="push.settings.value?.devices.length" class="push-device-list" aria-label="أجهزتك المشتركة"><li v-for="(device,index) in push.settings.value.devices" :key="device.id"><span>{{ push.binding()?.id===device.id?'هذا الجهاز':'جهاز مشترك '+(index+1) }} · {{ formatDateTime(device.created_at) }}</span><button class="button customer-push-remove" type="button" :disabled="push.busy.value" @click="push.remove(device.id)">إيقاف إشعارات الجهاز</button></li></ul>
  </section>
</template>
<style scoped>
.customer-push-settings{padding:20px;border:1px solid var(--border);border-radius:12px;background:var(--cream);margin-block:20px;min-width:0}.customer-push-settings h2{font-size:18px}.customer-push-settings p{font-size:13px;line-height:1.9}.push-controls{display:flex;flex-wrap:wrap;gap:10px}.push-controls button,.push-device-list button{min-height:44px}.push-device-list{list-style:none;padding:0}.push-device-list li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-block:12px;border-top:1px solid var(--border);font-size:13px}@media(max-width:560px){.customer-push-settings{padding:14px}.push-controls button{width:100%}.push-device-list li{flex-wrap:wrap}}
</style>
