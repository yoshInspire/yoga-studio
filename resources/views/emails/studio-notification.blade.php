<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>{{ $heading }}</title>
</head>
<body style="font-family: sans-serif; color: #2f3a2e; line-height: 1.5;">
  <h2 style="color: #2f3a2e; font-size: 20px;">{{ $heading }}</h2>
  @foreach ($lines as $line)
    <p style="margin: 8px 0;">{!! nl2br(e($line)) !!}</p>
  @endforeach
  @if (! empty($footnote))
    <p style="color: #6b7280; font-size: 14px; margin-top: 16px;">{{ $footnote }}</p>
  @endif
  <p style="color: #6b7280; font-size: 14px; margin-top: 20px;">ЭКО YOGA · <a href="https://ekoyoga-ik.ru" style="color: #6b7280;">ekoyoga-ik.ru</a></p>
  @if (! empty($unsubscribeUrl))
    <p style="color: #9ca3af; font-size: 12px; margin-top: 12px; line-height: 1.6;">
      Вы получаете это письмо как клиент студии.
      <a href="{{ $unsubscribeUrl }}" style="color: #9ca3af;">Отписаться от рассылок</a>.
      Письма о ваших записях, отменах занятий и абонементе продолжат приходить.
    </p>
  @endif
</body>
</html>
