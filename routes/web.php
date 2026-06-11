<?php

use App\Http\Controllers\Account\OfferAcceptanceController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\TelegramLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DirectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TrainerController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/schedule', ScheduleController::class)->name('schedule');
Route::get('/directions', DirectionController::class)->name('directions');

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{news}', [NewsController::class, 'show'])->name('news.show');

Route::post('/lead', [LeadController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('lead.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register');
    Route::post('/register/verify', [RegisterController::class, 'verify'])
        ->middleware('throttle:12,1')
        ->name('register.verify');
    Route::post('/register/resend', [RegisterController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('register.resend');
    Route::post('/register/cancel', [RegisterController::class, 'cancelVerification'])
        ->name('register.cancel');
    Route::get('/auth/telegram/callback', [TelegramAuthController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('auth.telegram.callback');
    Route::post('/password/forgot', [PasswordResetController::class, 'request'])
        ->middleware('throttle:3,1')
        ->name('password.forgot');
    Route::post('/password/reset', [PasswordResetController::class, 'verify'])
        ->middleware('throttle:12,1')
        ->name('password.reset');
    Route::post('/password/resend', [PasswordResetController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('password.resend');
    Route::post('/password/cancel', [PasswordResetController::class, 'cancel'])
        ->name('password.cancel');
});

Route::post('/logout', LogoutController::class)->name('logout')->middleware('auth');

Route::get('/oferta', [OfferController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('offer.show');

Route::get('/payments/{payment}/return', [PaymentController::class, 'return'])
    ->middleware('signed')
    ->name('payments.return');

Route::middleware(['auth', 'role:client'])->group(function () {
    Route::get('/account', AccountController::class)->name('account');
    Route::post('/account/offer/accept', [OfferAcceptanceController::class, 'store'])->name('account.offer.accept');
    Route::put('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');
    Route::post('/account/profile/email/send-code', [ProfileController::class, 'sendEmailCode'])
        ->middleware('throttle:3,1')
        ->name('account.profile.email.send');
    Route::post('/account/profile/email/verify', [ProfileController::class, 'verifyEmailCode'])
        ->middleware('throttle:12,1')
        ->name('account.profile.email.verify');
    Route::get('/account/telegram/callback', [TelegramLinkController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('account.telegram.callback');
    Route::delete('/account/telegram', [TelegramLinkController::class, 'destroy'])
        ->name('account.telegram.unlink');
    Route::middleware('offer.accepted')->group(function () {
        Route::get('/purchase', [PurchaseController::class, 'index'])->name('purchase.index');
        Route::post('/purchase', [PurchaseController::class, 'store'])->name('purchase.store');
        Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule'])->name('bookings.reschedule');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    });
});

Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payments.webhook');

Route::middleware(['auth', 'role:trainer'])->group(function () {
    Route::get('/trainer', TrainerController::class)->name('trainer');
});
