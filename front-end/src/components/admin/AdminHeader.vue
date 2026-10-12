<script setup>
import {adminRoleLabel,adminDomainLabel} from '../../constants/adminPresentation'
import { ref, inject } from 'vue'
import AdminIcon from './AdminIcon.vue'
import { useAuthStore } from '../../stores/auth'
import { useSessionLogout } from '../../composables/useSessionLogout'
const auth = useAuthStore()
const { notify } = inject('admin')
const { logout } = useSessionLogout(notify)
defineProps({ sidebarOpen: Boolean, title: { type: String, default: 'نظرة عامة' } })
const emit = defineEmits(['toggle-sidebar'])
const menuButton = ref(null)
defineExpose({ containsToggle: target => menuButton.value?.contains(target), focusToggle: () => menuButton.value?.focus() })
</script>
<template>
  <header class="topbar">
    <button class="icon-button menu-toggle" id="menu-toggle" ref="menuButton" type="button" :aria-expanded="sidebarOpen" aria-controls="sidebar" :aria-label="sidebarOpen ? 'إغلاق قائمة الإدارة' : 'فتح قائمة الإدارة'" @click="emit('toggle-sidebar')"><AdminIcon name="menu" /></button>
    <nav v-if="title==='مناديب التوصيل'" class="driver-admin-breadcrumb" aria-label="مسار الصفحة"><RouterLink to="/admin">مطعم شيشلي</RouterLink><span class="driver-breadcrumb-separator" aria-hidden="true">‹</span><span class="driver-breadcrumb-current" aria-current="page"><AdminIcon name="truck"/>{{ title }}</span></nav>
    <span v-else>مطعم شيشلي <span class="muted"> / {{ title }}</span></span>
    <div class="admin"><span class="avatar"><AdminIcon name="user-round" /></span><div>{{ auth.user?.name }}<small>{{ auth.roles.map(adminRoleLabel).join(' / ') }}</small></div><button class="row-button" type="button" :disabled="auth.isLoading" @click="logout">خروج</button></div>
  </header>
</template>
<style scoped>
.driver-admin-breadcrumb{display:flex;align-items:center;flex-wrap:wrap;gap:10px;font-size:12px;min-width:0;line-height:1.6}
.driver-admin-breadcrumb>a{color:var(--muted);white-space:nowrap}
.driver-breadcrumb-separator{color:var(--muted);font-size:20px}
.driver-breadcrumb-current{display:inline-flex;align-items:center;gap:7px;color:var(--burgundy);font-weight:700}
.driver-breadcrumb-current .icon{width:18px;height:18px;flex-shrink:0}
@media(max-width:760px){.driver-admin-breadcrumb{gap:5px;font-size:11px}.driver-breadcrumb-current{gap:4px}}
</style>
