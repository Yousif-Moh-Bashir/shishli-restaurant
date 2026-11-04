<?php

use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Finance\LoginController;
use App\Http\Controllers\Api\V1\Finance\OutflowController;
use App\Http\Controllers\Api\V1\Finance\ReportController;
use App\Http\Controllers\Api\V1\Finance\RevenueController;
use App\Http\Controllers\Api\V1\Finance\SettingsController;
use App\Http\Controllers\Api\V1\Finance\UserController;
use App\Http\Middleware\EnsureFinanceToken;
use Illuminate\Support\Facades\Route;

Route::post('v1/finance/login', LoginController::class)->middleware('throttle:login')->name('finance.login');

Route::prefix('v1/finance')->name('finance.')->middleware(['auth:sanctum', 'permission:finance.access', EnsureFinanceToken::class, 'throttle:60,1'])->group(function (): void {
    Route::post('logout', LogoutController::class)->name('logout');
    Route::get('me', [SettingsController::class, 'bootstrap'])->name('me');
    Route::get('revenues', [RevenueController::class, 'index'])->middleware('permission:finance.revenues.view')->name('revenues.index');
    Route::post('revenues', [RevenueController::class, 'store'])->middleware('permission:finance.revenues.create')->name('revenues.store');
    Route::get('revenues/{uuid}', [RevenueController::class, 'show'])->whereUuid('uuid')->middleware('permission:finance.revenues.view')->name('revenues.show');
    Route::put('revenues/{uuid}', [RevenueController::class, 'update'])->whereUuid('uuid')->middleware('permission:finance.revenues.edit')->name('revenues.update');
    Route::post('revenues/{uuid}/approve', [RevenueController::class, 'approve'])->whereUuid('uuid')->middleware('permission:finance.revenues.approve')->name('revenues.approve');
    Route::post('revenues/{uuid}/correct', [RevenueController::class, 'correct'])->whereUuid('uuid')->middleware('permission:finance.revenues.correct')->name('revenues.correct');
    foreach (['expenses', 'purchases'] as $kind) {
        Route::get($kind, [OutflowController::class, 'index'])->defaults('kind', $kind)->name($kind.'.index');
        Route::post($kind, [OutflowController::class, 'store'])->defaults('kind', $kind)->name($kind.'.store');
        Route::post($kind.'/{uuid}', [OutflowController::class, 'update'])->defaults('kind', $kind)->whereUuid('uuid')->name($kind.'.update');
        Route::get($kind.'/{uuid}/attachment', [OutflowController::class, 'attachment'])->defaults('kind', $kind)->whereUuid('uuid')->name($kind.'.attachment');
    }
    foreach (['sources', 'categories', 'suppliers'] as $kind) {
        Route::get('settings/'.$kind, [SettingsController::class, 'index'])->defaults('kind', $kind)->name($kind.'.index');
        Route::post('settings/'.$kind, [SettingsController::class, 'save'])->defaults('kind', $kind)->name($kind.'.store');
        Route::put('settings/'.$kind.'/{uuid}', [SettingsController::class, 'save'])->defaults('kind', $kind)->whereUuid('uuid')->name($kind.'.update');
    }
    Route::get('reports', [ReportController::class, 'index'])->middleware('permission:finance.reports.view')->name('reports.index');
    foreach (['xlsx', 'pdf'] as $format) {
        Route::get('reports/export/'.$format, [ReportController::class, 'export'])->defaults('format', $format)->middleware(['permission:finance.reports.view', 'permission:finance.reports.export'])->name('reports.export.'.$format);
    }
    Route::get('audit', [SettingsController::class, 'audit'])->middleware('permission:finance.audit.view')->name('audit');
    Route::get('users', [UserController::class, 'index'])->middleware('permission:finance.users.manage')->name('users.index');
    Route::post('users', [UserController::class, 'save'])->middleware('permission:finance.users.manage')->name('users.store');
    Route::put('users/{uuid}', [UserController::class, 'save'])->whereUuid('uuid')->middleware('permission:finance.users.manage')->name('users.update');
});
