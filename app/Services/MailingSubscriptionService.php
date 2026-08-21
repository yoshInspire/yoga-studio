<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Подписка клиента на информационные рассылки студии.
 *
 * Ссылка «отписаться» в письме — подписанная и бессрочная. Бессрочная потому,
 * что письмо могут открыть через полгода, и ссылка, которая к тому моменту
 * протухла, — это не отписка, а жалоба на спам. Подпись (`APP_KEY`) не даёт
 * отписать чужого человека, подставив другой id в адрес.
 *
 * Отписка не требует входа в кабинет намеренно: у половины клиентов почта
 * открыта на телефоне, а пароль от кабинета не вспомнить, и любое лишнее
 * препятствие на этом пути оборачивается кнопкой «Спам» — она стоит студии
 * куда дороже, чем один потерянный подписчик.
 */
class MailingSubscriptionService
{
    /**
     * Адрес отписки. Одна и та же ссылка живёт в подвале письма (GET —
     * страница с подтверждением) и в заголовке `List-Unsubscribe` (POST —
     * отписка в один клик кнопкой самой почты).
     */
    public function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('mailings.unsubscribe', ['user' => $user->getKey()]);
    }

    public function resubscribeUrl(User $user): string
    {
        return URL::signedRoute('mailings.subscribe', ['user' => $user->getKey()]);
    }

    public function unsubscribe(User $user): void
    {
        if ($user->isSubscribedToMailings()) {
            $user->forceFill(['mailings_subscribed' => false])->save();
        }
    }

    public function resubscribe(User $user): void
    {
        if (! $user->isSubscribedToMailings()) {
            $user->forceFill(['mailings_subscribed' => true])->save();
        }
    }
}
