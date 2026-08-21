<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailingSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Подписка на рассылки в личном кабинете.
 *
 * Дублирует ссылку из письма намеренно: письмо со ссылкой на отписку у
 * человека может не сохраниться, а кабинет — то место, куда он придёт искать
 * настройку. Обратный путь тоже нужен: вернуть подписку из письма нельзя,
 * писем-то больше нет.
 */
class MailingPreferenceController extends Controller
{
    public function __construct(private MailingSubscriptionService $subscription) {}

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'subscribed' => ['required', 'boolean'],
        ]);

        if ($request->boolean('subscribed')) {
            $this->subscription->resubscribe($user);
            $status = 'Вы снова будете получать рассылки студии.';
        } else {
            $this->subscription->unsubscribe($user);
            $status = 'Вы отписались от рассылок. Письма о ваших записях и абонементе продолжат приходить.';
        }

        return redirect()
            ->route('account')
            ->with('status', $status)
            ->with('lk_section', 'profile');
    }
}
