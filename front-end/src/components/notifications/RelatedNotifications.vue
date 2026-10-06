<script setup>
import AppSkeleton from "../common/AppSkeleton.vue"
import {watch,ref} from 'vue'
import {RouterLink} from 'vue-router'
import {useAuthStore} from '../../stores/auth'
import {notificationsApi} from '../../api/notifications.api'
import {useApiState} from '../../composables/useApiState'
import {channelLabel,typeLabel} from '../../utils/notificationStatus'
import {formatDateTime} from '../../utils/formatters'
import NotificationBadge from './NotificationBadge.vue'
import ApiPagination from '../admin/catalog/ApiPagination.vue'
const props=defineProps({referenceId:{type:String,required:true}}),auth=useAuthStore(),state=useApiState(),page=ref(1)
const {data,loading,error}=state
function load(next=1){page.value=next;if(auth.can('notifications.view'))void state.run(signal=>notificationsApi.list({reference_uuid:props.referenceId,page:page.value,per_page:5},signal))}
watch([()=>props.referenceId,()=>auth.token],()=>{state.clear();load()},{immediate:true})
</script>
<template><section v-if="auth.can('notifications.view')" class="panel related-notifications"><div class="panel-head"><h2>الإشعارات المرتبطة</h2><button class="row-button" :disabled="loading" @click="load(page)">تحديث الإشعارات</button></div><p>فشل إرسال رسالة لا يغير نتيجة الطلب أو الدفع.</p><AppSkeleton v-if="loading" variant="list" label="جارٍ تحميل الإشعارات…"/><div v-if="error" role="alert"><p>{{ error.message }}</p><button class="button" @click="load(page)">إعادة المحاولة</button></div><template v-if="data"><article v-for="notification in data.data" :key="notification.id" class="related-notification"><strong>{{ typeLabel(notification.type) }} · {{ channelLabel(notification.channel) }}</strong><NotificationBadge :status="notification.status"/><p v-if="notification.sent_at">إرسال: {{ formatDateTime(notification.sent_at) }}</p><p v-if="notification.last_error">{{ notification.last_error.message }}</p><RouterLink class="row-button" :to="'/admin/notifications/'+notification.id">التفاصيل ومحاولات الإرسال</RouterLink></article><p v-if="!data.data.length">لا توجد إشعارات مسجلة لهذا المرجع.</p><ApiPagination :meta="data.meta" :links="data.links" :busy="loading" @page="load"/><RouterLink class="row-button" :to="{path:'/admin/notifications',query:{reference_uuid:referenceId}}">كل الإشعارات المرتبطة</RouterLink></template></section></template>
<style scoped>.related-notifications{padding:20px;margin-block:16px;min-width:0}.related-notification{padding-block:12px;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.related-notification .badge{margin-inline:10px}</style>
