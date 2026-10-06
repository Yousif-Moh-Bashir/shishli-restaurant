import {defineStore} from 'pinia'
import {reactive} from 'vue'
import {operationsApi} from '../api/operations.api'
import {normalizeApiError} from '../utils/apiError'
export const useOperationsStore=defineStore('operations',()=>{
 const status=reactive({}),errors=reactive({}),loading=reactive({})
 const pending=new Map(),readAt=new Map()
 async function load(branch,force=false){
  if(!branch)return null
  if(pending.has(branch)){const value=await pending.get(branch);return force?load(branch,true):value}
  if(!force&&status[branch]&&Date.now()-(readAt.get(branch)||0)<15000)return status[branch]
  loading[branch]=true;errors[branch]=null
  const request=(async()=>{try{status[branch]=await operationsApi.status(branch);readAt.set(branch,Date.now());return status[branch]}catch(cause){errors[branch]=normalizeApiError(cause);delete status[branch];return null}finally{loading[branch]=false;pending.delete(branch)}})()
  pending.set(branch,request);return request
 }
 return {status,errors,loading,load}
})
