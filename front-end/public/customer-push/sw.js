/* Isolated customer channel: no fetch handler, caches, bearer tokens, or order details. */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()))
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value)
async function database() {
  return new Promise((resolve, reject) => {
    const open=indexedDB.open('shishli-customer-push',1)
    open.onupgradeneeded=()=>{open.result.createObjectStore('settings');open.result.createObjectStore('delivered',{keyPath:'id'})}
    open.onsuccess=()=>resolve(open.result);open.onerror=()=>reject(open.error)
  })
}
async function binding(value, expected=null) {
  const db=await database()
  return new Promise((resolve,reject)=>{
    const tx=db.transaction('settings',value===undefined?'readonly':'readwrite'),store=tx.objectStore('settings'),request=store.get('binding')
    let result
    request.onsuccess=()=>{result=request.result;if(value!==undefined&&(!expected||result===expected)){result=value;if(value===null)store.delete('binding');else store.put(value,'binding')}}
    tx.oncomplete=()=>{db.close();resolve(result)};tx.onerror=tx.onabort=()=>{db.close();reject(tx.error)}
  })
}
async function claim(id, expiresAt) {
  const db=await database()
  return new Promise((resolve,reject)=>{
    const tx=db.transaction('delivered','readwrite'),store=tx.objectStore('delivered'),request=store.get(id)
    let claimed=false
    request.onsuccess=()=>{if(!request.result){store.put({id,expiresAt});claimed=true}}
    const cursor=store.openCursor()
    cursor.onsuccess=()=>{const row=cursor.result;if(row){if(row.value.expiresAt<=Date.now())row.delete();row.continue()}}
    tx.oncomplete=()=>{db.close();resolve(claimed)};tx.onerror=tx.onabort=()=>{db.close();reject(tx.error)}
  })
}
async function closeNotifications() { for(const n of await self.registration.getNotifications())n.close() }
self.addEventListener('message',event=>{
  if(!event.source?.url||new URL(event.source.url).origin!==self.location.origin)return
  if(!['customer-push-bind','customer-push-clear','customer-push-status'].includes(event.data?.type))return
  event.waitUntil((async()=>{
    try{
      if(event.data.type==='customer-push-status'){event.ports?.[0]?.postMessage({ok:true,id:await binding()});return}
      if(event.data.type==='customer-push-bind'){
        if(!uuid(event.data.id))throw Error('INVALID_BINDING')
        if(await binding()!==event.data.id)await closeNotifications()
        await binding(event.data.id)
      }else{
        const previous=await binding()
        if(!event.data.id||event.data.id===previous){await binding(null);await closeNotifications()}
      }
      event.ports?.[0]?.postMessage({ok:true})
    }catch{event.ports?.[0]?.postMessage({ok:false})}
  })())
})
self.addEventListener('push',event=>{
  event.waitUntil((async()=>{
    try{
      const data=event.data?.json(),expires=Date.parse(data?.expires_at)
      if(data?.kind!=='customer-order-push'||!uuid(data.id)||!uuid(data.binding)||!Number.isFinite(expires)||expires<=Date.now()||expires>Date.now()+600000)return
      if(await binding()!==data.binding)return
      const tag='shishli-customer-'+data.id
      if((await self.registration.getNotifications({tag})).length||!await claim(data.id,expires))return
      if(await binding()!==data.binding)return
      // Fixed text: untrusted payload fields never become lock-screen order data.
      await self.registration.showNotification('تحديث من شيشلي',{body:'يوجد تحديث لطلبك. افتح حسابك للاطلاع عليه. إرسال الطلب لا يعني قبوله.',
        icon:'/apple-touch-icon.png',badge:'/favicon.png',tag,renotify:false,data:{id:data.id,binding:data.binding}})
      for(const client of await self.clients.matchAll({type:'window',includeUncontrolled:true}))client.postMessage({type:'customer-order-push',id:data.id})
    }catch{/* Storage failure fails closed; the internal notification remains available. */}
  })())
})
self.addEventListener('notificationclick',event=>{
  event.notification.close()
  event.waitUntil((async()=>{
    try{
      const data=event.notification.data
      if(!uuid(data?.id)||await binding()!==data.binding)return
      const target=new URL('/account/notifications/open/'+data.id,self.location.origin).href
      const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true})
      const client=windows.find(item=>new URL(item.url).origin===self.location.origin)
      if(client){await client.navigate(target);await client.focus()}else await self.clients.openWindow(target)
    }catch{}
  })())
})
self.addEventListener('pushsubscriptionchange',event=>{
  event.waitUntil((async()=>{
    await binding(null);await closeNotifications()
    for(const client of await self.clients.matchAll({type:'window',includeUncontrolled:true}))client.postMessage({type:'customer-push-renewal-required'})
  })())
})
