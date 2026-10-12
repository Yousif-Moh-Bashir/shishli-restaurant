import { computed,ref } from 'vue'
import { defineStore } from 'pinia'
import { branchesApi } from '../api/branches.api'
import { normalizeApiError } from '../utils/apiError'
const key='shishli.selectedBranch'
export const useBranchStore=defineStore('branch',()=>{
  const branches=ref([]),selectedUuid=ref(''),isLoading=ref(false),error=ref(null),initialized=ref(false)
  let pending
  const selectedBranch=computed(()=>branches.value.find(branch=>branch.id===selectedUuid.value)||null)
  function select(uuid){
    selectedUuid.value=branches.value.some(branch=>branch.id===uuid&&branch.is_active)?uuid:''
    try{if(selectedUuid.value)localStorage.setItem(key,selectedUuid.value);else localStorage.removeItem(key)}catch{/* Preference is optional. */}
  }
  async function load(force=false){
    if(pending)return pending
    if(initialized.value&&!force)return
    isLoading.value=true;error.value=null;branches.value=[];selectedUuid.value=''
    pending=(async()=>{
      try{
        const result=await branchesApi.publicList()
        if(!Array.isArray(result))throw new Error('INVALID_BRANCH_LIST')
        branches.value=result.filter(branch=>branch.is_active)
        let saved='';try{saved=localStorage.getItem(key)||''}catch{}
        const preferred = branches.value.find(branch => branch.id === saved)
          || branches.value.find(branch => branch.name?.includes('المصيف'))
        select(preferred?.id || '');initialized.value=true
      }catch(cause){error.value=normalizeApiError(cause);initialized.value=false}
      finally{isLoading.value=false;pending=null}
    })()
    return pending
  }
  function syncCartBranch(uuid){
    selectedUuid.value=uuid
    try{localStorage.setItem(key,uuid)}catch{}
  }
  async function refresh(id){const value=await branchesApi.publicShow(id);const index=branches.value.findIndex(item=>item.id===id);if(index>=0)branches.value[index]=value;else if(value.is_active)branches.value.push(value);return value}
  return {branches,selectedUuid,selectedBranch,isLoading,error,load,refresh,select,syncCartBranch}
})
