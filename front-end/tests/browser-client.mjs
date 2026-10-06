import {spawn} from 'node:child_process'
import {mkdir} from 'node:fs/promises'
export async function browserClient(port,output){
 await mkdir(output,{recursive:true});const process=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port='+port,'--user-data-dir='+output+'/profile','about:blank'],{windowsHide:true,stdio:'ignore'}),sleep=ms=>new Promise(r=>setTimeout(r,ms))
 let pages;for(let i=0;i<80;i++){try{pages=await(await fetch('http://127.0.0.1:'+port+'/json')).json();if(pages.length)break}catch{}await sleep(250)}
 const socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);await new Promise(r=>socket.addEventListener('open',r,{once:true}));let seq=0;const pending=new Map(),diagnostics=[]
 const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq,timer=setTimeout(()=>{pending.delete(id);reject(Error('CDP timeout '+method))},20000);pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value)},reject:error=>{clearTimeout(timer);reject(error)}});socket.send(JSON.stringify({id,method,params}))})
 socket.addEventListener('message',({data})=>{const m=JSON.parse(data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(m.error):p.resolve(m.result)}else if(m.method==='Runtime.exceptionThrown')diagnostics.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);else if(m.method==='Runtime.consoleAPICalled'&&['error','warning'].includes(m.params.type))diagnostics.push(m.params.args.map(a=>a.value||a.description).join(' '))})
 const evaluate=async expression=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value}
 const wait=async expression=>{for(let i=0;i<160;i++){if(await evaluate(expression))return;await sleep(250)}throw Error('Timeout '+expression+' '+JSON.stringify({diagnostics,page:await evaluate('({path:location.pathname,text:document.body.innerText.slice(0,300)})')}))}
 const go=async(origin,path)=>{await send('Page.navigate',{url:origin+path});await wait('document.querySelector("main")')}
 const input=(selector,value)=>evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Missing input');e.value=${JSON.stringify(value)};e.dispatchEvent(new Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}))})()`)
 const click=(selector,label)=>evaluate(`(()=>{const e=[...document.querySelectorAll(${JSON.stringify(selector)})].find(e=>e.textContent.includes(${JSON.stringify(label)}));if(!e)throw Error('Missing control '+${JSON.stringify(label)});e.click()})()`)
 socket.addEventListener('message',({data})=>{const m=JSON.parse(data);if(m.method==='Page.javascriptDialogOpening')send('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{})})
 const onEvent=(method,callback)=>{const listener=({data})=>{const message=JSON.parse(data);if(message.method===method)callback(message.params)};socket.addEventListener('message',listener);return()=>socket.removeEventListener('message',listener)}
 await send('Page.enable');await send('Runtime.enable');return {send,evaluate,wait,go,input,click,diagnostics,sleep,onEvent,close:()=>{socket.close();process.kill()}}
}
