import assert from 'node:assert/strict'
import { fileURLToPath } from 'node:url'
import { browserClient } from './browser-client.mjs'

const client = await browserClient(9876, fileURLToPath(new URL('../.notifications-check/pilot-ui/', import.meta.url)))
try {
  await client.send('Page.navigate', { url: 'http://127.0.0.1:5182/tests/pilot-harness.html' })
  await client.wait('document.readyState === "complete" && Boolean(document.querySelector("#test-root"))')
  const checks = await client.evaluate(`(async () => {
    const source=await(await fetch('/src/composables/useNewOrderSound.js')).text()
    const vueUrl=source.match(/from ["']([^"']*vue[.]js[^"']*)["']/)[1]
    const {createApp,h,nextTick}=await import(vueUrl)
    const Component=(await import('/src/views/admin/WebPushPilotView.vue')).default
    const {staffWebPushPilotApi:api}=await import('/src/api/staffWebPushPilot.api.js')
    let registered=false,subscriptions=0,removals=0,current=null
    api.status=async()=>({public_key:'B'.repeat(87),subscription:registered?{id:'fixture-device'}:null})
    api.subscribe=async()=>{subscriptions++;registered=true;return{id:'fixture-device'}}
    api.unsubscribe=async id=>{if(id!=='fixture-device')throw Error('wrong device');removals++;registered=false}
    const reg=await navigator.serviceWorker.register('/sw.js',{scope:'/'})
    await navigator.serviceWorker.ready
    const fixture={toJSON:()=>({endpoint:'https://fcm.googleapis.com/fcm/send/fixture',keys:{}}),async unsubscribe(){current=null;return true}}
    reg.pushManager.getSubscription=async()=>current
    reg.pushManager.subscribe=async()=>current=fixture
    const permission=Notification.requestPermission
    Notification.requestPermission=async()=> 'granted'
    const app=createApp({render:()=>h(Component)})
    app.mount(document.querySelector('#test-root'))
    const settle=()=>new Promise(resolve=>setTimeout(resolve,150))
    await settle()
    const results=[subscriptions===0 && removals===0,document.body.textContent.includes('تسجيل هذا الجهاز')]
    const click=text=>[...document.querySelectorAll('button')].find(button=>button.textContent.includes(text)).click()
    click('تسجيل هذا الجهاز');await settle();await nextTick()
    results.push(subscriptions===1 && registered,document.body.textContent.includes('fixture-device'))
    click('إلغاء اشتراك');await settle();await nextTick()
    results.push(removals===1 && !registered && current===null)
    api.status=async()=>{throw Error('forbidden')}
    click('تحديث الحالة');await settle();await nextTick()
    results.push(document.body.textContent.includes('غير متاحة'),!document.body.textContent.includes('تسجيل هذا الجهاز'))
    app.unmount();Notification.requestPermission=permission;await reg.unregister()
    return results
  })()`)
  assert.deepEqual(checks, Array(7).fill(true))
  assert.deepEqual(client.diagnostics, [])
  console.log('Passed: pilot UI has no automatic subscription, explicit registration, unsubscribe, and denied-account state. API and PushManager were fixtures; no push was sent.')
} finally { client.close() }
