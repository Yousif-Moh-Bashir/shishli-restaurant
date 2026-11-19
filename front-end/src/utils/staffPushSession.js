export function clearStaffPushSession({ unregister = false } = {}) {
  if (!('serviceWorker' in navigator)) return
  // No bearer token or other authentication secret is ever stored in the worker.
  return navigator.serviceWorker.getRegistration('/').then(async registration => {
    registration?.active?.postMessage({ type: 'staff-clear-notifications' })
    if (unregister) {
      const subscription = await registration?.pushManager.getSubscription()
      await subscription?.unsubscribe()
    }
  }).catch(error => { if (unregister) throw error })
}

export async function restoreStaffPushSubscription(subscription, register, isCurrent = () => true) {
  try { return await register(subscription.toJSON()) }
  catch (error) {
    // An endpoint owned by another employee must not keep receiving their alerts
    // in the newly signed-in employee's browser. Never transfer its ownership.
    if (error.response?.status === 409 && error.response?.headers?.['x-staff-push-conflict'] === 'ownership' && isCurrent()) await subscription.unsubscribe()
    throw error
  }
}
