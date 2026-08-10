<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountDeletionController;
use App\Http\Controllers\Api\Admin\AsanaController as AdminAsanaController;
use App\Http\Controllers\Api\Admin\AsanaLibraryController as AdminAsanaLibraryController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Api\Admin\DirectionController as AdminDirectionController;
use App\Http\Controllers\Api\Admin\MailingController as AdminMailingController;
use App\Http\Controllers\Api\Admin\NewsController as AdminNewsController;
use App\Http\Controllers\Api\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PricingController as AdminPricingController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\SessionController as AdminSessionController;
use App\Http\Controllers\Api\Admin\StaffController as AdminStaffController;
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
            Route::get('/bookings', [AdminBookingController::class, 'index']);
            Route::post('/bookings', [AdminBookingController::class, 'store']);
            Route::get('/bookings/options', [AdminBookingController::class, 'options']);
            // Перенос записи на другое занятие: клиент звонит и просит
            // переставить. Сроки отмены администратору не мешают.
            Route::get('/bookings/{booking}/reschedule', [AdminBookingController::class, 'rescheduleOptions']);
            Route::post('/bookings/{booking}/reschedule', [AdminBookingController::class, 'reschedule']);
            Route::post('/bookings/{booking}/attended', [AdminVisitController::class, 'attended']);
            Route::post('/bookings/{booking}/no-show', [AdminVisitController::class, 'noShow']);
            Route::post('/bookings/{booking}/cancel', [AdminVisitController::class, 'cancel']);

            Route::get('/clients', [AdminClientController::class, 'index']);
            Route::post('/clients', [AdminClientController::class, 'store']);
            Route::get('/clients/{client}', [AdminClientController::class, 'show']);
            Route::put('/clients/{client}', [AdminClientController::class, 'update']);
            Route::post('/clients/{client}/access', [AdminClientController::class, 'access'])
                ->middleware('throttle:10,1');

            Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
            Route::post('/subscriptions', [AdminSubscriptionController::class, 'store']);
            Route::put('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'update']);
            Route::post('/subscriptions/{subscription}/extend', [AdminSubscriptionController::class, 'extend']);
            Route::post('/subscriptions/{subscription}/sessions', [AdminSubscriptionController::class, 'addSessions']);
            Route::get('/payments', [AdminPaymentController::class, 'index']);

            // Новости. Публикация рассылает письма клиентам (NewsObserver),
            // поэтому ответы несут will_notify и число получателей.
            Route::get('/news', [AdminNewsController::class, 'index']);
            Route::post('/news', [AdminNewsController::class, 'store']);
            Route::get('/news/{news}', [AdminNewsController::class, 'show']);
            Route::put('/news/{news}', [AdminNewsController::class, 'update']);
            Route::delete('/news/{news}', [AdminNewsController::class, 'destroy']);
            Route::post('/news/{news}/image', [AdminNewsController::class, 'storeImage'])
                ->middleware('throttle:30,1');
            Route::delete('/news/{news}/image', [AdminNewsController::class, 'destroyImage']);

            // Цены. Правка базового тарифа меняет и витрину, и сумму
            // онлайн-оплаты, поэтому ответ перечисляет «было → стало».
            Route::get('/pricing', [AdminPricingController::class, 'index']);
            Route::put('/pricing', [AdminPricingController::class, 'updateBase']);
            Route::post('/pricing/items', [AdminPricingController::class, 'storeItem']);
            Route::put('/pricing/items/{item}', [AdminPricingController::class, 'updateItem']);
            Route::delete('/pricing/items/{item}', [AdminPricingController::class, 'destroyItem']);
            Route::post('/pricing/items/{item}/move', [AdminPricingController::class, 'moveItem']);

            // Рассылки клиентам. Каждое отправление — письма и Telegram живым
            // людям, отозвать нельзя. Dry-run из веб-админки намеренно не
            // переносим: число получателей приложение знает заранее.
            Route::get('/mailings', [AdminMailingController::class, 'index']);
            Route::put('/mailings/welcome', [AdminMailingController::class, 'updateWelcome']);
            Route::put('/mailings/birthday', [AdminMailingController::class, 'updateBirthday']);
            Route::post('/mailings/weekly', [AdminMailingController::class, 'sendWeekly'])
                ->middleware('throttle:10,1');
            Route::post('/mailings/custom', [AdminMailingController::class, 'sendCustom'])
                ->middleware('throttle:10,1');

            // Направления. Файлы лежат на диске public_web (рядом с сайтом),
            // а не в storage, как новости. Привязка по id, а не по slug:
            // getRouteKeyName у модели — slug, приложению нужен id.
            Route::get('/directions', [AdminDirectionController::class, 'index']);
            Route::post('/directions', [AdminDirectionController::class, 'store']);
            Route::get('/directions/{direction:id}', [AdminDirectionController::class, 'show']);
            Route::put('/directions/{direction:id}', [AdminDirectionController::class, 'update']);
            Route::delete('/directions/{direction:id}', [AdminDirectionController::class, 'destroy']);
            Route::post('/directions/{direction:id}/move', [AdminDirectionController::class, 'move']);
            Route::post('/directions/{direction:id}/cover', [AdminDirectionController::class, 'storeCover'])
                ->middleware('throttle:30,1');
            Route::post('/directions/{direction:id}/slides', [AdminDirectionController::class, 'storeSlide'])
                ->middleware('throttle:30,1');
            Route::delete('/directions/{direction:id}/slides/{index}', [AdminDirectionController::class, 'destroySlide'])
                ->whereNumber('index');
            Route::post('/directions/{direction:id}/slides/{index}/move', [AdminDirectionController::class, 'moveSlide'])
                ->whereNumber('index');

            // Оферта. Загрузка PDF пересобирает текст страницы /oferta из
            // самого файла — двух редакций одного договора быть не должно.
            Route::get('/offer', [AdminOfferController::class, 'show']);
            Route::post('/offer', [AdminOfferController::class, 'store'])
                ->middleware('throttle:10,1');
            Route::delete('/offer', [AdminOfferController::class, 'destroy']);

            // Отчёты Excel. Файл идёт прямо в ответе под Bearer-токеном:
            // expo-file-system умеет слать заголовки, а подписанная ссылка
            // означала бы общедоступный адрес с телефонами клиентов.
            Route::get('/reports', [AdminReportController::class, 'index']);
            Route::get('/reports/{key}', [AdminReportController::class, 'download'])
                ->middleware('throttle:30,1');

            // Сотрудники: тренеры и администраторы. Пароль в приложении не
            // вводится — доступ выдаётся кнопкой (тренеру письмом, админу
            // на экран). Удаления сотрудника нет, есть снятие с витрины.
            Route::get('/staff', [AdminStaffController::class, 'index']);
            Route::post('/staff', [AdminStaffController::class, 'store']);
            Route::get('/staff/{staff}', [AdminStaffController::class, 'show']);
            Route::put('/staff/{staff}', [AdminStaffController::class, 'update']);
            Route::post('/staff/{staff}/role', [AdminStaffController::class, 'role']);
            Route::post('/staff/{staff}/access', [AdminStaffController::class, 'access'])
                ->middleware('throttle:10,1');
            Route::post('/staff/{staff}/photo', [AdminStaffController::class, 'storePhoto'])
                ->middleware('throttle:30,1');
            Route::delete('/staff/{staff}/photo', [AdminStaffController::class, 'destroyPhoto']);

            // Асаны и программы. Обёртки над AsanaProgramService: перестановка
            // поз, зарисовки и копирование занятия там уже написаны. Рисунок
            // приходит строкой data-URL — ровно тем, что отдаёт холст.
            Route::get('/asanas', [AdminAsanaController::class, 'index']);
            Route::post('/asanas/folders', [AdminAsanaController::class, 'storeFolder']);
            Route::put('/asanas/folders/{folder}', [AdminAsanaController::class, 'updateFolder']);
            Route::delete('/asanas/folders/{folder}', [AdminAsanaController::class, 'destroyFolder']);

            Route::post('/asanas/programs', [AdminAsanaController::class, 'storeProgram']);
            Route::get('/asanas/programs/{program}', [AdminAsanaController::class, 'showProgram']);
            Route::put('/asanas/programs/{program}', [AdminAsanaController::class, 'updateProgram']);
            Route::delete('/asanas/programs/{program}', [AdminAsanaController::class, 'destroyProgram']);
            Route::post('/asanas/programs/{program}/duplicate', [AdminAsanaController::class, 'duplicateProgram']);
            // Лист для печати собирает сервер: раскладка одна с веб-админкой.
            Route::get('/asanas/programs/{program}/print', [AdminAsanaController::class, 'printProgram']);

            Route::post('/asanas/programs/{program}/items', [AdminAsanaController::class, 'storeItem']);
            Route::put('/asanas/items/{item}', [AdminAsanaController::class, 'updateItem']);
            Route::delete('/asanas/items/{item}', [AdminAsanaController::class, 'destroyItem']);
            Route::post('/asanas/items/{item}/move', [AdminAsanaController::class, 'moveItem']);
            Route::post('/asanas/items/{item}/drawing', [AdminAsanaController::class, 'storeItemDrawing'])
                ->middleware('throttle:60,1');
            Route::delete('/asanas/items/{item}/drawing', [AdminAsanaController::class, 'destroyItemDrawing']);

            Route::get('/asanas/library', [AdminAsanaLibraryController::class, 'index']);
            Route::post('/asanas/library', [AdminAsanaLibraryController::class, 'store'])
                ->middleware('throttle:60,1');
            Route::put('/asanas/library/{asana}', [AdminAsanaLibraryController::class, 'update']);
            Route::delete('/asanas/library/{asana}', [AdminAsanaLibraryController::class, 'destroy']);
            Route::post('/asanas/categories', [AdminAsanaLibraryController::class, 'storeCategory']);
            Route::put('/asanas/categories/{category}', [AdminAsanaLibraryController::class, 'updateCategory']);
            Route::delete('/asanas/categories/{category}', [AdminAsanaLibraryController::class, 'destroyCategory']);

            Route::get('/chats', [AdminChatController::class, 'index']);
            Route::get('/chats/{client}', [AdminChatController::class, 'show']);
            Route::post('/chats/{client}', [AdminChatController::class, 'store'])->middleware('throttle:60,1');
            Route::post('/chats/{client}/read', [AdminChatController::class, 'read']);
        });
    });
});
