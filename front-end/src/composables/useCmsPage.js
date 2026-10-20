import {watch} from 'vue'
import {useRoute} from 'vue-router'
import {cmsApi} from '../api/cms.api'
import {useApiState} from './useApiState'
import {setPageSeo,routeSeo,cmsPageSeo} from '../utils/seo'
export function useCmsPage(key){
 const state=useApiState(),route=useRoute()
 function seo(value){
  const page=value?.page||value
  if(!page)return
  setPageSeo(cmsPageSeo(route,page))
 }
 function load(){return state.run(signal=>cmsApi.page(key,signal))}
 watch(state.data,seo)
 watch(state.error,error=>{if(error?.status===404&&key!=='home')setPageSeo({...routeSeo(route),index:false})})
 return {...state,load,seo}
}
