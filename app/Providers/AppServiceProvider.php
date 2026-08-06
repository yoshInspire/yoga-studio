<?php

namespace App\Providers;

use App\Models\News;
use App\Observers\NewsObserver;
use App\Support\Push\ExpoPushSender;
use App\Support\Push\NullPushSender;
use App\Support\Push\PushSender;
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
        // Отправщик пушей выбирается конфигом: сейчас Expo, после переезда на
        // Flutter здесь появится FcmPushSender, и это будет вся правка.
        $this->app->singleton(PushSender::class, fn () => match ((string) config('services.push.driver')) {
            'expo' => new ExpoPushSender,
            default => new NullPushSender,
        });

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

        News::observe(NewsObserver::class);
    }
}
