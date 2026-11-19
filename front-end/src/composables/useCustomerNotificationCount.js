import { watch, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../stores/auth'
import { customerNotificationsApi } from '../api/customerNotifications.api'
import { useOrderPolling } from './useOrderPolling'

export function useCustomerNotificationCount() {
  const auth=useAuthStore()
  const state=useOrderPolling(signal=>customerNotificationsApi.unreadCount(signal), {
    interval:20000, enabled:()=>auth.isAuthenticated,
  })
  watch([()=>auth.token,()=>auth.user?.uuid,()=>auth.isAuthenticated],()=>{
    state.reset()
    if(auth.isAuthenticated) void state.load()
  },{immediate:true})
  const refresh=()=>{if(auth.isAuthenticated) void state.load(true,true)}
  const pushed=event=>{if(event.data?.type==='customer-order-push')refresh()}
  onMounted(()=>{window.addEventListener('customer-notifications-changed',refresh);navigator.serviceWorker?.addEventListener('message',pushed)})
  onBeforeUnmount(()=>{window.removeEventListener('customer-notifications-changed',refresh);navigator.serviceWorker?.removeEventListener('message',pushed)})
  return state
}
