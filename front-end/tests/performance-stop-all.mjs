// Only debugging ports created by this audit; do not close the user's normal browser.
const closed=[]
for (const port of [9451,9452,9454,9455,9456,9391]) {
  try {
    const response=await fetch('http://127.0.0.1:'+port+'/json/version',{signal:AbortSignal.timeout(1000)})
    const info=await response.json()
    await new Promise(resolve=>{
      const socket=new WebSocket(info.webSocketDebuggerUrl)
      const timer=setTimeout(()=>{socket.close();resolve()},1500)
      socket.addEventListener('open',()=>socket.send(JSON.stringify({id:1,method:'Browser.close'})))
      socket.addEventListener('close',()=>{clearTimeout(timer);resolve()})
      socket.addEventListener('error',()=>{clearTimeout(timer);resolve()})
    })
    closed.push(port)
  } catch {}
}
console.log(JSON.stringify({closed_owned_debug_ports:closed}))
