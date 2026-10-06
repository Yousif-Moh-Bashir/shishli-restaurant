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
  await send('Page.navigate', { url: pathToFileURL(path.resolve('index.html')).href });
  await new Promise(resolve => setTimeout(resolve, 1500));
  await evaluate('document.fonts.ready.then(() => true)');
  assert(await evaluate("document.querySelectorAll('.product-card').length === 5"), 'Five product cards render');
  assert(await evaluate("document.documentElement.scrollWidth <= innerWidth"), 'Desktop has no horizontal overflow');
  fs.writeFileSync('.preview/desktop.png', Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'));
  await evaluate("localStorage.removeItem('shishli.cart.v1'); location.reload()");
  await new Promise(resolve => setTimeout(resolve, 500));
  await evaluate("document.querySelector('[data-add=\"1\"]').click();document.querySelector('[data-add=\"1\"]').click();document.querySelector('[data-add=\"3\"]').click()");
  assert(await evaluate("document.querySelector('#cart-count').textContent === '3' && document.querySelector('#cart-total').textContent === '79 ريال'"), 'Cart quantity and total');
  await evaluate('location.reload()');
  await new Promise(resolve => setTimeout(resolve, 500));
  assert(await evaluate("document.querySelector('#cart-count').textContent === '3'"), 'Cart persists on reload');
  await evaluate("document.querySelector('[data-cart-open]').click();document.querySelector('[data-quantity=\"1\"][data-delta=\"-1\"]').click();document.querySelector('[data-remove=\"3\"]').click()");
  assert(await evaluate("document.querySelector('#cart-total').textContent === '17 ريال'"), 'Decrease and remove update totals');
  await evaluate("document.querySelector('[data-remove=\"1\"]').click()");
  assert(await evaluate("!!document.querySelector('.cart-empty') && document.querySelector('#cart-count').textContent === '0'"), 'Empty cart state');
  await evaluate("document.querySelector('#cart-dialog').close();document.querySelector('[data-search-open]').click();document.querySelector('#product-search').value='كباب';document.querySelector('#product-search').dispatchEvent(new Event('input'))");
  assert(await evaluate("document.querySelectorAll('.product-card').length === 1"), 'Product search filters');
  await evaluate("document.querySelector('#search-close').click();document.querySelector('[data-menu-open]').click()");
  assert(await evaluate("document.querySelector('#info-dialog').open && !!document.querySelector('.menu-image')"), 'Menu dialog opens');
  await evaluate("document.querySelector('#info-dialog').close();scrollTo(0,0)");
  for (const width of [768, 390, 320]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    assert(await evaluate('document.documentElement.scrollWidth <= innerWidth'), `No overflow at ${width}px`);
    if (width === 390) {
      await evaluate('scrollTo(0,0)');
      await new Promise(resolve => setTimeout(resolve, 300));
      fs.writeFileSync('.preview/mobile.png', Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })).data, 'base64'));
      await evaluate("document.querySelector('#menu-toggle').click()");
      assert(await evaluate("document.querySelector('#menu-toggle').getAttribute('aria-expanded') === 'true'"), 'Mobile menu opens');
      await evaluate("document.querySelector('#main-nav a').click()");
      assert(await evaluate("document.querySelector('#menu-toggle').getAttribute('aria-expanded') === 'false'"), 'Mobile navigation closes menu');
    }
  }
  await evaluate("localStorage.setItem('shishli.cart.v1', '[{\"id\":999,\"quantity\":5},{\"id\":1,\"quantity\":-2},null]');location.reload()");
  await new Promise(resolve => setTimeout(resolve, 400));
  assert(await evaluate("document.querySelector('#cart-count').textContent === '0'"), 'Invalid stored cart is sanitized');
  await evaluate("localStorage.removeItem('shishli.cart.v1')");
  assert(errors.length === 0, 'No browser JavaScript errors');
  ws.close();
})().catch(error => { console.error(error); process.exit(1); });
