import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { normalizeApiError } from '../utils/apiError.js'
// Serial requests, visibility pause, cancellation and stale-result protection.
export function useOrderPolling(request, { interval=15000, enabled=()=>true, paused=()=>false, normalizeError=normalizeApiError }={}) {
  const data=ref(null), loading=ref(false), refreshing=ref(false), error=ref(null), syncedAt=ref(null)
  let timer,controller,revision=0,disposed=false,blocked=false,retryDelay=0,retryAt=0
  function schedule(){clearTimeout(timer);if(!disposed&&!blocked&&enabled()&&document.visibilityState!=='hidden')timer=setTimeout(()=>{if(paused()){schedule();return}void load(true)},Math.max(interval,retryDelay))}
  function reset(){revision++;controller?.abort();clearTimeout(timer);loading.value=false;refreshing.value=false;data.value=null;error.value=null;syncedAt.value=null;blocked=false;retryDelay=0;retryAt=0}
  async function load(silent=false,force=false){
    if(disposed||(refreshing.value&&!force)||(paused()&&!force))return null
    if(Date.now()<retryAt){schedule();return null}
    clearTimeout(timer);controller?.abort();controller=new AbortController();const signal=controller.signal,current=++revision
    refreshing.value=true;loading.value=!silent&&!data.value;error.value=null;blocked=false
    try{const value=await request(signal);if(current!==revision||disposed)return null;data.value=value;syncedAt.value=new Date().toISOString();retryDelay=0;retryAt=0;return value}
    catch(cause){if(current!==revision||disposed||cause.code==='ERR_CANCELED')return null;error.value=normalizeError(cause);blocked=[401,403,404,422].includes(error.value.status);if([401,403,404].includes(error.value.status))data.value=null;retryDelay=error.value.status===429?Math.max(60000,Number(error.value.retryAfter||0)*1000):Math.min(retryDelay?retryDelay*2:30000,120000);return null}
    finally{if(current===revision&&!disposed){if(error.value?.status===429)retryAt=Date.now()+retryDelay;loading.value=false;refreshing.value=false;schedule()}}
  }
  function resume(){clearTimeout(timer);if(document.visibilityState==='visible'&&!blocked&&enabled()){if(paused())schedule();else void load(true)}}
  onMounted(()=>{
    watch([enabled,paused],([isEnabled,isPaused])=>{if(!isEnabled||isPaused)clearTimeout(timer);else resume()})
    document.addEventListener('visibilitychange',resume);window.addEventListener('online',resume)
  })
  onBeforeUnmount(()=>{disposed=true;reset();document.removeEventListener('visibilitychange',resume);window.removeEventListener('online',resume)})
  return {data,loading,refreshing,error,syncedAt,load,reset}
}
