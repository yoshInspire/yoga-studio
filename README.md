# Студия йоги

Laravel-приложение: публичный сайт, личный кабинет клиента, кабинет тренера,
админка на Filament.

## Требования

- PHP 8.2+
- Composer
- MySQL (в бою); для тестов подойдёт SQLite при наличии `pdo_sqlite`

## Запуск

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Приложение поднимется на http://127.0.0.1:8000.

Все внешние сервисы (почта, платежи, Telegram) настраиваются переменными
окружения — список и значения по умолчанию смотрите в `.env.example` и в
файлах `config/`.

## Тесты

```bash
php artisan test
```

## Структура

- `app/Services/` — бизнес-логика (запись, абонементы, платежи, коды подтверждения)
- `app/Http/Controllers/` — контроллеры сайта, `app/Http/Controllers/Api/` — REST API
  для мобильного приложения (префикс `/api/v1`, авторизация Sanctum)
- `app/Filament/` — админка
- `config/studio.php`, `config/directions.php`, `config/purchases.php` — бизнес-настройки
- `resources/views/pages/` — страницы сайта, `layouts/site.blade.php` — общий шаблон
- `tests/Feature/` — функциональные тесты

## Выкладка

Скрипты выкладки, доступы и инструкции по серверу в репозитории не хранятся.
