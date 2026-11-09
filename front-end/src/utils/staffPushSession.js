export function clearStaffPushSession() {
  if (!('serviceWorker' in navigator)) return
  // No bearer token or other authentication secret is ever stored in the worker.
  void navigator.serviceWorker.getRegistration('/').then(async registration => {
    registration?.active?.postMessage({ type: 'staff-clear-notifications' })
    const subscription = await registration?.pushManager.getSubscription()
    await subscription?.unsubscribe()
  }).catch(() => {})
}
