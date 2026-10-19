<?php

namespace App\Exceptions;

use App\Models\Subscription;
use InvalidArgumentException;

/**
 * Своего подходящего абонемента нет, но семья открыла свои.
 *
 * Сам по себе общий абонемент не выбирается: человек должен согласиться,
 * что занятие спишется с чужого. Поэтому запись не создаётся, а наверх
 * уходят варианты — сайт показывает их кнопками, приложение шторкой.
 * Наследник InvalidArgumentException: старые обработчики покажут хотя бы
 * понятный текст.
 */
class SharedSubscriptionRequired extends InvalidArgumentException
{
    /**
     * @param  list<Subscription>  $subscriptions
     */
    public function __construct(public readonly array $subscriptions)
    {
        parent::__construct(
            'Своего абонемента для этого занятия нет, но можно записаться по абонементу семьи — выберите его.',
        );
    }
}
