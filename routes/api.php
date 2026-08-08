<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountDeletionController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\VisitController as AdminVisitController;
use App\Http\Controllers\Api\AdminChatController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\NotificationController;
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
    Route::get('/pricing', [ContentController::class, 'pricing']);
    Route::get('/rules', [ContentController::class, 'rules']);
    Route::get('/legal', [ContentController::class, 'legal']);
    Route::post('/lead', [LeadController::class, 'store'])->middleware('throttle:6,1');

    // --- Требуют токен ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

        // Уведомления и пуши: не привязаны к роли — сейчас их получают клиенты,
        // тренерские лягут сюда же без правки маршрутов.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread', [NotificationController::class, 'unread']);
        Route::post('/notifications/read', [NotificationController::class, 'read']);
        Route::post('/push-tokens', [NotificationController::class, 'storeToken']);
        Route::delete('/push-tokens', [NotificationController::class, 'destroyToken']);

        // Фотография профиля: своя есть у любой роли.
        Route::post('/account/avatar', [AvatarController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('/account/avatar', [AvatarController::class, 'destroy']);

        // Свои данные, пароль и email — тоже у любой роли: в приложении
        // администратор и тренер правят профиль там же, где клиент.
        Route::put('/account/profile', [ProfileController::class, 'update']);
        Route::put('/account/password', [ProfileController::class, 'changePassword']);
        Route::post('/account/email/request-code', [ProfileController::class, 'requestEmailCode'])->middleware('throttle:3,1');
        Route::post('/account/email/confirm', [ProfileController::class, 'confirmEmail'])->middleware('throttle:12,1');

        // Чат: общее для обеих ролей
        Route::get('/chat/unread', [ChatController::class, 'unread']);
        Route::get('/chat/attachments/{message}', [ChatController::class, 'attachment'])
            ->name('api.chat.attachment');

        // Клиент
        Route::middleware('role:client')->group(function () {
            Route::get('/chat', [ChatController::class, 'index']);
            Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:30,1');
            Route::post('/chat/read', [ChatController::class, 'read']);

            Route::get('/account', [AccountController::class, 'show']);
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
            Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);

            // Оферта — только клиентская история: подписывает её клиент.
            Route::get('/account/offer', [ProfileController::class, 'offer']);
            // Удаление аккаунта — требование магазинов приложений
            // (App Store 5.1.1(v), Google Play Data Safety).
            Route::delete('/account', [AccountDeletionController::class, 'destroy'])
                ->middleware('throttle:6,1');
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
            Route::get('/overview', [AdminOverviewController::class, 'overview']);
            Route::get('/meta', [AdminOverviewController::class, 'meta']);
            Route::get('/sessions', [AdminSessionController::class, 'index']);
            Route::post('/sessions', [AdminSessionController::class, 'store']);
            Route::put('/sessions/{session}', [AdminSessionController::class, 'update']);
            Route::delete('/sessions/{session}', [AdminSessionController::class, 'destroy']);
            Route::post('/sessions/{session}/cancel', [AdminSessionController::class, 'cancel']);
            // Контроль посещений: день, отметки и запись клиента администратором.
            Route::get('/visits', [AdminVisitController::class, 'day']);
            Route::post('/bookings', [AdminBookingController::class, 'store']);
            Route::get('/bookings/options', [AdminBookingController::class, 'options']);
            Route::post('/bookings/{booking}/attended', [AdminVisitController::class, 'attended']);
            Route::post('/bookings/{booking}/no-show', [AdminVisitController::class, 'noShow']);
            Route::post('/bookings/{booking}/cancel', [AdminVisitController::class, 'cancel']);

            Route::get('/clients', [AdminClientController::class, 'index']);
            Route::post('/clients', [AdminClientController::class, 'store']);
            Route::post('/subscriptions', [AdminSubscriptionController::class, 'store']);
            Route::get('/payments', [AdminPaymentController::class, 'index']);

            Route::get('/chats', [AdminChatController::class, 'index']);
            Route::get('/chats/{client}', [AdminChatController::class, 'show']);
            Route::post('/chats/{client}', [AdminChatController::class, 'store'])->middleware('throttle:60,1');
            Route::post('/chats/{client}/read', [AdminChatController::class, 'read']);
        });
    });
});
