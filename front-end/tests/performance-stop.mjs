const response = await fetch('http://127.0.0.1:9451/json/version').catch(() => null)
if (response) {
  const socket = new WebSocket((await response.json()).webSocketDebuggerUrl)
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }))
  socket.send(JSON.stringify({ id: 1, method: 'Browser.close' }))
  await new Promise(resolve => socket.addEventListener('close', resolve, { once: true }))
}
