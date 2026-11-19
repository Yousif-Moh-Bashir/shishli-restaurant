<?php

use App\Http\Controllers\Api\V1\Admin\OrderPrintingController;
use App\Http\Controllers\Api\V1\PrintAgentController;
use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\EnsureAdminAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/print-agent')->name('api.v1.print-agent.')->middleware([AuthenticatePrintAgent::class, 'throttle:120,1'])->group(function (): void {
    Route::post('connectivity', [PrintAgentController::class, 'connectivity'])->name('connectivity');
    Route::post('claim', [PrintAgentController::class, 'claim'])->name('claim');
    Route::post('jobs/{job}/start', [PrintAgentController::class, 'start'])->whereUuid('job')->name('start');
    Route::post('jobs/{job}/result', [PrintAgentController::class, 'result'])->whereUuid('job')->name('result');
});
Route::prefix('v1/admin')->name('api.v1.admin.printing.')->middleware(['auth:sanctum', EnsureAdminAccess::class])->group(function (): void {
    Route::get('orders/{order}/print-jobs', [OrderPrintingController::class, 'index'])->whereUuid('order')
        ->middleware(['permission:orders.view', 'permission:printing.view'])->name('index');
    Route::post('orders/{order}/print-jobs/{job}/reprint', [OrderPrintingController::class, 'reprint'])->whereUuid(['order', 'job'])
        ->middleware(['permission:orders.view', 'permission:printing.reprint', 'throttle:20,1'])->name('reprint');
    Route::middleware('permission:printing.manage_agents')->group(function (): void {
        Route::get('print-agents', [OrderPrintingController::class, 'agents'])->name('agents');
        Route::post('print-agents', [OrderPrintingController::class, 'register'])->middleware('throttle:5,1')->name('register');
        Route::patch('print-agents/{printAgent}', [OrderPrintingController::class, 'update'])->whereUuid('printAgent')->name('update');
    });
});
