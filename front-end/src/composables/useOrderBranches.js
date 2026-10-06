import { ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { branchesApi } from '../api/branches.api'
import { normalizeApiError } from '../utils/apiError'
export function useOrderBranches(){
 const auth=useAuthStore(),branches=ref([]),error=ref(null),loading=ref(true)
 onMounted(async()=>{const session=auth.token;try{if(auth.can('branches.view')){let next=1,values=[],response;do{response=await branchesApi.list({page:next,per_page:100});values.push(...response.data);next++}while(next<=response.meta.last_page);if(auth.token===session)branches.value=values}else{const values=await branchesApi.publicList();if(auth.token===session)branches.value=values}}catch(cause){if(auth.token===session)error.value=normalizeApiError(cause)}finally{loading.value=false}})
 return {branches,error,loading}
}
