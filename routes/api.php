<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\TrainerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API для мобильного приложения (Expo / React Native)
|--------------------------------------------------------------------------
| Авторизация — Bearer-токены Sanctum.
*/

Route::prefix('v1')->group(function () {
    // --- Публичные: авторизация ---
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('api.login');
    Route::post('/register', [RegisterController::class, 'start'])->middleware('throttle:6,1');
    Route::post('/register/verify', [RegisterController::class, 'verify'])->middleware('throttle:12,1');
    Route::post('/register/resend', [RegisterController::class, 'resend'])->middleware('throttle:3,1');
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:3,1');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:12,1');

    // --- Публичные: контент ---
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

            Route::put('/account/profile', [ProfileController::class, 'update']);
            Route::put('/account/password', [ProfileController::class, 'changePassword']);
            Route::post('/account/email/request-code', [ProfileController::class, 'requestEmailCode'])->middleware('throttle:3,1');
            Route::post('/account/email/confirm', [ProfileController::class, 'confirmEmail'])->middleware('throttle:12,1');
            Route::get('/account/offer', [ProfileController::class, 'offer']);
            Route::post('/account/offer/accept', [ProfileController::class, 'acceptOffer']);

            Route::get('/purchase', [PurchaseController::class, 'index']);
            Route::post('/purchase', [PurchaseController::class, 'store']);
        });

        // Тренер
        Route::middleware('role:trainer')->group(function () {
            Route::get('/trainer', [TrainerController::class, 'index']);
        });

        // Администратор
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/meta', [AdminController::class, 'meta']);
            Route::get('/sessions', [AdminController::class, 'sessions']);
            Route::post('/sessions', [AdminController::class, 'createSession']);
            Route::post('/sessions/{session}/cancel', [AdminController::class, 'cancelSession']);
            Route::get('/clients', [AdminController::class, 'clients']);
            Route::post('/clients', [AdminController::class, 'createClient']);
            Route::post('/subscriptions', [AdminController::class, 'issueSubscription']);
            Route::get('/payments', [AdminController::class, 'payments']);
        });
    });
});
