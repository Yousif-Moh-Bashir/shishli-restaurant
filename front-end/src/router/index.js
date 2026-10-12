import { createRouter, createWebHistory } from 'vue-router'
const PublicBranchesView=()=>import('../views/site/PublicBranchesView.vue')
const PublicOffersView=()=>import('../views/site/PublicOffersView.vue')
const NotFoundView=()=>import('../views/errors/NotFoundView.vue')
import SiteLayout from '../layouts/SiteLayout.vue'
const HomeView=()=>import('../views/site/HomeView.vue')
const MenuView=()=>import('../views/site/MenuView.vue')
const ProductView=()=>import('../views/site/ProductView.vue')
const LoginView=()=>import('../views/auth/LoginView.vue')
const RegisterView=()=>import('../views/auth/RegisterView.vue')
const CartView=()=>import('../views/site/CartView.vue')
const CheckoutView=()=>import('../views/site/CheckoutView.vue')
const OrderSuccessView=()=>import('../views/site/OrderSuccessView.vue')
const TrackOrderView=()=>import('../views/site/TrackOrderView.vue')
const CmsPageView=()=>import('../views/site/CmsPageView.vue')
const ProfileView=()=>import('../views/site/ProfileView.vue')
const AdminLayout = () => import('../layouts/AdminLayout.vue')
const DashboardView=()=>import('../views/admin/DashboardView.vue')
const ReportsView=()=>import('../views/admin/ReportsView.vue')
import { reportPermissions } from '../constants/reports'
const OrdersView=()=>import('../views/admin/OrdersView.vue')
const ProductsView=()=>import('../views/admin/ProductsView.vue')
const CategoriesView=()=>import('../views/admin/CategoriesView.vue')
const OffersView=()=>import('../views/admin/PromotionsView.vue')
const CustomersView=()=>import('../views/admin/CustomerManagementView.vue')
const StaffView=()=>import('../views/admin/StaffView.vue')
const RolesView=()=>import('../views/admin/RolesView.vue')
const ActivityLogsView=()=>import('../views/admin/ActivityLogsView.vue')
const InventoryView=()=>import('../views/admin/InventoryView.vue')
const CmsView=()=>import('../views/admin/CmsView.vue')
const SettingsView=()=>import('../views/admin/SettingsView.vue')
const ForbiddenView=()=>import('../views/errors/ForbiddenView.vue')
const BranchesView=()=>import('../views/admin/BranchesView.vue')
const BranchDetailsView=()=>import('../views/admin/BranchDetailsView.vue')
const ProductOptionsView=()=>import('../views/admin/ProductOptionsView.vue')
const OptionGroupDetailsView=()=>import('../views/admin/OptionGroupDetailsView.vue')
const ProductDetailsView=()=>import('../views/admin/ProductDetailsView.vue')
import { operationPermissions } from '../constants/settings'
import { adminNavigation } from '../constants/adminNavigation'
const AddressesView=()=>import('../views/site/AddressesView.vue')
const DeliveryZonesView=()=>import('../views/admin/DeliveryZonesView.vue')
const CustomerOrdersView=()=>import('../views/site/CustomerOrdersView.vue')
const CustomerOrderView=()=>import('../views/site/CustomerOrderView.vue')
const AdminOrderDetailsView=()=>import('../views/admin/OrderDetailsView.vue')
const KitchenView=()=>import('../views/admin/KitchenView.vue')
const PaymentsView=()=>import('../views/admin/PaymentsView.vue')
const PaymentDetailsView=()=>import('../views/admin/PaymentDetailsView.vue')
const NotificationsView=()=>import('../views/admin/NotificationsView.vue')
const NotificationDetailsView=()=>import('../views/admin/NotificationDetailsView.vue')

export default createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [{
    path: '/',
    component: SiteLayout,
    children: [
      { path: '', name: 'home', component: HomeView },
      {path:'branches',name:'branches',component:PublicBranchesView,meta:{title:'الفروع | مطعم شيشلي'}},
      {path:'offers',name:'offers',component:PublicOffersView,meta:{title:'العروض | مطعم شيشلي'}},
      {path:':pathMatch(.*)*',name:'not-found',component:NotFoundView,meta:{title:'الصفحة غير موجودة | مطعم شيشلي'}},
      { path: 'menu', name: 'menu', component: MenuView },
      { path: 'product/:slug', name: 'product', component: ProductView },
      { path: 'login', name: 'login', component: LoginView, meta: { guestOnly: true } },
      { path: 'register', name: 'register', component: RegisterView, meta: { guestOnly: true } },
      { path: 'forbidden', name: 'forbidden', component: ForbiddenView, meta: { title: 'غير مصرح | مطعم شيشلي' } },
      { path: 'cart', name: 'cart', component: CartView },
      { path: 'orders', name: 'customer-orders', component: CustomerOrdersView, meta: { title: 'طلباتي | مطعم شيشلي' } },
      { path: 'account', redirect: '/account/profile', meta: { requiresAuth: true } },
      { path: 'account/profile', name:'account.profile',component:ProfileView,meta:{requiresAuth:true,title:'حسابي | مطعم شيشلي'} },
      { path: 'account/addresses', name: 'account.addresses', component: AddressesView, meta: { requiresAuth: true, title: 'عناويني | مطعم شيشلي' } },
      { path: 'account/orders', name: 'account.orders', component: CustomerOrdersView, meta: { requiresAuth: true, title: 'طلباتي | مطعم شيشلي' } },
      { path: 'account/orders/:orderUuid', name: 'account.order', component: CustomerOrderView, meta: { requiresAuth: true, title: 'تفاصيل الطلب | مطعم شيشلي' } },
      { path: 'checkout', name: 'checkout', component: CheckoutView },
      { path: 'order-success/:orderUuid?', name: 'order-success', component: OrderSuccessView },
      { path: 'track-order', name: 'track-order', component: TrackOrderView },
      { path: 'track-order/:orderUuid', name: 'track-order.detail', component: TrackOrderView },
      { path: 'about', name: 'about', component: CmsPageView, meta: {cmsKey:'about', title: 'من نحن | مطعم شيشلي' } },
      { path: 'gallery', name: 'gallery', component: CmsPageView, meta: {cmsKey:'gallery', title: 'معرض الصور | مطعم شيشلي' } },
      { path: 'contact', name: 'contact', component: CmsPageView, meta: {cmsKey:'contact', title: 'تواصل معنا | مطعم شيشلي' } },
    ],
  }, {
    path: '/driver', component: () => import('../layouts/DriverLayout.vue'),
    meta: { requiresAuth: true, requiresDriver: true },
    children: [
      { path: '', redirect: '/driver/orders' },
      { path: 'orders', name: 'driver.orders', component: () => import('../views/driver/DriverOrdersView.vue') },
      { path: 'orders/:id', name: 'driver.order', component: () => import('../views/driver/DriverOrderView.vue') },
      { path: 'collections', name: 'driver.collections', component: () => import('../views/driver/DriverCollectionsView.vue') },
    ],
  }, {
    path: '/admin',
    component: AdminLayout,
    meta: { requiresAuth: true, requiresAdmin: true },
    children: [
      { path: 'drivers', name: 'admin.drivers', component: () => import('../views/admin/DriversView.vue'), meta: { adminTitle: 'مناديب التوصيل', permission: 'drivers.view' } },
      {path:'staff',name:'admin.staff',component:StaffView,meta:{adminTitle:'الموظفون',permission:'staff.view'}},
      {path:'roles',name:'admin.roles',component:RolesView,meta:{adminTitle:'الأدوار والصلاحيات',permission:'roles.view'}},
      {path:'activity-logs',name:'admin.activity-logs',component:ActivityLogsView,meta:{adminTitle:'سجل النشاط',permission:'activity_logs.view'}},
      {path:'inventory',name:'admin.inventory',component:InventoryView,meta:{adminTitle:'المخزون',permissionsAny:['inventory.view','inventory.movements.view']}},
      {path:'cms',name:'admin.cms',component:CmsView,meta:{adminTitle:'محتوى الموقع',permissionsAny:['cms.pages.view','cms.banners.view','cms.gallery.view']}},
      { path: '', name: 'admin.dashboard', component: DashboardView, meta: { title: 'نظرة عامة | لوحة شيشلي', adminTitle: 'نظرة عامة' } },
      { path: 'reports', name: 'admin.reports', component: ReportsView, meta: { title: 'التقارير | لوحة شيشلي', adminTitle: 'التقارير', permissionsAny: reportPermissions } },
      { path: 'branches', name: 'admin.branches', component: BranchesView, meta: { adminTitle: 'الفروع', permission: 'branches.view' } },
      { path: 'delivery-zones', name: 'admin.delivery-zones', component: DeliveryZonesView, meta: { adminTitle: 'مناطق التوصيل', permission: 'delivery_zones.view' } },
      { path: 'branches/:uuid', name: 'admin.branch', component: BranchDetailsView, meta: { adminTitle: 'إدارة الفرع', permission: 'branches.view' } },
      { path: 'product-options', name: 'admin.product-options', component: ProductOptionsView, meta: { adminTitle: 'خيارات المنتجات', permission: 'options.view' } },
      { path: 'product-options/:uuid', name: 'admin.option-group', component: OptionGroupDetailsView, meta: { adminTitle: 'قيم الخيارات', permission: 'options.view' } },
      { path: 'products/:uuid', name: 'admin.product', component: ProductDetailsView, meta: { adminTitle: 'إدارة المنتج', permission: 'products.view' } },
      { path: 'orders', name: 'admin.orders', component: OrdersView, meta: { title: 'الطلبات | لوحة شيشلي', adminTitle: 'الطلبات' } },
      { path: 'orders/:orderUuid', name: 'admin.order', component: AdminOrderDetailsView, meta: { adminTitle: 'تفاصيل الطلب', permission: 'orders.view' } },
      { path: 'kitchen', name: 'admin.kitchen', component: KitchenView, meta: { adminTitle: 'المطبخ', permission: 'orders.view' } },
      { path: 'kitchen/:orderUuid', name: 'admin.kitchen.order', component: AdminOrderDetailsView, meta: { adminTitle: 'تفاصيل التجهيز', permission: 'orders.view', kitchen: true } },
      { path: 'payments', name: 'admin.payments', component: PaymentsView, meta: { adminTitle: 'المدفوعات', permission: 'payments.view' } },
      { path: 'payments/:paymentUuid', name: 'admin.payment', component: PaymentDetailsView, meta: { adminTitle: 'تفاصيل الدفع', permission: 'payments.view' } },
      { path: 'notifications', name: 'admin.notifications', component: NotificationsView, meta: { adminTitle: 'الإشعارات', permission: 'notifications.view' } },
      { path: 'notifications/:notificationUuid', name: 'admin.notification', component: NotificationDetailsView, meta: { adminTitle: 'تفاصيل الإشعار', permission: 'notifications.view' } },
      { path: 'products', name: 'admin.products', component: ProductsView, meta: { title: 'المنتجات | لوحة شيشلي', adminTitle: 'المنتجات' } },
      { path: 'categories', name: 'admin.categories', component: CategoriesView, meta: { title: 'التصنيفات | لوحة شيشلي', adminTitle: 'التصنيفات' } },
      { path: 'offers', name: 'admin.offers', component: OffersView, meta: { title: 'العروض | لوحة شيشلي', adminTitle: 'العروض' } },
      { path: 'customers', name: 'admin.customers', component: CustomersView, meta: { title: 'العملاء | لوحة شيشلي', adminTitle: 'العملاء' } },
      { path: 'settings', name: 'admin.settings', component: SettingsView, meta: { title: 'الإعدادات | لوحة شيشلي', adminTitle: 'الإعدادات', permissionsAny:['settings.view','settings.audit.view',...operationPermissions] } },
    ].map(route => ({ ...route, meta: { ...route.meta, permission: route.meta.permission || adminNavigation.find(item => item.path === `/admin${route.path ? '/' + route.path : ''}`)?.permission } })),
  }],
  async scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.name === 'home' && to.hash === '#home') return { top: 0, left: 0, behavior: 'instant' }
    if (to.hash) {
      // Allow the target view to mount before resolving its anchor.
      await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))
      return { el: to.hash, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }
    }
    return { top: 0 }
  },
})
