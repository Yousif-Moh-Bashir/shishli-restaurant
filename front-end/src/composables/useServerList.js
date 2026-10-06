import {ref,reactive} from 'vue'
import {useApiState} from './useApiState'
export function useServerList(api,defaults={}){
 const state=useApiState(),page=ref(1),filters=reactive({search:'',per_page:20,...defaults})
 function load(){return state.run(signal=>api.list({...filters,page:page.value},signal))}
 function search(){page.value=1;return load()}
 function changePage(value){page.value=value;return load()}
 return {state,page,filters,load,search,changePage}
}
