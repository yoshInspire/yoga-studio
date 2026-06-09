@if ($telegramEnabled ?? false)
  <div class="auth__telegram">
    <script
      async
      src="https://telegram.org/js/telegram-widget.js?22"
      data-telegram-login="{{ $telegramBotUsername }}"
      data-size="large"
      data-radius="12"
      data-auth-url="{{ $telegramAuthUrl }}"
      data-request-access="write"
    ></script>
  </div>
@endif
