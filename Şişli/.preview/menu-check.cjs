const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
(async () => {
  const pages = await fetch('http://127.0.0.1:9333/json').then(r => r.json());
  const ws = new WebSocket(pages.find(p => p.type === 'page').webSocketDebuggerUrl);
  await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
  let id = 0;
  const pending = new Map(), errors = [];
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (pending.has(message.id)) { const { resolve, reject } = pending.get(message.id); pending.delete(message.id); message.error ? reject(message.error) : resolve(message.result); }
  });
  const send = (method, params = {}) => new Promise((resolve, reject) => { pending.set(++id, { resolve, reject }); ws.send(JSON.stringify({ id, method, params })); });
  const evaluate = async expression => { const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
  const assert = (value, label) => { if (!value) throw Error(label); console.log('PASS: ' + label); };
  await send('Runtime.enable');
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: pathToFileURL(path.resolve('menu.html')).href });
  await new Promise(resolve => setTimeout(resolve, 1500));
  await evaluate('document.fonts.ready.then(() => true)');
  assert(await evaluate("document.querySelectorAll('.product-card').length === 5"), 'Menu renders products');
  await evaluate("document.querySelector('[data-category=\"الشيش\"]').click()");
  assert(await evaluate("document.querySelectorAll('.product-card').length === 1"), 'Category filter');
  await evaluate("document.querySelector('[data-category=\"الكل\"]').click();document.querySelector('#product-search').value='كباب';document.querySelector('#product-search').dispatchEvent(new Event('input'))");
  assert(await evaluate("document.querySelectorAll('.product-card').length === 1"), 'Search filter');
  await evaluate("document.querySelector('#search-close').click();document.querySelector('#product-sort').value='price-desc';document.querySelector('#product-sort').dispatchEvent(new Event('change'))");
  assert(await evaluate("document.querySelector('.product-card h3').textContent === 'مشكل مشويات'"), 'Price sorting');
  await evaluate("localStorage.removeItem('shishli.cart.v1');location.reload()");
  await new Promise(resolve => setTimeout(resolve, 400));
  await evaluate("document.querySelector('[data-add=\"1\"]').click();document.querySelector('[data-add=\"3\"]').click();document.querySelector('[data-cart-open]').click()");
  assert(await evaluate("document.querySelector('#cart-total').textContent === '62 ريال' && document.querySelector('#cart-dialog').open"), 'Order review and total');
  await evaluate("document.querySelector('#cart-dialog').close()");
  for (const width of [1440,768,390,320]) {
    await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
    assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Menu no overflow '+width);
  }
  await send('Page.navigate',{url:pathToFileURL(path.resolve('index.html')).href});
  await new Promise(resolve => setTimeout(resolve, 500));
  assert(await evaluate("document.querySelector('#cart-count').textContent === '2'"), 'Cart shared with homepage');
  assert(await evaluate("!!document.querySelector('a[href=\"menu.html\"]') && !document.querySelector('[data-menu-open]')"), 'Homepage links to shop');
  await evaluate("localStorage.removeItem('shishli.cart.v1')");
  assert(errors.length === 0, 'No JavaScript errors');
  await send('Browser.close');
  ws.close();
})().catch(error => { console.error(error);process.exit(1); });