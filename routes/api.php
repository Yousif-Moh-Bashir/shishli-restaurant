<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');
    Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('register');
    Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/profile', ProfileController::class)->name('profile');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});

Route::get('/user', ProfileController::class)->middleware('auth:sanctum');
