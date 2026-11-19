<script setup>
import CustomerPushSettings from '../../components/site/CustomerPushSettings.vue'
import { ref,watch,onMounted,onBeforeUnmount } from 'vue'
import { useRouter,RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { useOrderPolling } from '../../composables/useOrderPolling'
import { customerNotificationsApi } from '../../api/customerNotifications.api'
import { normalizeApiError } from '../../utils/apiError'
import { formatDateTime } from '../../utils/formatters'
import AppSkeleton from '../../components/common/AppSkeleton.vue'
import ApiPagination from '../../components/admin/catalog/ApiPagination.vue'
import SiteIcon from '../../components/site/SiteIcon.vue'

const auth=useAuthStore(),router=useRouter(),page=ref(1),busy=ref(null),mutationError=ref(null)
const state=useOrderPolling(signal=>customerNotificationsApi.list({page:page.value,per_page:20},signal),{
  interval:20000,enabled:()=>auth.isAuthenticated,paused:()=>busy.value!==null,
})
const {data,loading,refreshing,error}=state
const pushed=event=>{if(event.data?.type==='customer-order-push'&&auth.isAuthenticated&&busy.value===null)void state.load(true)}
onMounted(()=>navigator.serviceWorker?.addEventListener('message',pushed))
onBeforeUnmount(()=>navigator.serviceWorker?.removeEventListener('message',pushed))
watch([()=>auth.token,()=>auth.user?.uuid,()=>auth.isAuthenticated],()=>{
  state.reset();page.value=1;busy.value=null;mutationError.value=null
  if(auth.isAuthenticated) void state.load()
},{immediate:true})
function changePage(value){page.value=value;state.reset();void state.load()}
async function read(row,navigate=false){
  if(busy.value!==null)return
  const session=auth.token
  busy.value=row.id;mutationError.value=null
  try{
    const result=row.read_at?row:await customerNotificationsApi.read(row.id)
    if(auth.token!==session)return
    window.dispatchEvent(new Event('customer-notifications-changed'))
    if(navigate) await router.push({name:'account.order',params:{orderUuid:result.order.id}})
    else await state.load(true,true)
  }catch(cause){if(auth.token===session){mutationError.value=normalizeApiError(cause);if([403,404].includes(mutationError.value.status))await state.load(true,true)}}
  finally{if(auth.token===session)busy.value=null}
}
</script>
<template>
  <main id="main" class="container customer-notifications-page">
    <RouterLink class="auth-back" to="/account/orders">العودة إلى طلباتي</RouterLink>
    <div class="section-heading notification-heading"><div><span class="section-kicker">متابعة طلباتك</span><h1>إشعارات الطلبات</h1><p>تحديثات المراحل المسجلة فعليًا. راجع تفاصيل الطلب لمعرفة حالته الحالية.</p></div><button type="button" class="button" :disabled="refreshing||busy!==null" @click="state.load(true,true)">تحديث الإشعارات</button></div>
    <CustomerPushSettings/>
    <p v-if="data" class="notification-summary" role="status">غير المقروءة: {{ data.unread_count }} · تتحدث القائمة تلقائيًا كل 20 ثانية.</p>
    <AppSkeleton v-if="loading" variant="detail" label="جارٍ تحميل إشعارات الطلبات…"/>
    <div v-if="error||mutationError" class="notification-error" role="alert"><p>{{ (mutationError||error).message }}</p><p v-if="error&&data">هذه آخر بيانات متاحة؛ تعذر تأكيد آخر التحديثات.</p><button type="button" class="button" :disabled="refreshing||busy!==null" @click="mutationError=null;state.load(true,true)">إعادة المحاولة</button></div>
    <template v-if="data">
      <section v-if="!data.data.length" class="notification-empty" role="status"><SiteIcon name="bell"/><h2>لا توجد إشعارات مسجلة</h2><p>ستظهر تحديثات طلبات حسابك هنا عند تسجيل مراحلها. إرسال الطلب وحده لا يعني قبوله.</p></section>
      <ol v-else class="customer-notification-list" aria-label="تحديثات الطلبات">
        <li v-for="row in data.data" :key="row.id" class="customer-notification-row" :class="{'is-unread':!row.read_at}">
          <button type="button" class="notification-open" :disabled="busy!==null" @click="read(row,true)"><span class="notification-state">{{ row.read_at?'مقروء':'غير مقروء' }}</span><strong>{{ row.title }}</strong><span class="notification-body">{{ row.body }}</span><span>الطلب <bdi>{{ row.order.number }}</bdi></span><time :datetime="row.occurred_at">{{ formatDateTime(row.occurred_at) }}</time><span class="notification-order-link">عرض تفاصيل الطلب</span></button>
          <button v-if="!row.read_at" type="button" class="button notification-read" :disabled="busy!==null" @click="read(row)">{{ busy===row.id?'جارٍ التحديث…':'تحديد كمقروء' }}</button>
        </li>
      </ol>
      <ApiPagination :meta="data.meta" :links="data.links" :busy="refreshing||busy!==null" @page="changePage"/>
    </template>
  </main>
</template>
<style scoped>
.customer-notifications-page{padding-block:30px 50px;min-width:0}.notification-heading{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}.notification-heading p,.notification-empty p{font-size:13px;line-height:1.9;color:var(--muted)}.notification-summary{font-size:12px;margin-block:16px;color:var(--muted)}
.customer-notification-list{list-style:none;padding:0;display:grid;gap:12px}.customer-notification-row{display:flex;align-items:center;gap:16px;padding:16px;border:1px solid var(--border);border-radius:12px;background:#fffdfa;min-width:0}.customer-notification-row.is-unread{border-inline-start:4px solid var(--primary);background:#f8f2ed}.notification-open{display:grid;gap:8px;flex:1;min-width:0;text-align:start;background:none;border:0;color:var(--primary-dark);font:inherit;cursor:pointer;overflow-wrap:anywhere;padding:8px}.notification-open strong{font-size:17px}.notification-open span,.notification-open time{font-size:12px;line-height:1.8}.notification-open .notification-body{font-size:13px}.notification-state,.notification-open time{color:var(--muted)}.notification-order-link{font-weight:700;text-decoration:underline}.notification-read{min-height:44px;flex-shrink:0}.notification-error,.notification-empty{padding:24px;border:1px solid var(--border);border-radius:12px;background:#fffdfa;line-height:1.9;margin-block:16px}.notification-error{border-color:#ddc1bb}.notification-empty{text-align:center}.notification-empty>.icon{width:32px;height:32px;color:var(--primary)}.customer-notifications-page :deep(.api-pagination){display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;font-size:12px;margin-top:20px}.customer-notifications-page :deep(.pagination-actions){display:flex;gap:12px}.customer-notifications-page :deep(.row-button){min-height:44px;padding:8px 14px;border:1px solid var(--border);border-radius:8px;background:var(--cream);font:inherit}
@media(max-width:600px){.customer-notification-row{align-items:stretch;flex-direction:column;gap:8px;padding:12px}.notification-read{align-self:flex-start}.notification-heading>button{width:100%}.customer-notifications-page{padding-block:24px 40px}}
</style>
