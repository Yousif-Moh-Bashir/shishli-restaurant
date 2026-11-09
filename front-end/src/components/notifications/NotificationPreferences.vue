<script setup>
import AdminIcon from '../admin/AdminIcon.vue'
defineProps({ notifications: { type: Object, required: true } })
</script>
<template>
  <div class="notification-preferences">
    <small v-if="notifications.diagnostic.value" role="status">وضع اختبار محلي؛ إشعارات الطلبات التلقائية متوقفة. <RouterLink to="/admin/push-diagnostics">حالة جهاز الاختبار</RouterLink></small>
    <small v-else-if="notifications.settings.value && !notifications.settings.value.enabled" role="status">إشعارات الطلبات متوقفة حاليًا.</small>
    <button v-if="notifications.settings.value?.sound_enabled" type="button" class="sidebar-sound-toggle" :aria-pressed="notifications.sound.enabled.value" @click="notifications.sound.toggle"><AdminIcon name="bell"/>{{ notifications.sound.enabled.value ? 'كتم تنبيه الطلبات' : 'تفعيل صوت الطلبات' }}</button>
    <small v-if="notifications.sound.message.value" role="status">{{ notifications.sound.message.value }}</small>
    <template v-if="notifications.pushAvailable.value">
      <button v-if="notifications.supported.value" type="button" class="sidebar-sound-toggle" :disabled="notifications.busy.value" :aria-pressed="notifications.pushEnabled.value" @click="notifications.pushEnabled.value ? notifications.disablePush() : notifications.enablePush()">{{ notifications.pushEnabled.value ? 'إيقاف إشعارات هذا الجهاز' : notifications.diagnostic.value ? 'تفعيل إشعارات اختبار هذا الجهاز' : 'تفعيل إشعارات هذا الجهاز' }}</button>
      <small v-else>إشعارات الجهاز تحتاج متصفحًا يدعمها واتصالًا آمنًا. على iPhone أضف الموقع إلى الشاشة الرئيسية.</small>
    </template>
    <small v-if="notifications.message.value" role="status">{{ notifications.message.value }}</small>
  </div>
</template>
<style scoped>
.notification-preferences{display:grid;gap:8px}.sidebar-sound-toggle{display:flex;align-items:center;gap:8px;width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:11px;cursor:pointer}.sidebar-sound-toggle[aria-pressed=true]{color:#d71920;border-color:#d71920}small{font-size:11px;line-height:1.7}button:disabled{opacity:.6;cursor:wait}
</style>
