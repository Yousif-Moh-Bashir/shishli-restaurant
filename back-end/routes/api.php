<?php

use App\Http\Controllers\Api\V1\Admin\ActivityLogController;
use App\Http\Controllers\Api\V1\Admin\AdminSessionController;
use App\Http\Controllers\Api\V1\Admin\BranchClosureController;
use App\Http\Controllers\Api\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\V1\Admin\BranchProductController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\CmsBannerController;
use App\Http\Controllers\Api\V1\Admin\CmsGalleryController;
use App\Http\Controllers\Api\V1\Admin\CmsPageController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DeliveryZoneController;
use App\Http\Controllers\Api\V1\Admin\GuestCustomerController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\NotificationController;
use App\Http\Controllers\Api\V1\Admin\OptionGroupController;
use App\Http\Controllers\Api\V1\Admin\OptionValueController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\OrderHistoryController;
use App\Http\Controllers\Api\V1\Admin\OrderOperationController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductOptionGroupController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RestaurantSettingsController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\BranchBusinessHoursController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BranchStatusController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartCouponController;
use App\Http\Controllers\Api\V1\CartDeliveryQuoteController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CmsController;
use App\Http\Controllers\Api\V1\CustomerAddressController;
use App\Http\Controllers\Api\V1\CustomerProfileController;
use App\Http\Controllers\Api\V1\DeliveryQuoteController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\GuestOrderController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KitchenOrderController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderCancellationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentController;
use App\Http\Controllers\Api\V1\OrderTrackingController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PublicConfigurationController;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\OptionalCartAuthentication;
use App\Http\Middleware\RecordAdminActivity;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\Admin\StaffService;
use App\Services\Reports\ReportService;
use Illuminate\Support\Facades\Route;

Route::bind('address', function (string $uuid): CustomerAddress {
    abort_unless(request()->user() !== null, 401);

    return request()->user()->addresses()->where('uuid', $uuid)->firstOrFail();
});
Route::bind('staffUser', fn (string $uuid): User => app(StaffService::class)->find($uuid));
Route::bind('driver', fn (string $uuid): User => User::role('driver')->where('uuid', $uuid)->firstOrFail());

Route::prefix('v1/driver')->name('api.v1.driver.')->middleware(['auth:sanctum', 'role:driver', 'permission:driver.access'])->group(function (): void {
    $controller = DriverController::class;
    Route::get('/me', [$controller, 'me'])->name('me');
    Route::patch('/availability', [$controller, 'availability'])->name('availability');
    Route::get('/orders', [$controller, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [$controller, 'show'])->whereUuid('order')->name('orders.show');
    Route::post('/orders/{order}/accept', [$controller, 'accept'])->whereUuid('order')->middleware('throttle:60,1')->name('orders.accept');
    Route::post('/orders/{order}/transition', [$controller, 'transition'])->whereUuid('order')->middleware('throttle:60,1')->name('orders.transition');
    Route::post('/orders/{order}/cash/collect', [$controller, 'collect'])->whereUuid('order')->middleware('throttle:30,1')->name('orders.collect');
    Route::get('/collections', [$controller, 'collections'])->name('collections');
});

Route::prefix('v1/admin')->name('api.v1.admin.')->middleware(['auth:sanctum', EnsureAdminAccess::class])->group(function (): void {
    $controller = App\Http\Controllers\Api\V1\Admin\DriverController::class;
    Route::get('/drivers', [$controller, 'index'])->middleware('permission:drivers.view')->name('drivers.index');
    Route::post('/drivers', [$controller, 'store'])->middleware('permission:drivers.manage')->name('drivers.store');
    Route::get('/drivers/{driver}', [$controller, 'show'])->whereUuid('driver')->middleware('permission:drivers.view')->name('drivers.show');
    Route::put('/drivers/{driver}', [$controller, 'update'])->whereUuid('driver')->middleware('permission:drivers.manage')->name('drivers.update');
    Route::get('/drivers/{driver}/collections', [$controller, 'collections'])->whereUuid('driver')->middleware('permission:drivers.view')->name('drivers.collections');
    Route::post('/drivers/{driver}/settlements', [$controller, 'settle'])->whereUuid('driver')->middleware('permission:drivers.settle_cash')->name('drivers.settle');
    Route::post('/orders/{order}/reassign-driver', [$controller, 'reassign'])->whereUuid('order')->middleware('permission:drivers.reassign')->name('orders.reassign-driver');
});

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/home', [CmsController::class, 'home'])->name('home');
    Route::get('/pages/{pageKey}', [CmsController::class, 'page'])->name('pages.show');
    Route::get('/banners', [CmsController::class, 'banners'])->name('banners');
    Route::get('/gallery', [CmsController::class, 'gallery'])->name('gallery');
    Route::get('/promotions', PromotionController::class)->name('promotions.index');
    Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)
        ->where('provider', '[a-z0-9_-]{1,50}')->middleware('throttle:120,1')->name('payments.webhook');
    Route::post('/orders/{order}/guest/payments', [OrderPaymentController::class, 'guest'])
        ->whereUuid('order')->middleware('throttle:30,1')->name('orders.guest.payments');
    Route::get('/kitchen/orders', KitchenOrderController::class)->middleware(['auth:sanctum', 'permission:orders.view'])->name('kitchen.orders');
    Route::post('/orders/{order}/guest/cancel', [OrderCancellationController::class, 'guest'])
        ->whereUuid('order')->middleware('throttle:60,1')->name('orders.guest.cancel');
    Route::post('/checkout', CheckoutController::class)
        ->middleware([OptionalCartAuthentication::class, 'throttle:checkout'])->name('checkout');
    Route::get('/orders/{order}/guest', GuestOrderController::class)
        ->whereUuid('order')->middleware('throttle:60,1')->name('orders.guest');
    Route::post('/orders/track', [OrderTrackingController::class, 'guest'])
        ->middleware('throttle:order-tracking')->name('orders.track');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/orders/{order}/tracking', [OrderTrackingController::class, 'show'])
            ->whereUuid('order')->name('orders.tracking');
        Route::get('/orders/{order}/payments', [OrderPaymentController::class, 'index'])->whereUuid('order')->name('orders.payments.index');
        Route::post('/orders/{order}/payments', [OrderPaymentController::class, 'store'])->whereUuid('order')->middleware('throttle:30,1')->name('orders.payments.store');
        Route::post('/orders/{order}/cancel', [OrderCancellationController::class, 'authenticated'])->whereUuid('order')->name('orders.cancel');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereUuid('order')->name('orders.show');
    });
    Route::get('/health', HealthController::class)->name('health');
    Route::post('/delivery/quote', DeliveryQuoteController::class)->middleware('throttle:delivery-quote')->name('delivery.quote');
    Route::prefix('addresses')->name('addresses.')->middleware('auth:sanctum')->group(function (): void {
        Route::get('/', [CustomerAddressController::class, 'index'])->name('index');
        Route::post('/', [CustomerAddressController::class, 'store'])->name('store');
        Route::get('/{address}', [CustomerAddressController::class, 'show'])->whereUuid('address')->name('show');
        Route::patch('/{address}', [CustomerAddressController::class, 'update'])->whereUuid('address')->name('update');
        Route::delete('/{address}', [CustomerAddressController::class, 'destroy'])->whereUuid('address')->name('destroy');
        Route::patch('/{address}/default', [CustomerAddressController::class, 'setDefault'])->whereUuid('address')->name('default');
    });
    Route::prefix('cart')->name('cart.')->middleware([OptionalCartAuthentication::class, 'throttle:cart'])->group(function (): void {
        Route::post('/coupon', [CartCouponController::class, 'store'])->middleware('throttle:10,1')->name('coupon.store');
        Route::delete('/coupon', [CartCouponController::class, 'destroy'])->name('coupon.destroy');
        Route::post('/delivery/quote', CartDeliveryQuoteController::class)->name('delivery.quote');
        Route::post('/', [CartController::class, 'store'])->middleware('throttle:cart-create')->name('store');
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::patch('/', [CartController::class, 'update'])->name('update');
        Route::post('/refresh', [CartController::class, 'refresh'])->name('refresh');
        Route::post('/items', [CartItemController::class, 'store'])->name('items.store');
        Route::delete('/items', [CartItemController::class, 'clear'])->name('items.clear');
        Route::patch('/items/{cartItem}/options', [CartItemController::class, 'options'])->whereUuid('cartItem')->name('items.options');
        Route::patch('/items/{cartItem}', [CartItemController::class, 'update'])->whereUuid('cartItem')->name('items.update');
        Route::delete('/items/{cartItem}', [CartItemController::class, 'destroy'])->whereUuid('cartItem')->name('items.destroy');
    });
    Route::get('/menu', [MenuController::class, 'index'])->name('menu.index');
    Route::get('/menu/products/{product}', [MenuController::class, 'show'])->name('menu.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');

    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', EnsureAdminAccess::class, RecordAdminActivity::class])->group(function (): void {
        Route::get('/me', [AdminSessionController::class, 'me'])->name('me');
        Route::get('/bootstrap', [AdminSessionController::class, 'bootstrap'])->name('bootstrap');
        Route::get('/navigation', [AdminSessionController::class, 'navigation'])->name('navigation');
        Route::get('/kitchen/orders', KitchenOrderController::class)->middleware('permission:orders.view')->name('kitchen.orders');
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity_logs.view')->name('activity-logs.index');
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view')->name('permissions.index');
        Route::put('/roles/{role}/permissions', [RoleController::class, 'update'])->middleware(['permission:roles.update_permissions', 'throttle:admin-roles'])->name('roles.permissions.update');
        Route::prefix('staff')->name('staff.')->group(function (): void {
            Route::get('/', [StaffController::class, 'index'])->middleware('permission:staff.view')->name('index');
            Route::post('/', [StaffController::class, 'store'])->middleware(['permission:staff.create', 'throttle:admin-sensitive'])->name('store');
            Route::get('/{staffUser}', [StaffController::class, 'show'])->whereUuid('staffUser')->middleware('permission:staff.view')->name('show');
            Route::patch('/{staffUser}', [StaffController::class, 'update'])->whereUuid('staffUser')->middleware(['permission:staff.update', 'throttle:admin-sensitive'])->name('update');
            foreach (['activate', 'deactivate'] as $operation) {
                Route::post('/{staffUser}/'.$operation, [StaffController::class, $operation])->whereUuid('staffUser')->middleware(['permission:staff.'.$operation, 'throttle:admin-sensitive'])->name($operation);
            }
        });
        Route::get('/settings/groups', [RestaurantSettingsController::class, 'groups'])->middleware('permission:settings.view')->name('settings.groups');
        Route::get('/orders/counts', [AdminOrderController::class, 'counts'])->middleware('permission:orders.view')->name('orders.counts');
        Route::get('/categories/tree', [AdminCategoryController::class, 'tree'])->middleware('permission:categories.view')->name('categories.tree');
        foreach (['status', 'availability'] as $operation) {
            Route::post('/products/bulk/'.$operation, [AdminProductController::class, 'bulk'])->middleware(['permission:products.update', 'throttle:admin-sensitive'])->name('products.bulk.'.$operation);
        }
        Route::prefix('pages')->name('pages.')->scopeBindings()->group(function (): void {
            $controller = CmsPageController::class;
            Route::get('/', [$controller, 'index'])->middleware('permission:cms.pages.view')->name('index');
            Route::get('/{page}', [$controller, 'show'])->whereUuid('page')->middleware('permission:cms.pages.view')->name('show');
            Route::patch('/{page}', [$controller, 'update'])->whereUuid('page')->middleware('permission:cms.pages.update')->name('update');
            Route::get('/{page}/sections', [$controller, 'sections'])->whereUuid('page')->middleware('permission:cms.pages.view')->name('sections');
            Route::patch('/{page}/sections/{section}', [$controller, 'updateSection'])->whereUuid('page')->whereUuid('section')->middleware('permission:cms.pages.update')->name('sections.update');
        });
        foreach ([
            'banners' => [CmsBannerController::class, 'banner'],
            'gallery' => [CmsGalleryController::class, 'galleryItem'],
        ] as $path => [$controller, $parameter]) {
            Route::prefix($path)->name($path.'.')->group(function () use ($path, $controller, $parameter): void {
                Route::get('/', [$controller, 'index'])->middleware('permission:cms.'.$path.'.view')->name('index');
                Route::post('/', [$controller, 'store'])->middleware('permission:cms.'.$path.'.create')->name('store');
                Route::patch('/reorder', [$controller, 'reorder'])->middleware('permission:cms.'.$path.'.update')->name('reorder');
                Route::get('/{'.$parameter.'}', [$controller, 'show'])->whereUuid($parameter)->middleware('permission:cms.'.$path.'.view')->name('show');
                Route::patch('/{'.$parameter.'}', [$controller, 'update'])->whereUuid($parameter)->middleware('permission:cms.'.$path.'.update')->name('update');
                Route::delete('/{'.$parameter.'}', [$controller, 'destroy'])->whereUuid($parameter)->middleware('permission:cms.'.$path.'.delete')->name('destroy');
            });
        }
        Route::get('/reports/inventory', [InventoryController::class, 'report'])
            ->middleware('permission:inventory.view')->name('reports.inventory');
        Route::post('/orders/{order}/stock/return', [InventoryController::class, 'returnStock'])
            ->whereUuid('order')->middleware('permission:inventory.adjust')->name('orders.stock.return');
        Route::prefix('inventory')->name('inventory.')->group(function (): void {
            $controller = InventoryController::class;
            Route::get('/', [$controller, 'index'])->middleware('permission:inventory.view')->name('index');
            Route::post('/', [$controller, 'store'])->middleware('permission:inventory.create')->name('store');
            Route::get('/movements', [$controller, 'movements'])->middleware('permission:inventory.movements.view')->name('movements');
            Route::get('/{inventory}', [$controller, 'show'])->whereUuid('inventory')->middleware('permission:inventory.view')->name('show');
            Route::patch('/{inventory}', [$controller, 'update'])->whereUuid('inventory')->middleware('permission:inventory.adjust')->name('update');
            Route::post('/{inventory}/receive', [$controller, 'receive'])->whereUuid('inventory')->middleware('permission:inventory.receive')->name('receive');
            Route::post('/{inventory}/adjust', [$controller, 'adjust'])->whereUuid('inventory')->middleware('permission:inventory.adjust')->name('adjust');
            Route::post('/{inventory}/waste', [$controller, 'waste'])->whereUuid('inventory')->middleware('permission:inventory.waste')->name('waste');
            Route::get('/{inventory}/movements', [$controller, 'movements'])->whereUuid('inventory')->middleware('permission:inventory.movements.view')->name('movements.history');
        });
        Route::get('/settings', [RestaurantSettingsController::class, 'index'])
            ->middleware('permission:settings.view')->name('settings.index');
        Route::patch('/settings', [RestaurantSettingsController::class, 'update'])
            ->middleware('permission:settings.update')->name('settings.update');
        Route::get('/settings/audit', [RestaurantSettingsController::class, 'audit'])
            ->middleware('permission:settings.audit.view')->name('settings.audit');
        Route::prefix('branches/{branch}')->whereUuid('branch')->name('branches.')->scopeBindings()->group(function (): void {
            Route::get('/business-hours', [App\Http\Controllers\Api\V1\Admin\BranchBusinessHoursController::class, 'index'])
                ->middleware('permission:branches.hours.view')->name('hours.index');
            Route::put('/business-hours', [App\Http\Controllers\Api\V1\Admin\BranchBusinessHoursController::class, 'update'])
                ->middleware('permission:branches.hours.update')->name('hours.update');
            Route::get('/closures', [BranchClosureController::class, 'index'])
                ->middleware('permission:branches.closures.view')->name('closures.index');
            Route::post('/closures', [BranchClosureController::class, 'store'])
                ->middleware('permission:branches.closures.create')->name('closures.store');
            Route::patch('/closures/{closure}', [BranchClosureController::class, 'update'])
                ->whereUuid('closure')->middleware('permission:branches.closures.update')->name('closures.update');
            Route::delete('/closures/{closure}', [BranchClosureController::class, 'destroy'])
                ->whereUuid('closure')->middleware('permission:branches.closures.delete')->name('closures.destroy');
        });
        Route::get('/dashboard', DashboardController::class)
            ->middleware('permission:dashboard.view')->name('dashboard');
        foreach (ReportService::PERMISSIONS as $report => $permission) {
            Route::get('/reports/'.$report, [ReportController::class, 'index'])
                ->defaults('report', $report)->middleware('permission:'.$permission)->name('reports.'.str_replace('/', '.', $report));
            if (in_array($report, ['orders', 'sales/trend', 'products', 'payments', 'customers', 'promotions'], true)) {
                Route::get('/reports/'.$report.'/export', [ReportController::class, 'export'])
                    ->defaults('report', $report)->middleware(['permission:'.$permission, 'permission:reports.export', 'throttle:5,1'])
                    ->name('reports.'.str_replace('/', '.', $report).'.export');
            }
        }
        Route::prefix('customers')->name('customers.')->group(function (): void {
            Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('index');
            Route::get('/{customer}', [CustomerController::class, 'show'])->whereUuid('customer')->middleware('permission:customers.view')->name('show');
            Route::get('/{customer}/orders', [CustomerController::class, 'orders'])->whereUuid('customer')->middleware('permission:customers.view')->name('orders');
            Route::get('/{customer}/addresses', [CustomerController::class, 'addresses'])->whereUuid('customer')->middleware('permission:customers.view')->name('addresses');
            Route::post('/{customer}/activate', [CustomerController::class, 'activate'])->whereUuid('customer')->middleware('permission:customers.activate')->name('activate');
            Route::post('/{customer}/deactivate', [CustomerController::class, 'deactivate'])->whereUuid('customer')->middleware('permission:customers.deactivate')->name('deactivate');
        });
        Route::get('/guest-customers', [GuestCustomerController::class, 'index'])->middleware('permission:customers.view')->name('guest-customers.index');
        Route::get('/guest-customers/orders', [GuestCustomerController::class, 'orders'])->middleware('permission:customers.view')->name('guest-customers.orders');
        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index'])->middleware('permission:notifications.view')->name('index');
            Route::get('/{notification}', [NotificationController::class, 'show'])->whereUuid('notification')->middleware('permission:notifications.view')->name('show');
            Route::get('/{notification}/attempts', [NotificationController::class, 'attempts'])->whereUuid('notification')->middleware('permission:notifications.view')->name('attempts');
            Route::post('/{notification}/retry', [NotificationController::class, 'retry'])->whereUuid('notification')->middleware('permission:notifications.retry')->name('retry');
        });
        Route::prefix('promotions')->name('promotions.')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'index'])->middleware('permission:promotions.view')->name('index');
            Route::post('/', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'store'])->middleware('permission:promotions.create')->name('store');
            Route::get('/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'show'])->whereUuid('promotion')->middleware('permission:promotions.view')->name('show');
            Route::patch('/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'update'])->whereUuid('promotion')->middleware('permission:promotions.update')->name('update');
            Route::delete('/{promotion}', [App\Http\Controllers\Api\V1\Admin\PromotionController::class, 'destroy'])->whereUuid('promotion')->middleware('permission:promotions.delete')->name('destroy');
            Route::prefix('{promotion}/coupons')->whereUuid('promotion')->name('coupons.')->scopeBindings()->group(function (): void {
                Route::get('/', [CouponController::class, 'index'])->middleware('permission:coupons.view')->name('index');
                Route::post('/', [CouponController::class, 'store'])->middleware('permission:coupons.create')->name('store');
                Route::patch('/{coupon}', [CouponController::class, 'update'])->whereUuid('coupon')->middleware('permission:coupons.update')->name('update');
                Route::delete('/{coupon}', [CouponController::class, 'destroy'])->whereUuid('coupon')->middleware('permission:coupons.delete')->name('destroy');
            });
        });
        Route::post('/orders/{order}/payments/cash/collect', [AdminPaymentController::class, 'collect'])
            ->whereUuid('order')->middleware('permission:payments.collect_cash')->name('orders.payments.cash.collect');
        Route::middleware('permission:payments.view')->prefix('payments')->name('payments.')->group(function (): void {
            Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
            Route::get('/{payment}', [AdminPaymentController::class, 'show'])->whereUuid('payment')->name('show');
            Route::get('/{payment}/transactions', [AdminPaymentController::class, 'transactions'])->whereUuid('payment')->name('transactions');
        });
        Route::prefix('orders/{order}')->whereUuid('order')->name('orders.')->group(function (): void {
            Route::post('/confirm', [OrderOperationController::class, 'confirm'])->middleware('permission:orders.confirm')->name('confirm');
            Route::post('/start-preparing', [OrderOperationController::class, 'startPreparing'])->middleware('permission:orders.start_preparing')->name('start-preparing');
            Route::post('/mark-ready', [OrderOperationController::class, 'markReady'])->middleware('permission:orders.mark_ready')->name('mark-ready');
            Route::post('/dispatch', [OrderOperationController::class, 'dispatch'])->middleware('permission:orders.dispatch')->name('dispatch');
            Route::post('/complete', [OrderOperationController::class, 'complete'])->middleware('permission:orders.complete')->name('complete');
            Route::post('/cancel', [OrderOperationController::class, 'cancel'])->middleware('permission:orders.cancel')->name('cancel');
            Route::get('/history', OrderHistoryController::class)->middleware('permission:orders.view')->name('history');
        });
        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->whereUuid('order')->middleware('permission:orders.view')->name('orders.show');
        Route::prefix('branches/{branch}/delivery-zones')->name('delivery-zones.')->scopeBindings()->group(function (): void {
            Route::get('/', [DeliveryZoneController::class, 'index'])->middleware('permission:delivery_zones.view')->name('index');
            Route::post('/', [DeliveryZoneController::class, 'store'])->middleware('permission:delivery_zones.create')->name('store');
            Route::get('/{deliveryZone}', [DeliveryZoneController::class, 'show'])->middleware('permission:delivery_zones.view')->name('show');
            Route::patch('/{deliveryZone}', [DeliveryZoneController::class, 'update'])->middleware('permission:delivery_zones.update')->name('update');
            Route::delete('/{deliveryZone}', [DeliveryZoneController::class, 'destroy'])->middleware('permission:delivery_zones.delete')->name('destroy');
        });
        Route::prefix('branches/{branch}/products')->name('branches.products.')->scopeBindings()->group(function (): void {
            Route::get('/', [BranchProductController::class, 'index'])->middleware('permission:branches.view')->name('index');
            Route::middleware('permission:branches.products.manage')->group(function (): void {
                Route::post('/', [BranchProductController::class, 'store'])->name('store');
                Route::post('/bulk', [BranchProductController::class, 'bulk'])->name('bulk');
                Route::patch('/{product}', [BranchProductController::class, 'update'])->whereUuid('product')->name('update');
                Route::delete('/{product}', [BranchProductController::class, 'destroy'])->whereUuid('product')->name('destroy');
            });
        });
        Route::get('option-groups', [OptionGroupController::class, 'index'])->middleware('permission:options.view')->name('option-groups.index');
        Route::post('option-groups', [OptionGroupController::class, 'store'])->middleware('permission:options.create')->name('option-groups.store');
        Route::get('option-groups/{optionGroup}', [OptionGroupController::class, 'show'])->middleware('permission:options.view')->name('option-groups.show');
        Route::match(['put', 'patch'], 'option-groups/{optionGroup}', [OptionGroupController::class, 'update'])->middleware('permission:options.update')->name('option-groups.update');
        Route::delete('option-groups/{optionGroup}', [OptionGroupController::class, 'destroy'])->middleware('permission:options.delete')->name('option-groups.destroy');
        Route::scopeBindings()->group(function (): void {
            Route::post('option-groups/{optionGroup}/values', [OptionValueController::class, 'store'])->middleware('permission:options.create')->name('option-values.store');
            Route::patch('option-groups/{optionGroup}/values/{optionValue}', [OptionValueController::class, 'update'])->middleware('permission:options.update')->name('option-values.update');
            Route::delete('option-groups/{optionGroup}/values/{optionValue}', [OptionValueController::class, 'destroy'])->middleware('permission:options.delete')->name('option-values.destroy');
            Route::get('products/{product}/option-groups', [ProductOptionGroupController::class, 'index'])->middleware('permission:products.view')->name('product-option-groups.index');
            Route::post('products/{product}/option-groups', [ProductOptionGroupController::class, 'store'])->middleware('permission:products.update')->name('product-option-groups.store');
            Route::patch('products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'update'])->middleware('permission:products.update')->name('product-option-groups.update');
            Route::delete('products/{product}/option-groups/{optionGroup}', [ProductOptionGroupController::class, 'destroy'])->middleware('permission:products.update')->name('product-option-groups.destroy');
        });
        Route::prefix('products/{product}/images')->name('products.images.')
            ->middleware('permission:products.update')->scopeBindings()->group(function (): void {
                Route::post('/', [ProductImageController::class, 'store'])->name('store');
                Route::patch('/reorder', [ProductImageController::class, 'reorder'])->name('reorder');
                Route::patch('/{image}/primary', [ProductImageController::class, 'setPrimary'])->whereUuid('image')->name('primary');
                Route::delete('/{image}', [ProductImageController::class, 'destroy'])->whereUuid('image')->name('destroy');
            });
        Route::get('/products', [AdminProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
        Route::post('/products', [AdminProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
        Route::get('/products/{product}', [AdminProductController::class, 'show'])->middleware('permission:products.view')->name('products.show');
        Route::match(['put', 'patch'], '/products/{product}', [AdminProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');
        Route::get('/categories', [AdminCategoryController::class, 'index'])->middleware('permission:categories.view')->name('categories.index');
        Route::post('/categories', [AdminCategoryController::class, 'store'])->middleware('permission:categories.create')->name('categories.store');
        Route::get('/categories/{category}', [AdminCategoryController::class, 'show'])->middleware('permission:categories.view')->name('categories.show');
        Route::match(['put', 'patch'], '/categories/{category}', [AdminCategoryController::class, 'update'])->middleware('permission:categories.update')->name('categories.update');
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->middleware('permission:categories.delete')->name('categories.destroy');
    });
    Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('register');
    Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/profile', [CustomerProfileController::class, 'show'])->name('profile');
        Route::patch('/profile', [CustomerProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/claim-orders', [CustomerProfileController::class, 'claimOrders'])->middleware('throttle:10,1')->name('profile.claim-orders');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});

Route::get('/user', ProfileController::class)->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public
    |--------------------------------------------------------------------------
    */

    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/settings/public', PublicConfigurationController::class)->name('settings.public');
    Route::get('/branches/{branch}/status', BranchStatusController::class)->whereUuid('branch')->name('branches.status');
    Route::get('/branches/{branch}/business-hours', BranchBusinessHoursController::class)->whereUuid('branch')->name('branches.hours');
    Route::get('/branches/{branch}', [BranchController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum', EnsureAdminAccess::class, RecordAdminActivity::class])
        ->prefix('admin')
        ->group(function () {

            Route::get('/branches', [AdminBranchController::class, 'index'])
                ->middleware('permission:branches.view');
            Route::post('/branches', [AdminBranchController::class, 'store'])
                ->middleware('permission:branches.create');
            Route::get('/branches/{branch}', [AdminBranchController::class, 'show'])
                ->middleware('permission:branches.view');
            Route::put('/branches/{branch}', [AdminBranchController::class, 'update'])
                ->middleware('permission:branches.update');
            Route::patch('/branches/{branch}', [AdminBranchController::class, 'update'])
                ->middleware('permission:branches.update');
            Route::delete('/branches/{branch}', [AdminBranchController::class, 'destroy'])
                ->middleware('permission:branches.delete');
        });
});
