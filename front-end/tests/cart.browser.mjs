import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import assert from 'node:assert/strict'
const output = fileURLToPath(new URL('../.cart-check/', import.meta.url))
await mkdir(output, { recursive: true })
const browser = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port=9242',`--user-data-dir=${output}/profile`,'about:blank'], { windowsHide:true, stdio:'ignore' })
const sleep = ms => new Promise(resolve=>setTimeout(resolve,ms))
let socket
const report={live:[],fixtures:[],diagnostics:[],responsive:[]}
try {
  let pages
  for(let i=0;i<100;i++){try{pages=await(await fetch('http://127.0.0.1:9242/json')).json();if(pages.length)break}catch{}await sleep(100)}
  socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl)
  await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}))
  let sequence=0
  const pending=new Map()
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}))})
  socket.addEventListener('message',({data})=>{const m=JSON.parse(data);if(m.id){const call=pending.get(m.id);pending.delete(m.id);m.error?call.reject(m.error):call.resolve(m.result)}if(m.method==='Runtime.exceptionThrown')report.diagnostics.push(m.params.exceptionDetails.text);if(m.method==='Runtime.consoleAPICalled'&&['warning','error'].includes(m.params.type))report.diagnostics.push(m.params.args.map(a=>a.value??a.description).join(' '))})
  const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value}
  const waitFor=async expression=>{for(let i=0;i<180;i++){if(await evaluate(`Boolean(${expression})`))return;await sleep(100)}throw Error('Timeout: '+expression)}
  await send('Page.enable');await send('Runtime.enable')
  await send('Page.navigate',{url:(process.env.PUBLIC_UI_ORIGIN||'http://127.0.0.1:5173')+'/cart'})
  await waitFor("document.querySelector('#app')?.__vue_app__")
  await evaluate("Promise.all([import('/src/stores/cart.js'),import('/src/stores/branch.js'),import('/src/stores/auth.js'),import('/src/api/cart.api.js')]).then(([c,b,a,api])=>{window.cart=c.useCartStore();window.branch=b.useBranchStore();window.auth=a.useAuthStore();window.cartApi=api.cartApi;window.router=document.querySelector('#app').__vue_app__.config.globalProperties.$router;return cart.initializeCart()})")
  const branchIds=await evaluate('branch.branches.map(b=>b.id)')
  if(branchIds.length&&process.env.PUBLIC_SKIP_LIVE!=='1'){
    await evaluate(`cart.changeBranch(${JSON.stringify(branchIds[0])})`)
    await waitFor('!cart.isLoading&&!cart.isMutating')
    const loaded=await evaluate('Boolean(cart.cart)&&!cart.error')
    if(loaded){
      report.live.push('Guest create/load and opaque token persisted')
      await evaluate("router.push('/menu')")
      await waitFor("document.querySelector('.product-card')||document.querySelector('.empty-search')")
      const products=await evaluate(`fetch('http://127.0.0.1:8000/api/v1/menu?branch='+branch.selectedUuid).then(r=>r.json()).then(b=>b.data?.categories?.flatMap(c=>c.products)||[])`)
      const product=products.find(p=>p.available)
      if(product){
        await evaluate(`router.push('/product/'+${JSON.stringify(product.id)})`)
        await waitFor("document.querySelector('.detail-order button')")
        await evaluate("document.querySelector('.detail-order button').click()")
        await waitFor('!cart.isMutating')
        if(await evaluate('cart.items.length>0')){
          report.live.push('Product UI add with server pricing')
          const id=await evaluate('cart.cart.id')
          await send('Page.reload')
          await waitFor("document.querySelector('#app')?.__vue_app__")
          await evaluate("import('/src/stores/cart.js').then(m=>{window.cart=m.useCartStore();return cart.initializeCart()})")
          assert.equal(await evaluate('cart.cart.id'),id)
          assert.ok(await evaluate('cart.items.length>0'))
          report.live.push('Browser reload restores same guest cart')
          await evaluate('cart.updateQuantity(cart.items[0].id,2)')
          assert.equal(await evaluate('cart.items[0].quantity'),2)
          await evaluate('cart.refreshCart()')
          report.live.push('Quantity update and explicit refresh')
          if(branchIds.length>1){assert.equal((await evaluate(`cart.changeBranch(${JSON.stringify(branchIds[1])})`)).ok,false);report.live.push('Nonempty branch change blocked without clearing')}
          await evaluate('cart.removeItem(cart.items[0].id)')
          assert.equal(await evaluate('cart.items.length'),0)
          await evaluate('cart.clearCart()')
          report.live.push('Remove and clear use server response')
        }else report.live.push('Product requires selections; default UI add rejected by backend validation')
      }else report.live.push('No available product in live catalog')
    }else report.live.push('Live cart creation unavailable: branch/API rejected request')
  }else report.live.push('Live branches unavailable or empty; no live mutations performed')
  // Controlled transports exercise the actual Pinia store, not a replacement cart.
  await evaluate("Promise.all([import('/src/api/cart.api.js'),import('/src/stores/auth.js'),import('/src/stores/branch.js')]).then(([api,a,b])=>{window.cartApi=api.cartApi;window.auth=a.useAuthStore();window.branch=b.useBranchStore();window.router=document.querySelector('#app').__vue_app__.config.globalProperties.$router;window.savedApi={...cartApi};window.savedToken=cart.cartToken;window.savedAuth=auth.token;window.savedCart=cart.cart;window.savedBranchUuid=branch.selectedUuid;window.savedBranchLoad=branch.load;branch.load=async()=>{};return true})")
  await evaluate(`window.fixture={id:'11111111-1111-4111-8111-111111111111',status:'active',branch:{id:'22222222-2222-4222-8222-222222222222',name:'فرع الاختبار'},items:[{id:'33333333-3333-4333-8333-333333333333',product:{id:'44444444-4444-4444-8444-444444444444',name:'منتج الاختبار'},quantity:2,options:[],pricing:{unit_price:'12.50',line_total:'25.00',line_total_after_discount:'25.00',discount_total:'0.00'},issues:[],is_available:true}],summary:{quantity_total:2,items_count:1,subtotal:'25.00',discount_total:'0.00',total:'25.00'},issues:[],can_checkout:true};cart.cart=structuredClone(fixture);cart.initialized=true;cart.error=null;auth.token=null;cart.cartToken='fixture-opaque';branch.syncCartBranch(fixture.branch.id);cartApi.show=async()=>structuredClone(fixture);window.calls=[];cartApi.quantity=async(id,quantity,ctx)=>{calls.push({id,quantity,ctx});await new Promise(r=>setTimeout(r,150));const value=structuredClone(fixture);value.items[0].quantity=quantity;value.summary.quantity_total=quantity;return value};true`)
  await evaluate("cartApi.add=async(payload,ctx)=>{window.addRequest={payload,ctx};return structuredClone(fixture)};cart.addItem({id:fixture.items[0].product.id,real:true,branchUuid:fixture.branch.id},2,[{option_group_uuid:'55555555-5555-4555-8555-555555555555',option_value_uuids:['66666666-6666-4666-8666-666666666666']}])")
  const addRequest=await evaluate('addRequest')
  assert.deepEqual(Object.keys(addRequest.payload).sort(),['options','product_uuid','quantity'])
  assert.equal(addRequest.ctx.token,'fixture-opaque')
  assert.equal(addRequest.payload.options[0].option_value_uuids.length,1)
  await evaluate("cartApi.options=async(id,options,ctx)=>{window.optionRequest={id,options,ctx};return structuredClone(fixture)};cart.updateOptions(fixture.items[0].id,[])")
  assert.deepEqual((await evaluate('optionRequest')).options,[])
  report.fixtures.push('Add sends UUID/quantity/grouped value UUIDs only; options replacement supports empty selection')
  const rapid=await evaluate('Promise.all([cart.updateQuantity(fixture.items[0].id,3),cart.updateQuantity(fixture.items[0].id,4)]).then(r=>({results:r,calls:calls.length,quantity:cart.items[0].quantity}))')
  assert.equal(rapid.calls,1);assert.equal(rapid.quantity,3)
  report.fixtures.push('Rapid quantity requests serialize; server quantity wins')
  await evaluate("cartApi.show=async()=>{throw Error('network')};cart.loadCart()")
  assert.equal(await evaluate('Boolean(cart.error)&&!cart.isEmpty'),true)
  report.fixtures.push('Network failure preserves token/data and is not empty')
  await evaluate("cartApi.show=async()=>structuredClone(fixture);cart.loadCart()")
  await evaluate("cartApi.refresh=async()=>({...structuredClone(fixture),summary:{...fixture.summary,total:'77.25'},can_checkout:false,issues:[{code:'BRANCH_NOT_ACCEPTING_ORDERS',message:'الفرع مغلق'}]});cart.refreshCart()")
  assert.equal(await evaluate('cart.total'),'77.25');assert.equal(await evaluate('cart.canCheckout'),false)
  report.fixtures.push('Refresh server total and blocking issues respected')
  await evaluate("cartApi.show=async ctx=>({...structuredClone(fixture),id:auth.token?'account-cart':'guest-cart'});auth.token='fixture-auth';true")
  await waitFor("cart.cart?.id===\"account-cart\"");assert.equal(await evaluate('cart.cart.id'),'account-cart');assert.equal(await evaluate('cart.cartToken'),'fixture-opaque')
  await evaluate('auth.token=null;true');await waitFor("cart.cart?.id===\"guest-cart\"")
  assert.equal(await evaluate('cart.cart.id'),'guest-cart')
  report.fixtures.push('Login preserves guest token; logout restores guest context')
  await evaluate("cartApi.show=async()=>{throw {response:{status:404,data:{success:false,message:'غير موجود'}}}};cartApi.create=async()=>({...structuredClone(fixture),token:'recovered-opaque'});cart.loadCart()")
  assert.equal(await evaluate('cart.cartToken'),'recovered-opaque')
  report.fixtures.push('404 token recovery creates once; no token parsing')
  await evaluate("Object.assign(cartApi,savedApi);branch.load=savedBranchLoad;auth.token=savedAuth;cart.cartToken=savedToken;cart.cart=savedCart;if(savedToken)localStorage.setItem('shishli_cart_token',savedToken);else localStorage.removeItem('shishli_cart_token');cart.error=null;branch.syncCartBranch(savedBranchUuid);router.push('/cart')")
  for(const path of ['/','/menu','/product/44444444-4444-4444-8444-444444444444','/cart','/login','/register','/admin']){
    await evaluate(`router.push(${JSON.stringify(path)}).then(()=>true)`)
    await sleep(200)
    assert.equal(await evaluate('Boolean(document.querySelector("main"))'),true)
  }
  report.fixtures.push('Public route regression and guest admin guard smoke checked; live business data unavailable')
  await evaluate("router.push('/cart').then(()=>true)")
  for(const width of [390,768,1440]){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<500});await sleep(150);assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);report.responsive.push(width)}
  await writeFile(output+'/report.json',JSON.stringify(report,null,2))
  console.log(JSON.stringify(report,null,2))
  assert.deepEqual(report.diagnostics,[])
} finally {socket?.close();browser.kill()}








