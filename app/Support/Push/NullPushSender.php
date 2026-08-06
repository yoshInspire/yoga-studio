<?php

namespace App\Support\Push;

/**
 * Заглушка: ничего не отправляет.
 *
 * Стоит по умолчанию в тестах и включается на проде через
 * `PUSH_DRIVER=null`, если пуши понадобится быстро выключить, не выкатывая код.
 */
class NullPushSender implements PushSender
{
    public function provider(): string
    {
        return 'null';
    }

    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        return 0;
    }
}
