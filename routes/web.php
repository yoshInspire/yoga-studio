<?php

use App\Http\Controllers\Account\AccountDeletionController;
use App\Http\Controllers\Account\AvatarController;
use App\Http\Controllers\Account\MailingPreferenceController;
use App\Http\Controllers\Account\OfferAcceptanceController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\TelegramLinkController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DirectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MailingSubscriptionController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsReactionController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TrainerController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/schedule', ScheduleController::class)->name('schedule');
Route::get('/directions', [DirectionController::class, 'index'])->name('directions');
Route::get('/directions/{direction:slug}', [DirectionController::class, 'show'])->name('directions.show');

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/news/{news}', [NewsController::class, 'show'])->name('news.show');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::post('/news/{news}/reactions', [NewsReactionController::class, 'store'])
    ->middleware(['auth', 'role:client,trainer', 'throttle:60,1'])
    ->name('news.reactions.store');

Route::post('/lead', [LeadController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('lead.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->middleware('noindex')->name('login');
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

/*
 * Правовые документы. Открыты всем: их проверяют роботы магазинов приложений,
 * а мобильное приложение показывает документы ещё до входа.
 *
 * По /oferta теперь отдаётся текстовая версия договора: PDF в браузере
 * Android не показывается, а скачивается файлом. PDF-оригинал, который
 * администратор загружает через админку, переехал на /oferta.pdf.
 */
Route::get('/oferta', [LegalController::class, 'offer'])->name('legal.offer');
Route::get('/oferta.pdf', [OfferController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('legal.offer-pdf');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/account/delete-request', [LegalController::class, 'accountDelete'])->name('legal.account-delete');

/*
 * Отписка от рассылок по ссылке из письма. Без входа в кабинет — подписи в
 * ссылке достаточно, а требовать пароль у человека, который просто хочет,
 * чтобы письма перестали приходить, значит подталкивать его к кнопке «Спам».
 *
 * GET показывает страницу с кнопкой, POST по тому же адресу отписывает.
 * Раздельно потому, что ссылки в письмах открывают роботы-предпросмотрщики:
 * отписка прямо на GET срабатывала бы без участия человека. Кнопка отписки
 * в самом почтовом ящике (Яндекс, Mail.ru, Gmail) шлёт сразу POST — для неё
 * это по-прежнему один клик.
 */
Route::middleware(['signed', 'noindex'])->group(function () {
    Route::get('/mailings/unsubscribe/{user}', [MailingSubscriptionController::class, 'confirm'])
        ->name('mailings.unsubscribe');
    Route::post('/mailings/unsubscribe/{user}', [MailingSubscriptionController::class, 'unsubscribe'])
        ->name('mailings.unsubscribe.confirm');
    Route::get('/mailings/subscribe/{user}', [MailingSubscriptionController::class, 'resubscribe'])
        ->name('mailings.subscribe');
});

Route::get('/payments/{payment}/return', [PaymentController::class, 'return'])
    ->middleware(['signed', 'noindex'])
    ->name('payments.return');

// Фотографии из чата для веб-админки. Отдельно от api-маршрута: там сессия
// не работает, а здесь администратор авторизован обычным входом на сайт.
// Путь намеренно не под /admin — там свои маршруты у Filament.
Route::get('/chat-attachments/{message}', [ChatController::class, 'attachment'])
    ->middleware(['auth', 'role:admin', 'noindex'])
    ->name('chat.attachment');

// Фотография профиля: своя есть и у клиента, и у тренера, поэтому маршрут
// общий для всех вошедших, а возврат идёт на ту страницу, откуда пришли.
Route::middleware(['auth', 'noindex'])->group(function () {
    Route::post('/account/avatar', [AvatarController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('account.avatar.store');
    Route::delete('/account/avatar', [AvatarController::class, 'destroy'])
        ->name('account.avatar.destroy');
});

Route::middleware(['auth', 'role:client', 'noindex'])->group(function () {
    Route::get('/account', AccountController::class)->name('account');
    Route::post('/account/offer/accept', [OfferAcceptanceController::class, 'store'])->name('account.offer.accept');
    Route::put('/account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');
    Route::put('/account/mailings', [MailingPreferenceController::class, 'update'])->name('account.mailings.update');
    Route::delete('/account', [AccountDeletionController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('account.destroy');
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

Route::middleware(['auth', 'role:trainer', 'noindex'])->group(function () {
    Route::get('/trainer', TrainerController::class)->name('trainer');
});
