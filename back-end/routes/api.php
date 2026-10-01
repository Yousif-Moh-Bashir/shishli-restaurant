<?php

use App\Http\Controllers\Api\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\V1\Admin\BranchProductController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\DeliveryZoneController;
use App\Http\Controllers\Api\V1\Admin\OptionGroupController;
use App\Http\Controllers\Api\V1\Admin\OptionValueController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\OrderHistoryController;
use App\Http\Controllers\Api\V1\Admin\OrderOperationController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\ProductImageController;
use App\Http\Controllers\Api\V1\Admin\ProductOptionGroupController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CartDeliveryQuoteController;
use App\Http\Controllers\Api\V1\CartItemController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CustomerAddressController;
use App\Http\Controllers\Api\V1\DeliveryQuoteController;
use App\Http\Controllers\Api\V1\GuestOrderController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KitchenOrderController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderCancellationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Middleware\OptionalCartAuthentication;
use App\Models\CustomerAddress;
use Illuminate\Support\Facades\Route;

Route::bind('address', function (string $uuid): CustomerAddress {
    abort_unless(request()->user() !== null, 401);

    return request()->user()->addresses()->where('uuid', $uuid)->firstOrFail();
});

Route::prefix('v1')->name('api.v1.')->group(function (): void {
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
    Route::middleware('auth:sanctum')->group(function (): void {
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

    Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function (): void {
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
        Route::get('/profile', ProfileController::class)->name('profile');
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
    Route::get('/branches/{branch}', [BranchController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */

    Route::middleware('auth:sanctum')
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
