import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { publicPromotionsApi } from '../api/promotions.api'

export const useRegistrationOfferStore = defineStore('registrationOffer', () => {
  const offer = ref(null), currentTime = ref(Date.now())
  let controller, expiryTimer, revision = 0, loadedAt = 0, loadedBranch
  const activeOffer = computed(() => {
    const value = offer.value
    return value && (!value.ends_at || Date.parse(value.ends_at) >= currentTime.value) ? value : null
  })
  async function load(branchUuid = '') {
    currentTime.value = Date.now()
    if (loadedBranch === branchUuid && currentTime.value - loadedAt < 60000) return
    controller?.abort()
    clearTimeout(expiryTimer)
    controller = new AbortController()
    const version = ++revision
    const branchChanged = loadedBranch !== branchUuid
    loadedBranch = branchUuid
    loadedAt = 0
    if (branchChanged) offer.value = null
    try {
      const result = await publicPromotionsApi.list({ registration_offer: 1, branch_uuid: branchUuid }, controller.signal)
      if (version !== revision) return
      loadedAt = Date.now()
      const value = result.data[0]
      offer.value = value?.is_registration_offer && value.first_order_only && value.type === 'automatic' && value.discount?.type === 'percentage' ? value : null
      const delay = Date.parse(offer.value?.ends_at) - Date.now()
      if (Number.isFinite(delay) && delay > 0 && delay < 2147483647) {
        expiryTimer = setTimeout(() => { if (version === revision) { currentTime.value = Date.now(); offer.value = null; loadedAt = 0 } }, delay + 1)
      }
    } catch {
      // An optional announcement must not interrupt browsing or authentication.
      // Keep the current branch's valid offer on a transient refresh failure.
      // A successful empty response still removes an unavailable campaign.
      if (version === revision) loadedAt = 0
    }
  }
  function cancel() { controller?.abort(); clearTimeout(expiryTimer); revision++; loadedAt = 0 }
  return { activeOffer, load, cancel }
})
