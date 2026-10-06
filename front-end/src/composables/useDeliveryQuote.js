import {ref,computed,watch,onBeforeUnmount} from 'vue'
import {useCartStore} from '../stores/cart'
import {useAuthStore} from '../stores/auth'
export function useDeliveryQuote(addressSource,orderType){
  const cart=useCartStore(),auth=useAuthStore(),quote=ref(null),quoteError=ref(null),isQuoting=ref(false)
  let revision=0,disposed=false
  const cartSignature=computed(()=>JSON.stringify(cart.cart))
  function invalidate(){quote.value=null;quoteError.value=null}
  watch([addressSource,orderType,()=>auth.token,()=>cart.cart?.id,()=>cart.cart?.branch.id],()=>{revision++;invalidate()},{flush:'sync'})
  watch(cartSignature,invalidate,{flush:'sync'})
  async function requestQuote(){
    if(isQuoting.value||!addressSource.value||orderType.value!=='delivery')return false
    const version=revision,cartId=cart.cart?.id,branchId=cart.cart?.branch.id
    isQuoting.value=true;invalidate()
    try{
      const result=await cart.requestDeliveryQuote(addressSource.value)
      if(disposed||version!==revision||cartId!==cart.cart?.id||branchId!==cart.cart?.branch.id)return false
      if(!result.ok){quoteError.value=cart.error||{message:result.message,errors:{}};return false}
      if(typeof result.data?.available!=='boolean'||typeof result.data.delivery_fee!=='string'||typeof result.data.estimated_total!=='string')throw Error('INVALID_DELIVERY_QUOTE')
      quote.value=result.data;return true
    }catch{if(!disposed&&version===revision)quoteError.value={message:'تعذر قراءة نتيجة التحقق من التوصيل. أعد المحاولة.',errors:{}};return false}
    finally{if(!disposed)isQuoting.value=false}
  }
  onBeforeUnmount(()=>{disposed=true;revision++;invalidate()})
  return {quote,quoteError,isQuoting,requestQuote,invalidate}
}
