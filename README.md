# Студия йоги Ирины Коленцевой

Laravel-приложение: публичный сайт, личный кабинет, админка Filament.

## Требования

- PHP 8.2+
- Composer
- MySQL

## Локальный запуск

```powershell
cd C:\work\avito_programming\1\yoga-studio
copy .env.example .env
C:\php\php.exe artisan key:generate
C:\php\php.exe artisan serve
```

Откройте: http://127.0.0.1:8000

## Структура

- `resources/views/home.blade.php` — главная страница
- `resources/views/layouts/site.blade.php` — общий шаблон
- `config/directions.php` — **16 направлений** (названия и тексты клиента, фото — заглушки)
- `public/css/site.css`, `public/js/site.js` — стили и скрипты лендинга
- `docs/PROJECT_REQUIREMENTS.md` — единые требования (в родительской папке)

## Маршруты

| URL | Описание |
|-----|----------|
| `/` | Главная |
| `/directions` | Все 16 направлений |
| `/schedule` | Расписание и запись |
| `/news` | Новости |
| `/login` | Вход / регистрация |
| `/account` | Личный кабинет |
| `/admin` | Админка Filament |

## Контент

- **Логотип:** `public/images/logo-header-mark.png`, `logo-footer.png`, favicon
- **Направления:** редактировать в `config/directions.php`; фото заменить в полях `img` / `gallery`

## Деплой

См. `deploy/DEPLOY_GUIDE.md`. На сервере: `git pull`, `php artisan migrate --force`, `php artisan view:cache`.
