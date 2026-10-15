import assert from 'node:assert/strict'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'
import { createRenderer, ref, nextTick } from 'vue'
const server=await createServer({configFile:false,optimizeDeps:{noDiscovery:true,include:[]},server:{middlewareMode:true},appType:'custom'})
const storage=new Map();globalThis.localStorage={getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)}
const renderer=createRenderer({createComment:()=>({}),insert(){},remove(){},parentNode:()=>null,nextSibling:()=>null})
try{
 const {useCartStore}=await server.ssrLoadModule('/src/stores/cart.js'),{useDeliveryQuote}=await server.ssrLoadModule('/src/composables/useDeliveryQuote.js'),{useAuthStore}=await server.ssrLoadModule('/src/stores/auth.js')
 setActivePinia(createPinia());const cart=useCartStore(),auth=useAuthStore(),source=ref({address:{city:'تبوك',district:'المصيف',latitude:28.4,longitude:36.5}}),type=ref('delivery')
 cart.cart={id:'cart-1',branch:{id:'branch-1'},items:[],summary:{total:'80.00'}}
 const pending=[];cart.requestDeliveryQuote=(body,options)=>new Promise(resolve=>pending.push({body,options,resolve}))
 let state;const app=renderer.createApp({setup(){state=useDeliveryQuote(source,type);return()=>null}});app.mount({})
 const value={available:true,delivery_fee:'5.00',estimated_total:'85.00'}
 let request=state.requestQuote();assert.equal(pending.length,1)
 source.value={address:{...source.value.address,latitude:28.5}};assert.equal(pending[0].options.signal.aborted,true)
 pending.shift().resolve({ok:true,data:value});await request;assert.equal(state.quote.value,null,'Old address result cannot return')
 request=state.requestQuote();pending.shift().resolve({ok:true,data:value});await request;assert.deepEqual(state.quote.value,value)
 cart.cart.summary.total='90.00';assert.equal(state.quote.value,null,'Cart signature invalidates result')
 request=state.requestQuote();cart.cart.summary.total='100.00';const old=pending.shift();assert.equal(old.options.signal.aborted,true);old.resolve({ok:true,data:value});await request;assert.equal(state.quote.value,null,'Cart change rejects in-flight result')
 request=state.requestQuote();cart.cart.branch.id='branch-2';pending.shift().resolve({ok:true,data:value});await request;assert.equal(state.quote.value,null,'Branch change rejects in-flight result')
 request=state.requestQuote();auth.token='new-session';pending.shift().resolve({ok:true,data:value});await request;assert.equal(state.quote.value,null,'Session change rejects in-flight result')
 request=state.requestQuote();const current=pending.shift();current.options.onSync(()=>{cart.cart.summary.total='95.00'});current.resolve({ok:true,data:value});await request;assert.ok(state.quote.value,'Own authoritative cart sync can publish current result')
 state.invalidate();assert.equal(state.quote.value,null)
 request=state.requestQuote();const unmounted=pending.shift();app.unmount();assert.equal(unmounted.options.signal.aborted,true);unmounted.resolve({ok:true,data:value});await request
 const {http}=await server.ssrLoadModule('/src/api/http.js')
 setActivePinia(createPinia());const actual=useCartStore()
 actual.cart={id:'cart-real',branch:{id:'branch-real'},items:[],summary:{total:'80.00'}}
 let resolveTransport,transports=[]
 http.defaults.adapter=config=>{transports.push(config);return new Promise(resolve=>{resolveTransport=data=>resolve({config,status:200,statusText:'OK',headers:{},data:{success:true,data}})})}
 const waitTransport=async()=>{for(let i=0;i<100&&!resolveTransport;i++)await new Promise(resolve=>setTimeout(resolve,10));assert.ok(resolveTransport)}
 const firstActual=actual.requestDeliveryQuote(source.value);await waitTransport()
 actual.cart.summary.total='90.00';resolveTransport(value);assert.equal((await firstActual).stale,true);assert.equal(transports.length,1,'Changed cart cannot publish or refresh old quote')
 resolveTransport=null
 const controller=new AbortController(),abortedActual=actual.requestDeliveryQuote(source.value,{signal:controller.signal});await waitTransport();controller.abort();resolveTransport(value);assert.equal((await abortedActual).stale,true);assert.equal(actual.error,null,'Abort must not poison cart error state')
 console.log('Delivery quote: address/cart/branch/session/unmount races, cancellation, explicit invalidation and authoritative cart synchronization passed')
}finally{await server.close()}
