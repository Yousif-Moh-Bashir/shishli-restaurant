import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
const origin='http://127.0.0.1:8088',output='.delivery-ux-check/proxy-report.json'
const branches=(await(await fetch(origin+'/api/v1/branches')).json()).data
const point=branches[0].location
const query=new URLSearchParams({format:'jsonv2',lat:point.latitude,lon:point.longitude,addressdetails:'1','accept-language':'ar'})
const url=origin+'/map-geocoding/reverse?'+query
const first=await fetch(url),data=await first.json()
assert.equal(first.status,200);assert.ok(data.address)
const second=await fetch(url);assert.equal(second.status,200);assert.equal(second.headers.get('X-Geocoding-Cache'),'HIT')
const started=Date.now(),parallel=await Promise.all([fetch(url),fetch(url),fetch(url)])
assert.ok(parallel.every(response=>response.status===200))
assert.ok(Date.now()-started>=1500,'Application-wide limiter delays concurrent requests even across callers')
const search=await fetch(origin+'/map-geocoding/search?'+new URLSearchParams({format:'jsonv2',q:'شارع عمرو بن العاص، تبوك',limit:'3',addressdetails:'1','accept-language':'ar'}))
assert.equal(search.status,200);assert.ok(Array.isArray(await search.json()))
const autocomplete=await fetch(origin+'/map-geocoding/autocomplete?'+new URLSearchParams({q:'تبوك',limit:'5',lat:point.latitude,lon:point.longitude}))
assert.equal(autocomplete.status,200);const suggestions=await autocomplete.json();assert.ok(suggestions.features?.length>0,'Photon returns live suggestions')
assert.equal((await fetch(url,{method:'POST'})).status,405)
assert.equal((await fetch(origin+'/map-geocoding/unknown')).status,404)
const report={origin,reverseStatus:first.status,initialCache:first.headers.get('X-Geocoding-Cache'),repeatCache:second.headers.get('X-Geocoding-Cache'),parallelRequests:parallel.length,rateLimiterVerified:true,searchStatus:search.status,autocompleteStatus:autocomplete.status,autocompleteResults:suggestions.features.length,unknownRoute:404,postMethod:405}
await mkdir('.delivery-ux-check',{recursive:true});await writeFile(output,JSON.stringify(report,null,2));console.log(JSON.stringify(report))
