<script setup>
import AppSkeleton from "./components/common/AppSkeleton.vue"
import { RouterView,RouterLink,useRouter } from 'vue-router'
import {ref,onBeforeUnmount} from 'vue'
import { useAuthStore } from './stores/auth'
const auth = useAuthStore()
const router=useRouter(),routeError=ref(false)
const removeError=router.onError(()=>{routeError.value=true}),removeAfter=router.afterEach((to,from,failure)=>{if(!failure)routeError.value=false})
onBeforeUnmount(()=>{removeError();removeAfter()})
function reload(){window.location.reload()}
</script>
<template><div v-if="!auth.isInitialized" class="auth-bootstrap"><AppSkeleton variant="page" label="جارٍ التحقق من الجلسة…"/></div><main v-else-if="routeError" id="main" class="container section-space" role="alert"><h1>تعذر تحميل الصفحة</h1><p>أعد تحميل التطبيق للحصول على الملفات الحالية.</p><button class="button button-primary" @click="reload">إعادة التحميل</button><RouterLink class="text-button" to="/">الرئيسية</RouterLink></main><RouterView v-else /></template>
<style scoped>.auth-bootstrap{min-height:100dvh;display:grid;place-items:center;color:#431520;background:#fff8f2;font-family:Tajawal,Tahoma,Arial,sans-serif}</style>
