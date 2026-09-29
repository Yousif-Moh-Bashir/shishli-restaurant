<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');

    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'can:categories.manage'])->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'adminIndex'])->name('categories.index');
        Route::apiResource('categories', CategoryController::class)->except(['index']);
    });
    Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('register');
    Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/profile', ProfileController::class)->name('profile');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});

Route::get('/user', ProfileController::class)->middleware('auth:sanctum');

use App\Http\Controllers\Api\V1\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\V1\BranchController;

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
