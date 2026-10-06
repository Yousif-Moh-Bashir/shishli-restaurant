import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsApi } from '../api/settings.api'
import { normalizeApiError } from '../utils/apiError'
export const usePublicSettingsStore=defineStore('publicSettings',()=>{
 const data=ref(null),loading=ref(false),error=ref(null)
 let pending,lastRead=0
 async function load(force=false){
  if(pending){const value=await pending;return force?load(true):value}
  if(!force&&data.value&&Date.now()-lastRead<15000)return data.value
  loading.value=true;error.value=null
  pending=(async()=>{try{data.value=await settingsApi.public();lastRead=Date.now();return data.value}catch(cause){error.value=normalizeApiError(cause);data.value=null;return null}finally{loading.value=false;pending=null}})()
  return pending
 }
 return {data,loading,error,load}
})
