import { fileURLToPath } from 'node:url'
import { writeFile } from 'node:fs/promises'
import assert from 'node:assert/strict'
import { browserClient } from './browser-client.mjs'
const output = fileURLToPath(new URL('../.gallery-check/', import.meta.url))
const client = await browserClient(9347, output)
const origin = process.env.PUBLIC_UI_ORIGIN || 'http://127.0.0.1:5176'
const report = { responsive: [], checks: [], diagnostics: [] }
try {
  await client.go(origin, '/')
  await client.wait('document.querySelectorAll(".gallery-photo-button").length>1')
  await client.send('Emulation.setDeviceMetricsOverride', {width:390,height:900,deviceScaleFactor:1,mobile:true})
  await client.evaluate('document.querySelector(".site-gallery-carousel").scrollIntoView({block:"center"})')
  await client.wait('Math.abs(document.querySelector(".gallery-banner-photos").scrollLeft)>20')
  await client.evaluate('document.querySelector(".gallery-banner-photos").scrollTo({left:-100000,behavior:"instant"})')
  await client.wait('document.querySelectorAll(".gallery-controls .icon-button")[1].disabled')
  await client.wait('Math.abs(document.querySelector(".gallery-banner-photos").scrollLeft)<5')
  await client.click('.gallery-autoplay-button','إيقاف')
  await client.sleep(500)
  const pausedPosition=await client.evaluate('document.querySelector(".gallery-banner-photos").scrollLeft')
  await client.sleep(2500)
  assert.equal(await client.evaluate('document.querySelector(".gallery-banner-photos").scrollLeft'),pausedPosition)
  report.checks.push('Autoplay advances without arrows, loops from end, and pause stops the timer')
  for (const width of [390,768,1440]) {
    await client.send('Emulation.setDeviceMetricsOverride', {width,height:900,deviceScaleFactor:1,mobile:width===390})
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true)
    await client.click('.gallery-photo-button','')
    await client.wait('document.querySelector(".gallery-lightbox[open]")')
    assert.equal(await client.evaluate('document.querySelector(".gallery-lightbox[open]").getBoundingClientRect().width<=innerWidth'),true)
    await client.evaluate('document.querySelector(".gallery-lightbox[open]").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowLeft",bubbles:true}))')
    await client.wait('document.querySelector(".gallery-lightbox[open] .gallery-lightbox-foot span").textContent.trim().startsWith("2")')
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27})
    await client.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27})
    await client.wait('!document.querySelector(".gallery-lightbox[open]")')
    report.responsive.push(width)
  }
  report.checks.push('Home preview, keyboard navigation, Escape close, viewport containment')
  await client.evaluate(`(async()=>{const {cmsApi}=await import('/src/api/cms.api.js');cmsApi.page=async()=>{throw {status:404,message:'Not published'}};cmsApi.gallery=async()=>({data:[1,2,3].map(id=>({id,title:'صورة '+id,image_url:'/src/assets/images/banners/restaurant-reference.png',alt_text:'صورة للاختبار'})),meta:{current_page:1,last_page:1}});})()`)
  await client.evaluate(`(async()=>{await document.querySelector('#app').__vue_app__.config.globalProperties.$router.push('/gallery')})()`)
  await client.wait('document.querySelectorAll(".site-gallery-grid .gallery-photo-button").length===3')
  assert.equal(await client.evaluate('document.querySelector("main").textContent.includes("الصفحة غير منشورة")'),false)
  await client.click('.gallery-photo-button','')
  await client.wait('document.querySelector(".gallery-lightbox[open] img")?.complete')
  assert.equal(await client.evaluate('document.querySelector(".gallery-lightbox[open] img").naturalWidth>0'),true)
  report.checks.push('Published gallery transport fixture works when CMS page is missing; image opens')
  report.diagnostics=client.diagnostics
  assert.deepEqual(report.diagnostics,[])
  await writeFile(output+'/report.json',JSON.stringify(report,null,2))
  console.log(JSON.stringify(report))
} finally { client.close() }
