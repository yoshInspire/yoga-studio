# Студия йоги Ирины Коленцевой

Laravel-приложение: публичный сайт, личный кабинет, админка Filament.

**Прод:** https://ekoyoga-ik.ru · репозиторий `yoshInspire/yoga-studio`, ветка `main`  
**Текущий деплой:** коммит `e04e578` (июнь 2026)

Полный статус проекта и чек-лист «что осталось» — в [`../docs/PROJECT_REQUIREMENTS.md`](../docs/PROJECT_REQUIREMENTS.md) (разд. **0** и **20**).

---

## Требования

- PHP 8.2+
- Composer
- MySQL (на проде); локально тесты — SQLite, если установлен `pdo_sqlite`

## Локальный запуск

```powershell
cd C:\work\avito_programming\1\yoga-studio
copy .env.example .env
C:\php\php.exe artisan key:generate
C:\php\php.exe artisan serve
```

Откройте: http://127.0.0.1:8000

Для писем локально: `MAIL_MAILER=log` или настройте SMTP в `.env`.

---

## Структура

- `resources/views/pages/` — страницы сайта (`account`, `login`, `schedule`, …)
- `resources/views/layouts/site.blade.php` — общий шаблон
- `config/studio.php` — бизнес-настройки (лимиты записи, email заявок, TTL кодов, имя отправителя писем)
- `config/directions.php` — **16 направлений**
- `config/studio-photos.php` — фото студии для hero, «О студии», галереи и CTA
- `public/css/site.css`, `public/js/site.js` — стили и скрипты публичного сайта
- `app/Services/ProfileEmailVerificationService.php` — коды для смены email в ЛК
- `app/Services/RegistrationEmailVerificationService.php` — коды при регистрации без Telegram
- `tests/Feature/ProfileUpdateTest.php`, `RegistrationEmailVerificationTest.php`

---

## Маршруты

| URL | Описание |
|-----|----------|
| `/` | Главная |
| `/directions` | Все 16 направлений |
| `/schedule` | Расписание и запись |
| `/news` | Новости |
| `/login` | Вход / регистрация / подтверждение email |
| `/account` | Личный кабинет (роль `client`) |
| `/purchase` | Покупка абонемента онлайн (ЮKassa) |
| `/trainer` | Кабинет тренера |
| `/admin` | Админка Filament |
| `/admin/payments` | История онлайн-оплат |

### Личный кабинет — профиль (auth + `role:client`)

| Метод | URL | Действие |
|-------|-----|----------|
| `PUT` | `/account/profile` | Сохранить данные профиля |
| `POST` | `/account/profile/email/send-code` | Отправить код на новый email (throttle 3/мин) |
| `POST` | `/account/profile/email/verify` | Подтвердить код (throttle 12/мин) |

### Регистрация — подтверждение email (guest)

| Метод | URL | Действие |
|-------|-----|----------|
| `POST` | `/register` | Старт регистрации → письмо с кодом |
| `POST` | `/register/verify` | Ввод кода → создание аккаунта |
| `POST` | `/register/resend` | Повторная отправка кода |
| `POST` | `/register/cancel` | Отмена регистрации |

---

## Почта (SMTP)

Используется для:

- форма «Оставить заявку» на главной (`POST /lead` → `STUDIO_LEAD_EMAIL`);
- коды подтверждения email при регистрации (без Telegram);
- коды при **добавлении или смене email** в личном кабинете.

**Прод:** Yandex SMTP, ящик `ecoyoga-ik@yandex.ru`. В `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.yandex.ru
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=ecoyoga-ik@yandex.ru
MAIL_PASSWORD=...          # пароль приложения Яндекса
MAIL_FROM_ADDRESS=ecoyoga-ik@yandex.ru
MAIL_FROM_NAME="ЭКО YOGA"
MAIL_TIMEOUT=10
STUDIO_LEAD_EMAIL=...        # кому заявки с сайта
```

Имя отправителя по умолчанию: `config/studio.php` → `mail_from_name` (не `APP_NAME`).  
Деплой прописывает `MAIL_FROM_NAME` и `MAIL_FROM_ADDRESS` на сервере.

**Важно на VPS:** исходящий SMTP (465/587) и PTR для IP — без этого письма не уходят или сайт «висит» на отправке (см. `MAIL_TIMEOUT`).

---

## Регистрация и email

| Сценарий | Поведение |
|----------|-----------|
| Регистрация **без** Telegram | Email **обязателен** → после формы вкладка «код из письма» на `/login` → аккаунт с `email_verified_at` |
| Регистрация **через** Telegram | Email необязателен, без кода, аккаунт создаётся сразу |

Ключевые файлы: `RegisterController`, `RegistrationEmailVerificationService`, `RegistrationVerificationMail`, шаблон `emails/registration-verification.blade.php`.

---

## Личный кабинет — профиль

На `/account` → «Профиль»:

- **Просмотр** — поля как при регистрации + Telegram в одной строке.
- **Редактирование** — кнопка «Редактировать профиль» **заменяет** карточку просмотра (не показываются вместе).
- Редактируются: имя, фамилия, отчество, дата рождения, телефон, email.
- **Email:** если адрес **новый или изменён** — в форме «Отправить код» → ввод 6 цифр → «Подтвердить»; **Сохранить** активно только после подтверждения. Если email не меняли — можно сохранить другие поля без кода.
- **Telegram привязан:** ник и компактная кнопка «Отвязать» в строке поля (без большого блока). Не привязан — виджет входа Telegram (medium) в той же строке.

Ключевые файлы: `ProfileController`, `ProfileEmailVerificationService`, `UpdateProfileRequest`, `account.blade.php`, логика в `site.js`.

---

## Вход через Telegram

- Бот: [@ekoyogabot](https://t.me/ekoyogabot)
- На `/login` — «Войти через Telegram»; новый пользователь → регистрация с предзаполнённым аккаунтом
- В `/account` → «Профиль» — привязка / отвязка (компактно в поле Telegram)
- `.env`: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`
- В BotFather: домен `ekoyoga-ik.ru` (`/setdomain`)

---

## Онлайн-оплата (ЮKassa)

- Каталог: `config/purchases.php`
- Настройки: `config/yookassa.php` (`YOOKASSA_*` в `.env`)
- Webhook: `POST /payments/webhook` → `https://ekoyoga-ik.ru/payments/webhook`
- Подробности: `../docs/PROJECT_REQUIREMENTS.md` → разд. **0.6**

---

## Контент

- **Логотип:** `public/images/logo-header-mark.png`, `logo-footer.png`, favicon
- **Направления:** `config/directions.php`; фото — `public/images/directions/{slug}/`
- **Фото студии:** `public/images/studio/*.webp`, `config/studio-photos.php`

## Шапка сайта

- Иконка календаря → `/schedule`
- Иконка профиля → `/login` (гость), `/account` (клиент), `/trainer` (тренер)
- Для **admin** иконка ЛК на публичном сайте скрыта
- Логика: `User::publicCabinetLink()` в `app/Models/User.php`

---

## Деплой

См. `deploy/DEPLOY_GUIDE.md`. На сервере после `git pull`: миграции, `config:cache`, `view:cache`.

**Автодеплой с Windows:**

```powershell
python deploy/update_remote.py
```

Скрипт: `git pull`, `composer install`, сиды админа/тренера, кэш, `MAIL_FROM_*`, перезапуск php-fpm.

---

## Что осталось (кратко)

| Задача | Статус |
|--------|--------|
| PDF оферты от клиента → `/admin/offer` | ⏳ |
| Фото тренеров (не stock) | ⏳ |
| Первые новости в `/admin/news` | ⏳ |
| ЮKassa: webhook в кабинете, тестовый платёж | ⏳ |
| Финальная приёмка с администратором | ⏳ |
| Безопасность: смена пароля root, SSH-ключи | ⏳ |
| SMS / email-напоминания о занятиях | вне сметы |
| Редактирование профиля в ЛК | ✅ |
| SMTP + коды email (регистрация и профиль) | ✅ |
| Форма заявки на email | ✅ (нужен рабочий SMTP на проде) |

Полный чек-лист — [`../docs/PROJECT_REQUIREMENTS.md`](../docs/PROJECT_REQUIREMENTS.md).

## Тесты

```powershell
php artisan test
```

Feature-тесты: `ProfileUpdateTest`, `RegistrationEmailVerificationTest`, `PaymentFlowTest`, …
