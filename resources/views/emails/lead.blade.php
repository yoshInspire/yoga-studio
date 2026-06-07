<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #2f3a2a; line-height: 1.6;">
  <h2 style="color: #5a6b46;">Новая заявка с сайта студии</h2>
  <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
    <tr>
      <td style="font-weight: bold; vertical-align: top;">Имя:</td>
      <td>{{ $leadName }}</td>
    </tr>
    <tr>
      <td style="font-weight: bold; vertical-align: top;">Телефон:</td>
      <td>{{ $leadPhone }}</td>
    </tr>
    @if ($leadMessage)
      <tr>
        <td style="font-weight: bold; vertical-align: top;">Комментарий:</td>
        <td>{{ $leadMessage }}</td>
      </tr>
    @endif
    <tr>
      <td style="font-weight: bold; vertical-align: top;">Получено:</td>
      <td>{{ now()->translatedFormat('d F Y, H:i') }}</td>
    </tr>
  </table>
  <p style="color: #8a8f80; font-size: 13px; margin-top: 18px;">
    Письмо отправлено автоматически с формы «Оставить заявку» на сайте ekoyoga-ik.ru
  </p>
</body>
</html>
