import assert from 'node:assert/strict'
import { createNominatimProvider, createDeliveryLocationProvider, normalizeGeocodingResult, setLocationProvider, searchLocations } from '../src/services/locationProvider.js'
const requests = []
const provider = createNominatimProvider({ interval: 0, fetcher: async (url, options) => {
  requests.push({ url, options })
  return { ok: true, json: async () => url.includes('/search') ? [
    { place_id: 1, lat: '28.4', lon: '36.6', display_name: 'شارع الملك خالد، تبوك', address: { town: 'تبوك', quarter: 'المصيف', road: 'الملك خالد' } },
    { place_id: 2, lat: '99', lon: '36', display_name: 'Invalid' },
  ] : { address: { city: 'تبوك', suburb: 'المصيف' }, display_name: 'المصيف، تبوك' } }
} })
assert.deepEqual(await provider.search('حي'), [])
assert.equal(requests.length, 0, 'Short queries never reach the provider')
const found = await provider.search('الملك خالد', { city: 'تبوك' })
assert.equal(found.length, 1)
assert.equal(found[0].latitude, 28.4)
assert.equal(new URL(requests[0].url).searchParams.get('q'), 'الملك خالد, تبوك')
await provider.search('الملك خالد', { city: 'تبوك' })
assert.equal(requests.length, 1, 'Repeat search uses the cache')
const aborted = new AbortController(); aborted.abort()
await assert.rejects(provider.reverse(28.5,36.7,{signal:aborted.signal}), { name: 'AbortError' })
assert.equal(requests.length, 1)
await assert.rejects(provider.reverse(91,36), /INVALID_LOCATION/)
await provider.reverse(28.4,36.6); await provider.reverse(28.4,36.6)
assert.equal(requests.length, 2)
assert.equal(requests[0].options.credentials, 'omit')
assert.equal(normalizeGeocodingResult({address:{house_number:'99'}}).building_number, undefined)
await assert.rejects(createNominatimProvider({searchUrl:'disabled'}).search('شارع'), /GEOCODING_DISABLED/)
await assert.rejects(createNominatimProvider({interval:0,fetcher:async()=>({ok:false})}).search('شارع'), /GEOCODING_FAILED/)
setLocationProvider({reverse:async()=>({city:'بديل'}),search:async()=>found})
assert.equal((await searchLocations('شارع'))[0].district,'المصيف')
const started=[]
const throttled=createNominatimProvider({interval:50,fetcher:async()=>{started.push(Date.now());return{ok:true,json:async()=>({address:{city:'تبوك'}})}}})
await Promise.all([throttled.reverse(28.1,36.1),throttled.reverse(28.2,36.2)])
assert.ok(started[1]-started[0]>=45,'Provider serializes/rate-limits requests')
const autocompleteRequests=[]
const autocomplete=createDeliveryLocationProvider({interval:0,fetcher:async(url)=>{
 autocompleteRequests.push(new URL(url))
 return{ok:true,json:async()=>({features:[{geometry:{coordinates:[36.55,28.44]},properties:{osm_id:90,osm_type:'W',name:'شارع الملك خالد',street:'شارع الملك خالد',district:'المصيف',city:'تبوك',countrycode:'SA'}},{geometry:{coordinates:[36,99]},properties:{name:'Invalid'}},{geometry:{coordinates:[44.4,33.3]},properties:{name:'شارع في العراق',city:'بغداد',countrycode:'IQ'}},{geometry:{coordinates:[36.55,28.44]},properties:{name:'بلد خاطئ رغم نقطة قريبة',countrycode:'IQ'}},{geometry:{coordinates:[36.55,28.44]},properties:{name:'مدينة مختلفة رغم نقطة قريبة',city:'الرياض',countrycode:'SA'}}]})}
}})
assert.deepEqual(await autocomplete.search('حي'),[])
const suggestions=await autocomplete.search('الملك خالد',{city:'تبوك',center:{latitude:28.4,longitude:36.5}})
assert.equal(autocompleteRequests[0].pathname,'/map-geocoding/autocomplete')
assert.equal(autocompleteRequests[0].searchParams.get('lat'),'28.4')
assert.equal(autocompleteRequests[0].searchParams.get('countrycode'),'SA')
assert.equal(autocompleteRequests[0].searchParams.get('q'),'الملك خالد, تبوك')
assert.ok(autocompleteRequests[0].searchParams.get('bbox'))
assert.equal(autocompleteRequests[0].searchParams.has('format'),false,'No Nominatim autocomplete request')
assert.equal(suggestions.length,1)
assert.equal(suggestions[0].latitude,28.44)
assert.equal(suggestions[0].longitude,36.55)
assert.equal(suggestions[0].formattedAddress,'شارع الملك خالد، المصيف، تبوك')
await autocomplete.search('الملك خالد',{city:'تبوك',center:{latitude:28.4,longitude:36.5}})
assert.equal(autocompleteRequests.length,1,'Autocomplete uses cache')
console.log('Location provider: search, cache, abort, invalid points, errors, provider replacement and shared queue passed')
