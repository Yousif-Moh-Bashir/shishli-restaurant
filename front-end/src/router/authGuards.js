import { adminNavigation } from '../constants/adminNavigation'
import { canAccessNavigation } from '../constants/adminNavigation'
import { reportDefinitions } from '../constants/reports'
function canAccessReportRoute(auth, route) {
  if (route.meta.permissionsAny && !route.meta.permissionsAny.some(permission => auth.can(permission))) return false
  if (route.name === 'admin.reports' && route.query.report) {
    const report = reportDefinitions.find(item => item.key === route.query.report)
    return !!report && auth.can(report.permission)
  }
  return true
}
export function authenticatedHome(auth) { return auth.hasAdminAccess ? adminNavigation.find(item => canAccessNavigation(auth, item))?.path || '/forbidden' : auth.isDriver ? '/driver/orders' : '/' }
export function safeIntendedRoute(router, auth, value) {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\') || /[\u0000-\u001f]/.test(value)) return null
  const route = router.resolve(value)
  if (!route.matched.length || route.meta.guestOnly || ['forbidden','not-found'].includes(route.name)) return null
  if (route.meta.requiresAdmin && !auth.hasAdminAccess) return null
  if (route.meta.requiresDriver && !auth.isDriver) return null
  if (route.meta.permission && !auth.can(route.meta.permission)) return null
  if (!canAccessReportRoute(auth, route)) return null
  return route.fullPath
}
export function installAuthGuards(router, auth) {
  router.beforeEach(async to => {
    await auth.initialize()
    if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
    if (to.meta.requiresAdmin && !auth.hasAdminAccess) return { name: 'forbidden' }
    if (to.meta.requiresDriver && !auth.isDriver) return { name: 'forbidden' }
    if (to.meta.permission && !auth.can(to.meta.permission)) return { name: 'forbidden' }
    if (!canAccessReportRoute(auth, to)) return { name: 'forbidden' }
    if (to.meta.guestOnly && auth.isAuthenticated) return authenticatedHome(auth)
  })
}
