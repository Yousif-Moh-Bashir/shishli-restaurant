import { createRouter, createWebHistory } from 'vue-router'
import { useFinanceSession } from './session'
import Login from './views/Login.vue'
import Dashboard from './views/Dashboard.vue'
import Revenues from './views/Revenues.vue'
import Outflows from './views/Outflows.vue'
import Settings from './views/Settings.vue'
import Users from './views/Users.vue'
import Audit from './views/Audit.vue'
import AccessDenied from './views/AccessDenied.vue'

export const router = createRouter({ history: createWebHistory(), routes: [
  { path: '/login', component: Login, meta: { public: true } },
  { path: '/forbidden', component: AccessDenied, meta: { title: 'الوصول غير متاح' } },
  { path: '/', component: Dashboard, meta: { permission: 'reports.view', title: 'لوحة التحكم' } },
  { path: '/reports', component: Dashboard, meta: { permission: 'reports.view', title: 'التقارير المالية' } },
  { path: '/revenues', component: Revenues, meta: { permission: 'revenues.view', title: 'الإيرادات اليومية' } },
  { path: '/expenses', component: Outflows, props: { kind: 'expenses' }, meta: { permission: 'expenses.view', title: 'المصروفات' } },
  { path: '/purchases', component: Outflows, props: { kind: 'purchases' }, meta: { permission: 'purchases.view', title: 'المشتريات' } },
  { path: '/suppliers', component: Settings, props: { kind: 'suppliers' }, meta: { permission: 'suppliers.view', title: 'الموردون' } },
  { path: '/settings', component: Settings, props: { kind: 'sources' }, meta: { permission: 'settings.manage', title: 'الإعدادات المالية' } },
  { path: '/users', component: Users, meta: { permission: 'users.manage', title: 'المستخدمون والصلاحيات' } },
  { path: '/audit', component: Audit, meta: { permission: 'audit.view', title: 'سجل التدقيق' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
] })
router.beforeEach(async to => {
  const session = useFinanceSession()
  if (to.path === '/forbidden') return true
  if (to.meta.public) return session.ready ? '/revenues' : true
  if (!session.token) return '/login'
  if (!session.ready) {
    try { await session.load() } catch (error) { if ([401, 403].includes(error.response?.status)) session.clear(); return '/login' }
  }
  if (to.meta.permission && !session.can(to.meta.permission)) {
    return session.can('revenues.view') && to.path !== '/revenues' ? '/revenues' : '/forbidden'
  }
})
