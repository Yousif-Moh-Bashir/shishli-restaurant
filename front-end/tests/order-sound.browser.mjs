import assert from 'node:assert/strict'
import {fileURLToPath} from 'node:url'
import {browserClient} from './browser-client.mjs'
const client=await browserClient(9373,fileURLToPath(new URL('../.checkout-check/sound-profile/',import.meta.url)))
try {
 await client.go('http://127.0.0.1:5176','/')
 const checks=await client.evaluate(`(async()=>{
 const source=await(await fetch('/src/composables/useNewOrderSound.js')).text();const vueUrl=source.match(/from ["']([^"']*vue[.]js[^"']*)["']/)[1];const {createApp,h}=await import(vueUrl);const {useNewOrderSound}=await import('/src/composables/useNewOrderSound.js');
 let played=0,closed=false;const original=window.AudioContext;window.AudioContext=class {state='running';currentTime=0;destination={};constructor(){window.mockOrderAudio=this}async resume(){this.state='running'}async close(){closed=true}createOscillator(){return {frequency:{value:0},connect(){},start(){played++},stop(){}}}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}}}};
 const root=document.createElement('div');document.body.append(root);let sound;const app=createApp({setup(){sound=useNewOrderSound();return ()=>h('div')}});app.mount(root);
 const order=(id,time)=>({id,placed_at:'2026-10-06T'+time+':00Z'});const result=[];
 sound.observe([order('a','01:00')]);result.push(played===0);
 await sound.toggle();result.push(sound.enabled.value&&played===2);
 sound.observe([order('a','01:00')]);result.push(played===2);
 sound.observe([order('b','01:01')]);result.push(played===4);
 sound.observe([order('b','01:01')]);result.push(played===4);
 sound.observe([order('old','00:59')]);result.push(played===4);
 await sound.toggle();sound.observe([order('c','01:02')]);result.push(!sound.enabled.value&&played===4);
 await sound.toggle();sound.reset();sound.observe([order('other-session','02:00')]);result.push(played===6);
 sound.observe([order('same-second','02:00')]);result.push(played===8);
 window.mockOrderAudio.state='suspended';sound.observe([order('resume','02:01')]);await Promise.resolve();result.push(played===10);
 sound.reset();sound.observe([{...order('changed-placement','05:00'),created_at:'2026-10-06T02:00:00Z'}]);result.push(played===10);
 sound.observe([{...order('new-after-update','02:02'),created_at:'2026-10-06T02:02:00Z'}]);result.push(played===12);
 app.unmount();result.push(closed);window.AudioContext=original;root.remove();return result;
 })()`)
 assert.deepEqual(checks,Array(13).fill(true));assert.deepEqual(client.diagnostics,[])
 console.log('Passed: silent baseline, enable preview, new ID at equal count, duplicate suppression, older-order suppression, mute, session reset, same-second arrival and audio cleanup. Audio output was mocked.')
}finally{client.close()}
