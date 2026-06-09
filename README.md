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
- `resources/views/partials/header-icons.blade.php` — иконки «Расписание» и «Личный кабинет» в шапке
- `config/directions.php` — **16 направлений** (названия, тексты, фото)
- `config/studio-photos.php` — фото студии для hero, «О студии», галереи и CTA
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
| `/account` | Личный кабинет (роль `client`) |
| `/purchase` | Покупка абонемента онлайн (ЮKassa) |
| `/trainer` | Кабинет тренера |
| `/admin` | Админка Filament (не в публичной навигации) |
| `/admin/payments` | История онлайн-оплат |

## Вход через Telegram

- Бот: [@ekoyogabot](https://t.me/ekoyogabot)
- На `/login` — кнопка «Войти через Telegram»; новый пользователь попадает на регистрацию с предзаполненным аккаунтом
- В `/account` → «Профиль» — привязка или отвязка Telegram
- Настройки: `.env` → `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`
- В BotFather для бота должен быть указан домен `ekoyoga-ik.ru` (`/setdomain`)

## Онлайн-оплата (ЮKassa)

- Каталог тарифов: `config/purchases.php`
- Настройки ЮKassa и чеков: `config/yookassa.php` (`.env`: `YOOKASSA_*`)
- Webhook: `POST /payments/webhook` → `https://ekoyoga-ik.ru/payments/webhook`
- Подробный статус: `docs/PROJECT_REQUIREMENTS.md` → разд. **0.6**

## Контент

- **Логотип:** `public/images/logo-header-mark.png`, `logo-footer.png`, favicon
- **Направления:** `config/directions.php`; фото — `public/images/directions/{slug}/`
- **Фото студии:** `public/images/studio/*.webp`, настройка — `config/studio-photos.php`
  - hero — `hero-hall.webp`
  - блок «О студии» — `about-lounge.webp`
  - галерея «Наше пространство» — 5 кадров (без подписей на фото)
  - CTA — `cta-practice.webp`

## Шапка сайта

- Иконка календаря → `/schedule`
- Иконка профиля → `/login` (гость), `/account` (клиент), `/trainer` (тренер)
- Для роли **admin** иконка ЛК на публичном сайте **скрыта**; `/admin` не показывается в шапке
- Логика: `User::publicCabinetLink()` в `app/Models/User.php`

## Деплой

См. `deploy/DEPLOY_GUIDE.md`. На сервере: `git pull`, `php artisan migrate --force`, `php artisan view:cache`.

Автодеплой с Windows: `python deploy/update_remote.py`

**Текущий прод:** коммит `e66ede5` (09.06.2026)
