<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Код подтверждения</title>
</head>
<body style="font-family: sans-serif; color: #2f3a2e; line-height: 1.5;">
  <p>Здравствуйте!</p>
  @if ($context === 'profile')
    <p>Вы подтверждаете email в личном кабинете на сайте студии йоги Ирины Коленцевой.</p>
  @elseif ($context === 'password-reset')
    <p>Вы запросили сброс пароля на сайте студии йоги Ирины Коленцевой.</p>
  @else
    <p>Вы регистрируете аккаунт на сайте студии йоги Ирины Коленцевой.</p>
  @endif
  <p>Код подтверждения:</p>
  <p style="font-size: 28px; font-weight: 700; letter-spacing: 4px;">{{ $code }}</p>
  <p>Код действует {{ $ttlMinutes }} минут.
    @if ($context === 'password-reset')
      Если вы не запрашивали сброс пароля — просто проигнорируйте это письмо.
    @else
      Если вы не запрашивали это письмо — просто проигнорируйте его.
    @endif
  </p>
  <p style="color: #6b7280; font-size: 14px;">ЭКО YOGA · ekoyoga-ik.ru</p>
</body>
</html>
