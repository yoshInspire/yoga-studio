<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureOfferAccepted;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreventSearchIndexing;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'offer.accepted' => EnsureOfferAccepted::class,
            'noindex' => PreventSearchIndexing::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'payments/webhook',
            // Отписка в один клик из почтового клиента (RFC 8058): POST шлёт
            // сам Яндекс или Gmail, токена нашей формы у него нет. Подделать
            // запрос нельзя — адрес подписан, см. routes/web.php.
            'mailings/unsubscribe/*',
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            return match ($user?->role) {
                UserRole::Admin => '/admin',
                UserRole::Trainer => route('trainer'),
                default => route('account'),
            };
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
