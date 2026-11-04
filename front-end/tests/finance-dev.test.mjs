import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createServer } from 'vite'
import { resolve } from 'node:path'

test('direct financial routes and refreshes serve the independent Vue entry during development', async () => {
  const server = await createServer({ configFile: resolve('vite.finance.config.js'), logLevel: 'silent', server: { port: 0, host: '127.0.0.1' } })
  try {
    await server.listen()
    const origin = `http://127.0.0.1:${server.httpServer.address().port}`
    for (const path of ['/', '/login', '/revenues', '/expenses', '/purchases', '/reports', '/settings']) {
      const response = await fetch(origin + path, { headers: { Accept: 'text/html' } })
      assert.equal(response.status, 200, path)
      const html = await response.text()
      assert.match(html, /\/src\/finance\/main.js/)
      assert.doesNotMatch(html, /src="\/src\/main.js"/)
    }
  } finally { await server.close() }
})
