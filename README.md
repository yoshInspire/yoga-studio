# Студия йоги Ирины Коленцевой

Laravel-приложение: публичный сайт, личный кабинет (в разработке), админка (в разработке).

## Требования

- PHP 8.2+
- Composer
- MySQL (позже) — для главной страницы достаточно SQLite или без БД

## Локальный запуск

```powershell
cd C:\work\avito_programming\1\yoga-studio
copy .env.example .env
C:\php\php.exe artisan key:generate
C:\php\php.exe artisan serve
```

Откройте: http://127.0.0.1:8000

## Структура (текущий этап)

- `resources/views/home.blade.php` — главная страница
- `resources/views/layouts/site.blade.php` — общий шаблон
- `public/css/site.css`, `public/js/site.js` — стили и скрипты лендинга
- `docs/PROJECT_REQUIREMENTS.md` — единые требования (в родительской папке)

## Маршруты

| URL | Описание |
|-----|----------|
| `/` | Главная |
| `/schedule` | Расписание (заглушка) |
| `/directions` | Все направления (заглушка) |
| `/login` | Личный кабинет (заглушка) |

## Деплой

После настройки сервера и домена: `composer install --no-dev`, `php artisan config:cache`, веб-корень — `public/`.
