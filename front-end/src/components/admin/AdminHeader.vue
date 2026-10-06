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
    <span>مطعم شيشلي <span class="muted"> / {{ title }}</span></span>
    <div class="admin"><span class="avatar"><AdminIcon name="user-round" /></span><div>{{ auth.user?.name }}<small>{{ auth.roles.map(adminRoleLabel).join(' / ') }}</small></div><button class="row-button" type="button" :disabled="auth.isLoading" @click="logout">خروج</button></div>
  </header>
</template>
