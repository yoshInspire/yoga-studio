<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use YooKassa\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function () {
            $client = new Client;
            $client->setAuth(
                (int) config('yookassa.shop_id'),
                (string) config('yookassa.secret_key'),
            );

            return $client;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('ru');
    }
}
