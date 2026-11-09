<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { usePublicSettingsStore } from '../../stores/publicSettings'
import { orderingResumeMessage } from '../../utils/orderingStatus'
import SiteIcon from './SiteIcon.vue'

const settings = usePublicSettingsStore(), now = ref(Date.now())
const paused = computed(() => settings.data?.orders?.enabled === false || settings.data?.orders?.maintenance_mode === true)
const resume = computed(() => orderingResumeMessage(settings.data?.orders?.expected_resume_at, settings.data?.restaurant?.timezone, now.value))
let timer
function refresh() {
  now.value = Date.now()
  if (!document.hidden) void settings.load()
}
onMounted(() => { refresh(); timer = setInterval(refresh, 60000); document.addEventListener('visibilitychange', refresh) })
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('visibilitychange', refresh) })
</script>
<template>
  <div v-if="paused" class="ordering-pause-notice" role="status" aria-live="polite">
    <SiteIcon name="clock" /><div><strong>استقبال الطلبات متوقف مؤقتًا</strong><p v-if="resume">{{ resume }}</p><p v-else>{{ settings.data?.orders?.maintenance_mode ? settings.data.orders.maintenance_message || 'نعتذر عن التوقف المؤقت، ونرحّب بكم قريبًا.' : 'نعتذر عن التوقف المؤقت، ونرحّب بكم قريبًا.' }}</p></div>
  </div>
</template>
<style scoped>
.ordering-pause-notice{display:flex;align-items:flex-start;justify-content:center;gap:12px;padding:14px max(20px,env(safe-area-inset-right,0px)) 14px max(20px,env(safe-area-inset-left,0px));background:#fff4e5;border-bottom:1px solid #ecd8ba;color:#5a1725;line-height:1.8;text-align:right;overflow-wrap:anywhere}
.ordering-pause-notice>.icon{width:22px;height:22px;flex-shrink:0;margin-top:3px;color:#a26c25}
.ordering-pause-notice strong{font-size:14px}.ordering-pause-notice p{font-size:13px;margin:2px 0 0}
@media(max-width:480px){.ordering-pause-notice{padding-inline:16px;gap:10px}.ordering-pause-notice strong{font-size:13px}.ordering-pause-notice p{font-size:12px}}
</style>
