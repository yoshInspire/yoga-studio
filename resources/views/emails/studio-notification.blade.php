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
</body>
</html>
