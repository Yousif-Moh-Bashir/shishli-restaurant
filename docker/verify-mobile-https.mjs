import assert from 'node:assert/strict'
import fs from 'node:fs/promises'
import { X509Certificate, createHash } from 'node:crypto'
import https from 'node:https'
import tls from 'node:tls'
import { execFileSync } from 'node:child_process'

const state=JSON.parse(await fs.readFile('.local-mobile-https/state.json','utf8'))
const origin=state.origin, root=new X509Certificate(await fs.readFile('.local-mobile-https/shishli-rootCA.crt'))
const leaf=new X509Certificate(await fs.readFile('.local-mobile-https/server.crt'))
assert.equal(leaf.checkIP(state.lanIP),state.lanIP)
assert.equal(leaf.checkIP('192.168.1.219'),undefined)
assert.ok(Date.now()>Date.parse(leaf.validFrom)&&Date.now()<Date.parse(leaf.validTo),'Certificate valid now')
assert.equal(root.ca,true);assert.equal(leaf.verify(root.publicKey),true)
const ca=root.toString(),agent=new https.Agent({ca,rejectUnauthorized:true})
function request(path){return new Promise((resolve,reject)=>{
 const req=https.get(origin+path,{agent,headers:{Accept:'application/json'}},res=>{
  const chunks=[];res.on('data',chunk=>chunks.push(chunk));res.on('end',()=>resolve({status:res.statusCode,headers:res.headers,body:Buffer.concat(chunks)}))
 });req.setTimeout(20000,()=>req.destroy(Error('Request timeout '+path)));req.on('error',reject)
})}
function connection(identity=state.lanIP){return new Promise((resolve,reject)=>{
 const socket=tls.connect({host:state.lanIP,port:state.port,ca,rejectUnauthorized:true,checkServerIdentity:(_host,cert)=>tls.checkServerIdentity(identity,cert)},()=>{
  const result={authorized:socket.authorized,protocol:socket.getProtocol(),fingerprint:socket.getPeerCertificate().fingerprint256};socket.end();resolve(result)
 });socket.setTimeout(10000,()=>socket.destroy(Error('TLS timeout')));socket.on('error',reject)
})}
const transport=await connection();assert.equal(transport.authorized,true);assert.equal(transport.fingerprint,leaf.fingerprint256)
await assert.rejects(connection('192.168.1.219'),error=>error.code==='ERR_TLS_CERT_ALTNAME_INVALID')
const report={origin,tls:{...transport,subjectAltName:leaf.subjectAltName,validFrom:leaf.validFrom,validTo:leaf.validTo,wrongHostnameRejected:true,rootFingerprint:root.fingerprint256},checks:[],apis:[],assets:[],images:[],maps:[],containers:[]}
const home=await request('/');assert.equal(home.status,200);assert.match(home.body.toString(),/<div id="app">/)
const httpHome=await fetch('http://127.0.0.1:8088/');assert.equal(httpHome.status,200)
report.checks.push('Existing HTTP and LAN HTTPS both return the Vue application')
for(const path of [...new Set([...home.body.toString().matchAll(/(?:src|href)="(\/assets\/[^" ]+)"/g)].map(match=>match[1]))]){
 const secure=await request(path),plain=await fetch('http://127.0.0.1:8088'+path)
 assert.equal(secure.status,200);assert.equal(plain.status,200)
 const same=createHash('sha256').update(secure.body).digest('hex')===createHash('sha256').update(Buffer.from(await plain.arrayBuffer())).digest('hex')
 assert.equal(same,true,'Assets identical across HTTP/HTTPS');report.assets.push({path,status:200,identical:true})
}
assert.ok(report.assets.length)
function urls(value){
 if(typeof value==='string')return /^https?:\/\//.test(value)?[value]:[]
 if(value&&typeof value==='object')return Object.values(value).flatMap(urls)
 return[]
}
const publicJson=[]
for(const path of ['/api/v1/health','/api/v1/branches','/api/v1/settings/public','/api/v1/home','/api/v1/banners','/api/v1/gallery']){
 const result=await request(path)
 if (['/api/v1/home','/api/v1/banners','/api/v1/gallery'].includes(path) && result.status!==200) {
  const baseline=await fetch('http://127.0.0.1:8088'+path);assert.equal(baseline.status,result.status,'HTTPS must preserve the existing route status')
  publicJson.push(null);report.apis.push({path,status:result.status,baselineStatus:baseline.status,note:'Application failure also present on existing HTTP; this is not a passing API result'});continue
 }
 assert.equal(result.status,200,path);const data=JSON.parse(result.body.toString())
 assert.equal(data.success,true);assert.equal(urls(data).filter(url=>url.startsWith('http://')).length,0,'HTTP API/image URL '+path)
 publicJson.push(data);report.apis.push({path,status:200,insecureUrls:0})
}
const branch=publicJson[1].data[0];assert.ok(branch)
const menuPath='/api/v1/menu?branch='+branch.id,menu=await request(menuPath);assert.equal(menu.status,200)
const menuData=JSON.parse(menu.body.toString());assert.equal(menuData.success,true);assert.equal(urls(menuData).filter(url=>url.startsWith('http://')).length,0)
publicJson.push(menuData);report.apis.push({path:menuPath,status:200,insecureUrls:0})
const storageUrls=[...new Set(publicJson.flatMap(urls).filter(url=>url.startsWith(origin+'/storage/')))]
for(const url of storageUrls){const path=url.slice(origin.length),response=await request(path);assert.equal(response.status,200);report.images.push({path,status:200})}
if(!report.images.length)report.checks.push('No stored public images returned by available API routes; static Vue image assets are covered separately')
const reverse='/map-geocoding/reverse?'+new URLSearchParams({format:'jsonv2',lat:Number(branch.location.latitude).toFixed(7),lon:Number(branch.location.longitude).toFixed(7),addressdetails:'1','accept-language':'ar'})
const reversed=await request(reverse);assert.equal(reversed.status,200);assert.ok(JSON.parse(reversed.body.toString()).address)
report.maps.push({route:'reverse',status:200,cache:reversed.headers['x-geocoding-cache']})
const shared=await fetch('http://127.0.0.1:8088'+reverse);assert.equal(shared.status,200)
report.maps.push({route:'reverse HTTP baseline',status:200,cache:shared.headers.get('x-geocoding-cache')})
report.checks.push('Reverse geocoding succeeds through HTTPS and existing HTTP; cache status recorded without assuming upstream responses are cacheable')
const autocomplete='/map-geocoding/autocomplete?'+new URLSearchParams({q:branch.location.city,limit:'5',countrycode:'SA',lat:branch.location.latitude,lon:branch.location.longitude})
const searched=await request(autocomplete);assert.equal(searched.status,200);assert.ok(JSON.parse(searched.body.toString()).features.length)
report.maps.push({route:'autocomplete',status:200,cache:searched.headers['x-geocoding-cache']})
const denied=await request('/.local-mobile-https/server.key');assert.equal(denied.status,403);report.checks.push('Certificate/private files are not served over the web')
const old=await fs.readFile('docs/docker/evidence/local-mobile-https/before-containers.txt','utf8')
const current=execFileSync('docker',['ps','-a','--format','{{.Names}} {{.ID}}'],{encoding:'utf8'})
for(const line of old.trim().split(/\r?\n/)){assert.ok(current.split(/\r?\n/).includes(line),'Original container was recreated/removed: '+line);report.containers.push(line.split(' ')[0])}
const inspect=JSON.parse(execFileSync('docker',['inspect','shishli-mobile-https-1'],{encoding:'utf8'}))[0]
assert.deepEqual(inspect.NetworkSettings.Ports['443/tcp'].map(port=>({ip:port.HostIp,port:port.HostPort})),[{ip:state.lanIP,port:String(state.port)}])
assert.ok(inspect.Mounts.every(mount=>mount.RW===false))
assert.equal(inspect.Mounts.length,3);assert.ok(inspect.Mounts.every(mount=>!mount.Source.includes('mobile-https-ca')))
assert.deepEqual(Object.keys(inspect.NetworkSettings.Networks),['shishli_ingress'])
report.checks.push('All original container IDs retained; TLS port bound only to LAN IP; only server cert/key and config mounted read-only; no private network on HTTPS gateway')
await fs.mkdir('docs/docker/evidence/local-mobile-https',{recursive:true})
await fs.writeFile('docs/docker/evidence/local-mobile-https/tls-api-report.json',JSON.stringify(report,null,2))
console.log(JSON.stringify(report,null,2));agent.destroy()
