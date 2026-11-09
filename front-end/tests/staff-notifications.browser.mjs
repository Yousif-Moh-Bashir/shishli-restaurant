import assert from 'node:assert/strict'
import { fileURLToPath } from 'node:url'
import { browserClient } from './browser-client.mjs'

const client = await browserClient(9400 + process.pid % 5000, fileURLToPath(new URL('../.notifications-check/staff-test/' + process.pid + '/', import.meta.url)))
try {
  await client.send('Page.navigate', { url: 'http://127.0.0.1:5181/tests/staff-notifications-harness.html' })
  let loaded = false
  for (let attempt = 0; attempt < 100; attempt++) {
    try { loaded = await client.evaluate('location.pathname === "/tests/staff-notifications-harness.html" && document.readyState === "complete"') } catch {}
    if (loaded) break
    await client.sleep(100)
  }
  assert.equal(loaded, true)
  const results = await client.evaluate(`(async () => {
    const source = await (await fetch('/src/composables/useNewOrderSound.js')).text()
    const vueUrl = source.match(/from ["']([^"']*vue[.]js[^"']*)["']/)[1]
    const {createApp, h, reactive, nextTick} = await import(vueUrl)
    const {useStaffNotifications} = await import('/src/composables/useStaffNotifications.js')
    const {staffNotificationsApi} = await import('/src/api/staffNotifications.api.js')
    const Preferences = (await import('/src/components/notifications/NotificationPreferences.vue')).default
    let pending = [], played = 0, stopped = 0, now = Date.now()
    const realNow = Date.now; Date.now = () => now
    window.AudioContext = class {
      state = 'running'; currentTime = 0; destination = {}
      async resume() {this.state='running'} async close() {}
      createOscillator() {return {frequency:{value:0},connect(){},start(){played++},stop(){stopped++}}}
      createGain() {return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}}}
    }
    let enabled = false, diagnostic = false, diagnosticRegistrations = 0, diagnosticRemovals = 0, normalRegistrations = 0
    localStorage.setItem('shishli.orderSoundMuted', '1')
    staffNotificationsApi.feed = async () => ({enabled, sound_enabled:enabled, push_enabled:false, push_diagnostic:{enabled:diagnostic,ready:diagnostic,public_key:'B'.repeat(87)}, repeat_seconds:60, pending:[...pending]})
    staffNotificationsApi.subscribeDiagnostic = async () => {diagnosticRegistrations++;return {id:'fixture-device'}}
    staffNotificationsApi.unsubscribeDiagnostic = async id => {if(id==='fixture-device') diagnosticRemovals++}
    staffNotificationsApi.subscribe = async () => {normalRegistrations++}
    const auth = reactive({isAuthenticated:true, token:'fixture', user:{uuid:'staff-fixture'}, can:()=>true})
    let notifications
    const app = createApp({setup(){notifications=useStaffNotifications(auth);return()=>h(Preferences,{notifications})}})
    app.component('RouterLink',{setup(_,context){return()=>h('a',{},context.slots.default?.())}})
    app.mount(document.querySelector('#test-root'))
    await new Promise(resolve=>setTimeout(resolve,100))
    const checks=[]
    checks.push(document.body.textContent.includes('متوقفة') && !document.querySelector('button'))
    enabled=true; await notifications.feed.load(); await nextTick()
    await notifications.sound.toggle()
    const row={id:'browser-fixture-'+Math.random(),order_id:'order-1',expires_at:new Date(now+1800000).toISOString()}
    pending=[row]; await notifications.feed.load(); await new Promise(resolve=>setTimeout(resolve,100))
    checks.push(played===2)
    await notifications.feed.load(); await new Promise(resolve=>setTimeout(resolve,100)); checks.push(played===2)
    now+=61000; await notifications.feed.load(); await new Promise(resolve=>setTimeout(resolve,100)); checks.push(played===4)
    pending=[]
    window.dispatchEvent(new CustomEvent('shishli-order-updated',{detail:{id:'order-1',status:'confirmed'}}))
    await new Promise(resolve=>setTimeout(resolve,100))
    now+=61000; await notifications.feed.load(); await new Promise(resolve=>setTimeout(resolve,100)); checks.push(played===4 && stopped>0)
    enabled=false; await notifications.feed.load(); await nextTick(); checks.push(!document.querySelector('button'))
    auth.token='different-session'; await new Promise(resolve=>setTimeout(resolve,100)); checks.push(played===4)
    const registration = await navigator.serviceWorker.register('/sw.js', {scope:'/'})
    await navigator.serviceWorker.ready
    checks.push(registration.scope === location.origin + '/' && Boolean(registration.active))
    const manifest = await (await fetch('/manifest.webmanifest')).json()
    checks.push(manifest.display === 'standalone' && manifest.start_url === '/admin/orders')
    let currentSubscription = null
    const fakeSubscription = {endpoint:'https://fcm.googleapis.com/fcm/send/fixture',toJSON(){return {endpoint:this.endpoint,keys:{}}},async unsubscribe(){currentSubscription=null;return true}}
    registration.pushManager.getSubscription = async () => currentSubscription
    registration.pushManager.subscribe = async () => (currentSubscription=fakeSubscription)
    const originalPermission = Notification.requestPermission
    Notification.requestPermission = async () => 'granted'
    diagnostic=true; await notifications.feed.load(); await nextTick()
    checks.push(notifications.diagnostic.value && !notifications.settings.value.enabled && document.body.textContent.includes('اختبار'))
    await notifications.enablePush(); await nextTick()
    checks.push(notifications.pushEnabled.value && diagnosticRegistrations===1 && normalRegistrations===0)
    await notifications.disablePush(); await nextTick()
    checks.push(!notifications.pushEnabled.value && diagnosticRemovals===1 && currentSubscription===null && played===4)
    Notification.requestPermission = originalPermission
    app.unmount(); Date.now=realNow
    await registration.unregister()
    return checks
  })()`)
  assert.deepEqual(results, Array(12).fill(true))
  assert.deepEqual(client.diagnostics, [])
  console.log('Passed: Vue preferences, disabled defaults, sound deduplication, pending reminder, confirmation stop, session reset, real Service Worker activation and manifest. API and audio were fixtures.')
} finally { client.close() }
