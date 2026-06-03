# Деплой на VPS (ekoyoga-ik.ru)

Доступ к серверу — в файле `deploy/SERVER.local.txt` (только на вашем ПК, не в Git).

## Этап 1. DNS (в панели REG.RU, где куплен домен)

| Тип | Имя | Значение |
|-----|-----|----------|
| A | @ | 77.91.93.110 |
| A | www | 77.91.93.110 |

Подождать 15–60 минут (иногда до 24 ч).

---

## Этап 2. Подключение по SSH

PowerShell:

```powershell
ssh root@77.91.93.110
```

Пароль — из `SERVER.local.txt`. При первом входе подтвердите fingerprint (`yes`).

Сразу смените пароль root:

```bash
passwd
```

---

## Этап 3. Установка стека на Ubuntu (на сервере)

```bash
apt update && apt upgrade -y
apt install -y nginx mysql-server git unzip curl software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

MySQL — создать БД и пользователя (подставьте свой пароль БД):

```bash
mysql -e "CREATE DATABASE yoga_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'yoga'@'localhost' IDENTIFIED BY 'ВАШ_ПАРОЛЬ_БД';"
mysql -e "GRANT ALL PRIVILEGES ON yoga_studio.* TO 'yoga'@'localhost'; FLUSH PRIVILEGES;"
```

Запишите пароль БД в `SERVER.local.txt`.

---

## Этап 4. Загрузка проекта

**Вариант A — Git** (если репозиторий на GitHub/GitLab):

```bash
cd /var/www
git clone <URL_ВАШЕГО_РЕПО> yoga-studio
cd yoga-studio
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

**Вариант B — с вашего ПК** (если Git на сервере не настроен):

На Windows в папке проекта (без `vendor`, без `.env`):

```powershell
cd C:\work\avito_programming\1\yoga-studio
scp -r app bootstrap config database public resources routes artisan composer.json composer.lock root@77.91.93.110:/var/www/yoga-studio/
```

На сервере:

```bash
mkdir -p /var/www/yoga-studio
cd /var/www/yoga-studio
composer install --no-dev --optimize-autoloader
```

Права:

```bash
chown -R www-data:www-data /var/www/yoga-studio
chmod -R 775 /var/www/yoga-studio/storage /var/www/yoga-studio/bootstrap/cache
```

---

## Этап 5. Файл .env на сервере

```bash
nano /var/www/yoga-studio/.env
```

Минимум:

```env
APP_NAME="Студия йоги Ирины Коленцевой"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ekoyoga-ik.ru

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=yoga_studio
DB_USERNAME=yoga
DB_PASSWORD=ВАШ_ПАРОЛЬ_БД
```

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Этап 6. Nginx

```bash
nano /etc/nginx/sites-available/ekoyoga-ik.ru
```

```nginx
server {
    listen 80;
    server_name ekoyoga-ik.ru www.ekoyoga-ik.ru;
    root /var/www/yoga-studio/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/ekoyoga-ik.ru /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## Этап 7. SSL (HTTPS)

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d ekoyoga-ik.ru -d www.ekoyoga-ik.ru
```

---

## Этап 8. Cron (для будущего расписания Laravel)

```bash
crontab -e
```

Добавить строку:

```
* * * * * cd /var/www/yoga-studio && php artisan schedule:run >> /dev/null 2>&1
```

---

## Проверка

- http://77.91.93.110 — до DNS может открыться сайт по IP
- https://ekoyoga-ik.ru — после DNS и certbot

---

## Обновление сайта после изменений

```bash
cd /var/www/yoga-studio
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
