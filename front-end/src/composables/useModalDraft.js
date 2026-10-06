import {ref,computed,watch} from 'vue'
import {useUnsavedForm} from './useUnsavedForm'
export function useModalDraft(open,form,busy,extra){
 const baseline=ref(''),serialize=()=>JSON.stringify([form,extra?.value],(key,value)=>value instanceof File?{name:value.name,size:value.size,lastModified:value.lastModified}:value)
 watch(open,value=>{if(value)baseline.value=serialize()},{flush:'post'})
 const dirty=computed(()=>open.value&&serialize()!==baseline.value)
 useUnsavedForm(dirty,busy)
 return ()=>!busy.value&&(!dirty.value||confirm('هناك تغييرات غير محفوظة. هل تريد إغلاق النموذج؟'))
}
