import {watch,onBeforeUnmount} from 'vue'
import {onBeforeRouteLeave} from 'vue-router'
export function useUnsavedForm(dirty,busy,emit){
 watch([dirty,busy],()=>{emit?.('dirty',dirty.value);emit?.('busy',busy.value)},{immediate:true})
 onBeforeRouteLeave(()=>!busy.value&&(!dirty.value||confirm('هناك تغييرات تشغيلية غير محفوظة. هل تريد المغادرة؟')))
 const beforeUnload=event=>{if(dirty.value||busy.value){event.preventDefault();event.returnValue=''}}
 window.addEventListener('beforeunload',beforeUnload)
 onBeforeUnmount(()=>{window.removeEventListener('beforeunload',beforeUnload);emit?.('dirty',false);emit?.('busy',false)})
}
