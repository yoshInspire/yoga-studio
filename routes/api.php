<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\TrainerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API для мобильного приложения (Expo / React Native)
|--------------------------------------------------------------------------
| Авторизация — Bearer-токены Sanctum.
*/

Route::prefix('v1')->group(function () {
    // --- Публичные ---
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('api.login');

    Route::get('/directions', [ContentController::class, 'directions']);
    Route::get('/directions/{slug}', [ContentController::class, 'direction']);
    Route::get('/news', [ContentController::class, 'news']);
    Route::get('/news/{slug}', [ContentController::class, 'newsItem']);
    Route::get('/schedule', [ContentController::class, 'schedule']);
    Route::post('/lead', [LeadController::class, 'store'])->middleware('throttle:6,1');

    // --- Требуют токен ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

        // Клиент
        Route::middleware('role:client')->group(function () {
            Route::get('/account', [AccountController::class, 'show']);
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
            Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
        });

        // Тренер
        Route::middleware('role:trainer')->group(function () {
            Route::get('/trainer', [TrainerController::class, 'index']);
        });
    });
});
