import { ref,computed,watch,onBeforeUnmount } from 'vue'
import {useBranchStore} from '../stores/branch'
import {menuApi} from '../api/products.api'
import {categoriesApi,flattenCategories} from '../api/categories.api'
import {useApiState} from './useApiState'
import {normalizeApiError} from '../utils/apiError'
export function menuProduct(product,branchUuid) {
  return {...product,image:product.primary_image?.url||product.images?.[0]?.url||'',branchUuid,real:true}
}
export function usePublicMenu({categoriesEnabled=true,shouldLoad=()=>true}={}){
  const branch=useBranchStore(),state=useApiState(),query=ref(''),category=ref(''),featured=ref(''),available=ref(''),categories=ref([]),categoriesError=ref(null),categoriesLoading=ref(false)
  let timer, ready=false, disposed=false
  async function loadCategories(){categoriesLoading.value=true;categoriesError.value=null;categories.value=[];try{categories.value=flattenCategories(await categoriesApi.publicList())}catch(cause){categoriesError.value=normalizeApiError(cause)}finally{categoriesLoading.value=false}}
  function load(){clearTimeout(timer);if(branch.selectedBranch&&shouldLoad())state.run(signal=>menuApi.list({branch:branch.selectedUuid,search:query.value,category:category.value,featured:featured.value,available:available.value},signal));else state.clear()}
  watch(()=>branch.selectedUuid,()=>{category.value=''})
  watch([()=>branch.selectedBranch,query,category,featured,available],(values,previous)=>{
    state.clear();clearTimeout(timer)
    if(ready)timer=setTimeout(load,values[0]!==previous[0]?0:300)
  })
  const products=computed(()=>state.data.value?.categories.flatMap(group=>group.products.map(product=>menuProduct(product,branch.selectedUuid)))||[])
  branch.load().then(()=>{if(disposed)return;ready=true;clearTimeout(timer);load()});if(categoriesEnabled)loadCategories()
  onBeforeUnmount(()=>{disposed=true;clearTimeout(timer)})
  return {branch,state,query,category,featured,available,categories,categoriesError,categoriesLoading,products,load,loadCategories}
}
