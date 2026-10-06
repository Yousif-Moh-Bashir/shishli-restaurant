<script setup>
import { ref, computed, provide, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AdminSidebar from '../components/admin/AdminSidebar.vue'
import AdminHeader from '../components/admin/AdminHeader.vue'
import AdminConfirmDialog from '../components/admin/AdminConfirmDialog.vue'

const route = useRoute()
const sidebarOpen = ref(false), sidebar = ref(null), header = ref(null), toastMessage = ref('')
const title = computed(() => route.meta.adminTitle || 'نظرة عامة')
const confirmMessage=ref('')
let confirmResolver
function confirmAction(message){
 if(confirmResolver)return Promise.resolve(false)
 confirmMessage.value=message
 return new Promise(resolve=>{confirmResolver=resolve})
}
function answerConfirmation(value){const resolve=confirmResolver;confirmResolver=undefined;confirmMessage.value='';resolve?.(value)}
let timer, mobile
const originalTitle = document.title
let previousOverflow
watch(sidebarOpen,async open=>{
  if(open){previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';await nextTick();document.querySelector('#sidebar .sidebar-close')?.focus()}
  else{if(previousOverflow!==undefined){document.body.style.overflow=previousOverflow;previousOverflow=undefined}header.value?.focusToggle()}
})
let originalRobots, robots, createdRobots = false
function closeSidebar() { sidebarOpen.value = false }
function notify(message) { toastMessage.value = message; clearTimeout(timer); timer = setTimeout(() => { toastMessage.value = '' }, 3500) }
function outsideClick(event) { if (!sidebar.value?.contains(event.target) && !header.value?.containsToggle(event.target)) closeSidebar() }
function escapeMenu(event) {
  if(!sidebarOpen.value)return
  if(event.key==='Escape'){closeSidebar();return}
  if(event.key==='Tab'){
    const controls=[...document.querySelectorAll('#sidebar a,#sidebar button')].filter(element=>element.getClientRects().length)
    const first=controls[0],last=controls.at(-1)
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus()}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus()}
  }
}
watch(() => route.fullPath, () => { closeSidebar(); document.title = route.meta.title || 'لوحة شيشلي' }, { immediate: true })
provide('admin', { notify,confirmAction })
onMounted(() => {
  try{localStorage.removeItem('shishli.dashboard.v1')}catch{}
  document.body.classList.add('admin-page')
  robots = document.getElementsByName('robots')[0]
  if (!robots) { robots = document.createElement('meta'); robots.name = 'robots'; document.head.append(robots); createdRobots = true }
  else originalRobots = robots.content
  robots.content = 'noindex,nofollow'
  mobile = matchMedia('(max-width:760px)'); mobile.addEventListener('change', closeSidebar)
  document.addEventListener('click', outsideClick); document.addEventListener('keydown', escapeMenu)
})
onBeforeUnmount(() => {
  clearTimeout(timer); mobile?.removeEventListener('change', closeSidebar)
  answerConfirmation(false)
  document.removeEventListener('click', outsideClick); document.removeEventListener('keydown', escapeMenu)
  document.body.classList.remove('admin-page')
  if(previousOverflow!==undefined)document.body.style.overflow=previousOverflow
  if (createdRobots) robots?.remove(); else if (robots) robots.content = originalRobots
  document.title = originalTitle
})
</script>
<template>
  <div class="admin-layout">
    <a class="skip" href="#main">انتقل للمحتوى</a>
    <AdminSidebar ref="sidebar" :open="sidebarOpen" @navigate="closeSidebar" />
    <button v-if="sidebarOpen" type="button" class="sidebar-backdrop" tabindex="-1" aria-label="إغلاق قائمة الإدارة" @click="closeSidebar"/>
    <div class="workspace" :inert="sidebarOpen||undefined"><AdminHeader ref="header" :sidebar-open="sidebarOpen" :title="title" @toggle-sidebar="sidebarOpen = !sidebarOpen" /><RouterView /><footer>شيشلي © 2026 · لوحة التحكم</footer></div>
    <div id="toast" role="status" class="toast" :hidden="!toastMessage">{{ toastMessage }}</div>
    <AdminConfirmDialog :message="confirmMessage" @answer="answerConfirmation"/>
  </div>
</template>

