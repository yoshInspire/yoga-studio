<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MailingSubscriptionService;
use Illuminate\Contracts\View\View;

/**
 * Отписка от рассылок по ссылке из письма — без входа в кабинет.
 *
 * Все три маршрута закрыты подписью (`signed`), поэтому чужой адрес отписать,
 * подставив другой id, нельзя. Логин здесь намеренно не требуется: см.
 * `MailingSubscriptionService`.
 *
 * Почему отписка по ссылке из подвала — это два шага, GET со страницей и
 * POST с кнопкой: письма проходят через антивирусы и предпросмотрщики ссылок,
 * которые дёргают все GET-адреса подряд. Отписывай мы прямо на GET —
 * половина клиентов отписалась бы, ничего не нажимая. Кнопка отписки самой
 * почты (Яндекс, Mail.ru, Gmail) ходит сразу POST-запросом и страницу не
 * показывает — там всё в один клик, как и задумано RFC 8058.
 */
class MailingSubscriptionController extends Controller
{
    public function __construct(private MailingSubscriptionService $subscription) {}

    /** Страница по ссылке «Отписаться» из подвала письма. */
    public function confirm(User $user): View
    {
        return $this->page($user, $user->isSubscribedToMailings() ? 'confirm' : 'already');
    }

    /** Кнопка на странице и одноклик из почтового клиента. */
    public function unsubscribe(User $user): View
    {
        $this->subscription->unsubscribe($user);

        return $this->page($user, 'done');
    }

    /** Вернуть подписку — ссылка со страницы отписки. */
    public function resubscribe(User $user): View
    {
        $this->subscription->resubscribe($user);

        return $this->page($user, 'resubscribed');
    }

    private function page(User $user, string $state): View
    {
        return view('pages.mailings.unsubscribe', [
            'state' => $state,
            'unsubscribeUrl' => $this->subscription->unsubscribeUrl($user),
            'resubscribeUrl' => $this->subscription->resubscribeUrl($user),
        ]);
    }
}
