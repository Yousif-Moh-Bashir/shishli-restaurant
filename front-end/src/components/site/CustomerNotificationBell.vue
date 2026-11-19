<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import SiteIcon from './SiteIcon.vue'
import { useCustomerNotificationCount } from '../../composables/useCustomerNotificationCount'
const {data,error}=useCustomerNotificationCount()
const count=computed(()=>data.value?.unread_count)
const label=computed(()=>error.value?'إشعارات الطلبات، تعذر تحديث العدد':count.value==null?'إشعارات الطلبات':`إشعارات الطلبات، ${count.value} غير مقروءة`)
</script>
<template>
  <RouterLink class="icon-button customer-notification-bell" to="/account/notifications" :aria-label="label" :title="label">
    <SiteIcon name="bell"/>
    <span v-if="count>0&&!error" class="notification-count" aria-hidden="true">{{ count>99?'99+':count }}</span>
    <span v-if="error" class="notification-count notification-count-error" aria-hidden="true">!</span>
  </RouterLink>
</template>
<style scoped>
.customer-notification-bell{position:relative;flex-shrink:0;min-width:40px;min-height:44px}
.notification-count{position:absolute;inset-inline-end:-3px;top:-3px;display:grid;place-items:center;min-width:19px;min-height:19px;padding:1px 4px;border-radius:20px;background:var(--primary,#431520);color:white;font-size:10px;line-height:1.4;font-weight:700;border:2px solid var(--cream,#fffdfa)}
.notification-count-error{background:#8b362b}
</style>
