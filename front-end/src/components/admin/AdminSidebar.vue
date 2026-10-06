<script setup>
import { ref, computed, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AdminIcon from './AdminIcon.vue'
import logo from '../../assets/images/logo/shishli-logo.png'
import { useAuthStore } from '../../stores/auth'
import { adminNavigation, adminNavigationGroups, canAccessNavigation } from '../../constants/adminNavigation'
import { authenticatedHome } from '../../router/authGuards'
import { adminOrdersApi } from '../../api/orders.api'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { useNewOrderSound } from '../../composables/useNewOrderSound'
const auth = useAuthStore()
defineProps({ open: Boolean })
const emit = defineEmits(['navigate'])
const sidebar = ref(null)
const route = useRoute()
const collapsed = ref(new Set())
const groups = computed(() => adminNavigationGroups.map(group => ({...group,links:group.paths.map(path=>adminNavigation.find(item=>item.path===path)).filter(item=>item&&canAccessNavigation(auth,item))})).filter(group=>group.links.length))
const canViewOrders = computed(() => auth.isAuthenticated && auth.can('orders.view'))
const orderSound = useNewOrderSound(() => canViewOrders.value)
const orderCounts = useOrderPolling(async signal => {
  const [counts, recent] = await Promise.allSettled([
    adminOrdersApi.counts({}, signal),
    adminOrdersApi.list({ sort: 'created_at', direction: 'desc', per_page: 50 }, signal),
  ])
  if (signal.aborted) throw { code: 'ERR_CANCELED' }
  if (recent.status === 'fulfilled') orderSound.observe(recent.value.data)
  if (counts.status === 'rejected') throw counts.reason
  return counts.value
}, { interval: 20000, enabled: () => canViewOrders.value })
const newOrders = computed(() => {
  const count = orderCounts.data.value?.pending
  return canViewOrders.value && !orderCounts.error.value && Number.isSafeInteger(count) && count > 0 ? count : 0
})
watch([() => auth.token, canViewOrders], () => {
  orderCounts.reset()
  orderSound.reset()
  if (canViewOrders.value) void orderCounts.load()
}, { immediate: true })
const active = path => path === '/admin' ? route.path === path : route.path === path || route.path.startsWith(path+'/')
const expanded = group => group.links.some(link=>active(link.path)) || !collapsed.value.has(group.key)
function toggle(group){const next=new Set(collapsed.value);next.has(group.key)?next.delete(group.key):next.add(group.key);collapsed.value=next}
defineExpose({ contains: target => sidebar.value?.contains(target) })
</script>
<template>
  <aside class="sidebar" id="sidebar" ref="sidebar" :class="{ open }" :role="open?'dialog':undefined" :aria-modal="open?true:undefined" aria-label="قائمة لوحة التحكم">
    <div class="sidebar-brand-row"><RouterLink class="brand" :to="authenticatedHome(auth)" @click="emit('navigate')"><img :src="logo" alt="مطعم شيشلي"><span>لوحة التحكم<small>إدارة المطعم</small></span></RouterLink><button type="button" class="sidebar-close" aria-label="إغلاق قائمة الإدارة" @click="emit('navigate')"><AdminIcon name="x"/></button></div>
    <nav aria-label="أقسام لوحة التحكم">
      <section v-for="group in groups" :key="group.key" class="sidebar-group"><button class="sidebar-group-heading" type="button" :aria-expanded="expanded(group)" :aria-controls="'nav-'+group.key" @click="toggle(group)">{{ group.label }}<AdminIcon name="chevron-down"/></button><div :id="'nav-'+group.key" v-show="expanded(group)" class="sidebar-group-links"><RouterLink v-for="link in group.links" :key="link.path" :to="link.path" :class="{active:active(link.path)}" :aria-current="active(link.path)?'page':undefined" @click="emit('navigate')"><AdminIcon :name="link.icon"/><span>{{ link.label }}</span><span v-if="link.path==='/admin/orders' && newOrders" class="sidebar-orders-badge" :aria-label="newOrders+' طلبات جديدة'"><span class="sidebar-orders-new" lang="en">new</span><b class="sidebar-orders-count">{{ newOrders }}</b></span></RouterLink></div></section>
    </nav>
    <div v-if="canViewOrders" class="sidebar-sound"><button type="button" class="sidebar-sound-toggle" :aria-pressed="orderSound.enabled.value" @click="orderSound.toggle"><AdminIcon name="bell"/>{{ orderSound.enabled.value?'كتم تنبيه الطلبات':'تفعيل صوت الطلبات' }}</button><small v-if="orderSound.message.value" role="status">{{ orderSound.message.value }}</small></div><div class="sidebar-note">شيشلي للدجاج التركي<small>إدارة المطعم والطلبات</small></div>
  </aside>
</template>


<style scoped>
.sidebar-sound{padding:12px 16px}.sidebar-sound-toggle{display:flex;align-items:center;gap:8px;width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:11px;cursor:pointer}.sidebar-sound-toggle[aria-pressed=true]{color:#d71920;border-color:#d71920}.sidebar-sound small{display:block;font-size:10px;margin-top:6px}.sidebar-orders-badge{display:inline-flex;align-items:center;gap:5px;margin-inline-start:auto;flex-shrink:0;line-height:1}
.sidebar-orders-new{font-size:9px;font-weight:700;letter-spacing:.3px;color:#d71920;background:#fff0f0;padding:4px 5px;border-radius:4px}
.sidebar-orders-count{display:grid;place-items:center;min-width:19px;height:19px;padding-inline:4px;box-sizing:border-box;border-radius:10px;background:#d71920;color:#fff;font-size:10px;font-weight:700;font-variant-numeric:tabular-nums}
</style>
